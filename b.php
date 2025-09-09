<?php
// bot.php - Telegram Bot (Webhook) كامل مع لوحة مشرف، إدارة أرقام، اختيار سيرفر/تطبيق/دولة، وطابور مراقبة (watches.json)
// وضع هذا الملف في سيرفر يدعم HTTPS وعرّف Webhook ليشير إلى هذا الملف.
// تأكد من صلاحيات الكتابة للمجلد لتمكين إنشاء وتعديل ملفات JSON/TXT.

// ---------------------- إعدادات ----------------------
define('BOT_TOKEN', '8439996525:AAEOJk2YOIH8lX8mMajrp2yE02SPDJ3JWUU'); // ضع توكن بوتك هنا
define('API_URL', 'http://fi8.bot-hosting.net:20829/api/latest?key=SECRET123'); // يستخدمه watcher.php
define('ADMIN_ID', 640391482);

define('NUMBERS_FILE', __DIR__ . '/numbers.json');
define('COUNTRIES_FILE', __DIR__ . '/countries.txt');
define('SERVERS_FILE', __DIR__ . '/servers.txt');
define('SESSIONS_FILE', __DIR__ . '/sessions.json');
define('WATCHES_FILE', __DIR__ . '/watches.json');

define('WATCH_TIME', 120); // زمن المراقبة بالثواني (افتراضي 120)
define('POLL_INTERVAL', 2); // فقط توثيق هنا؛ يستخدمه watcher.php

// ---------------------- دوال Telegram ----------------------
function apiRequest($method, $params = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($params),
            'timeout' => 10
        ]
    ];
    $context  = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = 'Markdown') {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $parse_mode,
        'disable_web_page_preview' => true
    ];
    if ($reply_markup) $params['reply_markup'] = json_encode($reply_markup, JSON_UNESCAPED_UNICODE);
    return apiRequest('sendMessage', $params);
}

function answerCallback($callback_id, $text = '', $show_alert = false) {
    return apiRequest('answerCallbackQuery', [
        'callback_query_id' => $callback_id,
        'text' => $text,
        'show_alert' => $show_alert ? 'true' : 'false'
    ]);
}

// ---------------------- تخزين ملفات JSON/TXT ----------------------
function load_json_file($file, $default) {
    if (!file_exists($file)) return $default;
    $c = @file_get_contents($file);
    if ($c === false) return $default;
    $j = @json_decode($c, true);
    return is_array($j) ? $j : $default;
}
function save_json_file_with_lock($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return;
    }
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

// الأرقام والدول والسيرفرات
function load_numbers() {
    $default = ["whatsapp" => [], "facebook" => [], "telegram" => [], "other" => []];
    return load_json_file(NUMBERS_FILE, $default);
}
function save_numbers($data) { save_json_file_with_lock(NUMBERS_FILE, $data); }

function load_countries() {
    if (!file_exists(COUNTRIES_FILE)) {
        return ["🇸🇦 السعودية", "🇪🇬 مصر", "🇦🇪 الإمارات", "🇰🇼 الكويت", "🇮🇶 العراق", "🇯🇴 الأردن", "🇸🇾 سوريا"];
    }
    $lines = array_map('trim', file(COUNTRIES_FILE));
    $lines = array_filter($lines, fn($l) => $l !== '');
    return array_values($lines);
}
function save_countries($countries) { file_put_contents(COUNTRIES_FILE, implode("\n", $countries)); }

function load_servers() {
    if (!file_exists(SERVERS_FILE)) {
        return ["🚀 سيرفر سريع", "⚡ سيرفر مميز", "🔒 سيرفر آمن"];
    }
    $lines = array_map('trim', file(SERVERS_FILE));
    $lines = array_filter($lines, fn($l) => $l !== '');
    return array_values($lines);
}
function save_servers($servers) { file_put_contents(SERVERS_FILE, implode("\n", $servers)); }

// جلسات بسيطة لحفظ الحالة (sessions.json)
function load_sessions() { return load_json_file(SESSIONS_FILE, []); }
function save_sessions($s) { save_json_file_with_lock(SESSIONS_FILE, $s); }

// طابور المراقبات
function load_watches() { return load_json_file(WATCHES_FILE, []); }
function save_watches($watches) { save_json_file_with_lock(WATCHES_FILE, $watches); }
function add_watch_request($user_id, $last_digits, $duration = WATCH_TIME) {
    $watches = load_watches();
    $watches[] = [
        'user_id' => intval($user_id),
        'last' => strval($last_digits),
        'expires_at' => time() + intval($duration),
        'requested_at' => time()
    ];
    save_watches($watches);
    return true;
}

// ---------------------- أدوات مساعدة ----------------------
// استخراج آخر N أرقام (افتراضي 3)
function extract_last_digits($full_number, $count = 3) {
    $digits = preg_replace('/\D+/', '', $full_number);
    if (strlen($digits) < $count) return null;
    return substr($digits, -$count);
}

// إضافة رقم إلى التطبيق المحدد (تخزن last3)
function add_number_to_app($app, $full_number, $country = "غير محدد") {
    $data = load_numbers();
    if (!array_key_exists($app, $data)) $data[$app] = [];
    $last = extract_last_digits($full_number, 3);
    if (!$last) return false;
    $number_data = [
        "full_number" => $full_number,
        "last3" => $last,
        "country" => $country,
        "date_added" => date("Y-m-d H:i:s")
    ];
    $data[$app][] = $number_data;
    save_numbers($data);
    return true;
}

// تحويل مفاتيح التطبيقات المختصرة
function app_key_map($short) {
    $map = [
        "wa" => "whatsapp",
        "fb" => "facebook",
        "tg" => "telegram",
        "gm" => "other",
        "tw" => "other",
        "ig" => "other",
        "sn" => "other",
        "ga" => "other"
    ];
    return $map[$short] ?? $short;
}
function app_text_for($short) {
    $map = [
        "wa" => "📞 واتساب",
        "fb" => "👥 فيسبوك",
        "tg" => "📲 تيليجرام",
        "gm" => "📧 جيميل",
        "tw" => "🐦 تويتر",
        "ig" => "📸 إنستغرام",
        "sn" => "🔷 سناب شات",
        "ga" => "🎮 ألعاب"
    ];
    return $map[$short] ?? $short;
}

// ---------------------- واجهات المستخدم ----------------------
function admin_panel($chat_id) {
    $numbers = load_numbers();
    $countries = load_countries();
    $total_numbers = array_sum(array_map('count', $numbers));
    $stats = "👨‍💻 *لوحة تحكم المشرف*\n\n";
    $stats .= "📊 *الإحصائيات:*\n";
    $stats .= "• 📞 واتساب: " . count($numbers['whatsapp']) . " رقم\n";
    $stats .= "• 👥 فيسبوك: " . count($numbers['facebook']) . " رقم\n";
    $stats .= "• 📲 تيليجرام: " . count($numbers['telegram']) . " رقم\n";
    $stats .= "• 🔄 أخرى: " . count($numbers['other']) . " رقم\n";
    $stats .= "• 🌍 الدول: " . count($countries) . " دولة\n";
    $stats .= "• 🧮 المجموع: " . $total_numbers . " رقم\n";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text'=>"📞 إضافة رقم", 'callback_data'=>"admin_add_number"],
                ['text'=>"📤 إضافة قائمة", 'callback_data'=>"admin_add_list"]
            ],
            [
                ['text'=>"🌍 إضافة دولة", 'callback_data'=>"admin_add_country"],
                ['text'=>"📊 عرض الأرقام", 'callback_data'=>"admin_view_numbers"]
            ],
            [
                ['text'=>"🗑️ حذف أرقام", 'callback_data'=>"admin_delete_numbers"],
                ['text'=>"🔄 تحديث", 'callback_data'=>"admin_refresh"]
            ]
        ]
    ];
    sendMessage($chat_id, $stats, $keyboard);
}

function server_selection_menu($chat_id) {
    $servers = load_servers();
    $keyboard = [ 'inline_keyboard' => [] ];
    foreach ($servers as $idx => $srv) {
        $keyboard['inline_keyboard'][] = [ ['text'=>$srv, 'callback_data'=>"srv_$idx"] ];
    }
    $keyboard['inline_keyboard'][] = [
        ['text'=>"📊 الإحصائيات", 'callback_data'=>"stats"],
        ['text'=>"⚙️ الإعدادات", 'callback_data'=>"settings"],
        ['text'=>"ℹ️ المساعدة", 'callback_data'=>"help"]
    ];
    $msg = "🌟 *مرحباً بك في بوت الحصول على الأرقام!*\n\n"
        ."🌐 *يرجى اختيار السيرفر المناسب للبدء:*\n\n"
        ."• 🚀 سيرفر سريع: أسرع استجابة\n"
        ."• ⚡ سيرفر مميز: جودة عالية\n"
        ."• 🔒 سيرفر آمن: حماية متقدمة";
    sendMessage($chat_id, $msg, $keyboard);
}

function app_selection_menu($chat_id, $server_idx) {
    $apps = [
        ["📞 واتساب", "wa"],
        ["👥 فيسبوك", "fb"],
        ["📲 تيليجرام", "tg"],
        ["📧 جيميل", "gm"],
        ["🐦 تويتر", "tw"],
        ["📸 إنستغرام", "ig"],
        ["🔷 سناب شات", "sn"],
        ["🎮 ألعاب", "ga"]
    ];
    $keyboard = ['inline_keyboard' => []];
    $row = [];
    foreach ($apps as $app) {
        $row[] = ['text' => $app[0], 'callback_data' => "app_{$app[1]}_$server_idx"];
        if (count($row) == 2) {
            $keyboard['inline_keyboard'][] = $row;
            $row = [];
        }
    }
    if (!empty($row)) $keyboard['inline_keyboard'][] = $row;
    $keyboard['inline_keyboard'][] = [['text'=>"🔙 رجوع", 'callback_data'=>"back_srv"]];
    $srv_list = load_servers();
    $server_name = $srv_list[$server_idx] ?? "السيرفر";
    $msg = "🌐 السيرفر: *$server_name*\n📱 *اختر التطبيق المطلوب:*";
    sendMessage($chat_id, $msg, $keyboard);
}

function country_selection_menu($chat_id, $app_short, $server_idx) {
    $countries = load_countries();
    $keyboard = ['inline_keyboard' => []];
    $row = [];
    foreach ($countries as $idx => $country) {
        $row[] = ['text' => $country, 'callback_data' => "cty_$idx_{$app_short}_$server_idx"];
        if (count($row) == 2) {
            $keyboard['inline_keyboard'][] = $row;
            $row = [];
        }
    }
    if (!empty($row)) $keyboard['inline_keyboard'][] = $row;
    $keyboard['inline_keyboard'][] = [['text'=>"🌍 جميع الدول", 'callback_data'=>"cty_all_{$app_short}_$server_idx"]];
    $keyboard['inline_keyboard'][] = [['text'=>"🔙 رجوع", 'callback_data'=>"back_app_$server_idx"]];
    $srv_list = load_servers();
    $server_name = $srv_list[$server_idx] ?? "السيرفر";
    $app_text = app_text_for($app_short);
    $msg = "🌐 السيرفر: *$server_name*\n📱 التطبيق: *$app_text*\n🌍 *اختر الدولة:*";
    sendMessage($chat_id, $msg, $keyboard);
}

function get_number_based_on_selection($chat_id, $country_idx_or_all, $app_short, $server_idx) {
    $data = load_numbers();
    $app_key = app_key_map($app_short);

    $suitable = [];
    if ($country_idx_or_all === 'all') {
        if (!empty($data[$app_key])) {
            foreach ($data[$app_key] as $num) $suitable[] = $num;
        }
    } else {
        $countries = load_countries();
        $country = $countries[intval($country_idx_or_all)] ?? null;
        if ($country !== null) {
            foreach ($data[$app_key] as $num) {
                if (($num['country'] ?? 'غير محدد') === $country) $suitable[] = $num;
            }
        }
    }

    if (empty($suitable)) {
        if (!empty($data[$app_key])) {
            $suitable = $data[$app_key];
        }
    }

    if (empty($suitable)) {
        sendMessage($chat_id, "❌ لا توجد أرقام متاحة حالياً لهذا التطبيق/الدولة. حاول لاحقاً.");
        return;
    }

    $choice = $suitable[array_rand($suitable)];
    $full_number = $choice['full_number'];
    $last3 = $choice['last3'] ?? extract_last_digits($full_number, 3);
    $number_country = $choice['country'] ?? 'غير محدد';

    // احذف الرقم من الملف حتى لا يعاد استخدامه
    $new_data = $data;
    $new_data[$app_key] = array_filter($new_data[$app_key], function($n) use ($full_number) {
        return ($n['full_number'] ?? '') !== $full_number;
    });
    $new_data[$app_key] = array_values($new_data[$app_key]);
    save_numbers($new_data);

    $app_text = app_text_for($app_short);
    $srv_list = load_servers();
    $server_name = $srv_list[$server_idx] ?? "السيرفر";

    $message_text = "✅ *تم تخصيص الرقم لك!*\n\n";
    $message_text .= "📞 *الرقم:* `" . $full_number . "`\n";
    $message_text .= "🌍 *الدولة:* " . $number_country . "\n";
    $message_text .= "📱 *التطبيق:* " . $app_text . "\n";
    $message_text .= "🌐 *السيرفر:* " . $server_name . "\n\n";
    $message_text .= "⏳ *لديك خيار طلب الكود أو تغيير الرقم.*\n";

    $wa_link = "https://wa.me/" . preg_replace('/\D+/', '', $full_number);
    $keyboard = ['inline_keyboard' => [
        [
            ['text' => "📞 تحقق عبر واتساب", 'url' => $wa_link],
            ['text' => "🔄 تغيير الرقم", 'callback_data' => "chg_{$server_idx}_{$app_short}_" . ($country_idx_or_all === 'all' ? 'all' : $country_idx_or_all)]
        ],
        [
            ['text' => "📩 طلب الكود", 'callback_data' => "req_$last3"]
        ],
        [
            ['text' => "🔙 العودة للسيرفرات", 'callback_data' => "back_srv"]
        ]
    ]];
    sendMessage($chat_id, $message_text, $keyboard);
}

// ---------------------- معالجة التحديثات (Webhook) ----------------------
$update = json_decode(file_get_contents('php://input'), true);
$sessions = load_sessions();

// معالجة الرسائل النصية
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = isset($message['text']) ? trim($message['text']) : '';

    // تعامل المشرف مع حالات إدخال
    if ($chat_id == ADMIN_ID) {
        $session = $sessions[(string)$chat_id] ?? null;

        // انتظار رقم منفرد
        if ($session && ($session['action'] ?? '') === 'awaiting_admin_add_number') {
            $full_number = $text;
            $last3 = extract_last_digits($full_number, 3);
            if (!$last3) {
                sendMessage($chat_id, "❌ الرقم غير صالح. الرجاء إرسال رقم يحتوي على 3 أرقام على الأقل.");
                exit;
            }
            $sessions[(string)$chat_id] = [
                'action' => 'admin_add_number_pending',
                'full_number' => $full_number,
                'last3' => $last3
            ];
            save_sessions($sessions);

            $keyboard = ['inline_keyboard' => [
                [
                    ['text'=>"📞 واتساب", 'callback_data'=>"admin_app_whatsapp"],
                    ['text'=>"👥 فيسبوك", 'callback_data'=>"admin_app_facebook"]
                ],
                [
                    ['text'=>"📲 تيليجرام", 'callback_data'=>"admin_app_telegram"],
                    ['text'=>"🔄 أخرى", 'callback_data'=>"admin_app_other"]
                ]
            ]];
            sendMessage($chat_id, "✅ تم استلام الرقم: `{$full_number}`\n\n📱 *اختر التطبيق لإضافة الرقم إليه:*", $keyboard);
            exit;
        }

        // انتظار قائمة أرقام
        if ($session && ($session['action'] ?? '') === 'awaiting_admin_add_list') {
            $numbers_text = $text;
            $lines = preg_split('/\r\n|\r|\n/', $numbers_text);
            $found = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                preg_match_all('/(\+?\d[\d\-\s\(\)]{2,}\d)/u', $line, $matches);
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $num) {
                        $clean = preg_replace('/\D+/', '', $num);
                        if (strlen($clean) >= 3) $found[] = $num;
                    }
                } else {
                    $clean = preg_replace('/\D+/', '', $line);
                    if (strlen($clean) >= 3) $found[] = $line;
                }
            }
            if (empty($found)) {
                sendMessage($chat_id, "❌ لم يتم العثور على أرقام صالحة في القائمة. أعد الإرسال أو اضغط 🔄 تحديث للعودة.");
                exit;
            }
            $sessions[(string)$chat_id] = [
                'action' => 'admin_add_list_pending',
                'numbers' => array_values(array_unique($found))
            ];
            save_sessions($sessions);
            $keyboard = ['inline_keyboard' => [
                [
                    ['text'=>"📞 واتساب", 'callback_data'=>"admin_list_app_whatsapp"],
                    ['text'=>"👥 فيسبوك", 'callback_data'=>"admin_list_app_facebook"]
                ],
                [
                    ['text'=>"📲 تيليجرام", 'callback_data'=>"admin_list_app_telegram"],
                    ['text'=>"🔄 أخرى", 'callback_data'=>"admin_list_app_other"]
                ]
            ]];
            sendMessage($chat_id, "✅ تم قراءة " . count($found) . " رقم من قائمتك.\n\n📱 *اختر التطبيق لإضافة هذه الأرقام إليه:*", $keyboard);
            exit;
        }

        // انتظار إضافة دولة
        if ($session && ($session['action'] ?? '') === 'awaiting_admin_add_country') {
            $country = $text;
            if (trim($country) === '') {
                sendMessage($chat_id, "❌ الرجاء كتابة اسم الدولة.");
                exit;
            }
            $countries = load_countries();
            if (in_array($country, $countries)) {
                sendMessage($chat_id, "❌ هذه الدولة موجودة بالفعل.");
            } else {
                $countries[] = $country;
                save_countries($countries);
                sendMessage($chat_id, "✅ تم إضافة الدولة: " . $country);
            }
            unset($sessions[(string)$chat_id]);
            save_sessions($sessions);
            admin_panel($chat_id);
            exit;
        }

        // أوامر نصية بسيطة
        if ($text === '/start' || $text === '🔄 تحديث') {
            admin_panel($chat_id);
            exit;
        }

        admin_panel($chat_id);
        exit;
    }

    // للمستخدمين العاديين
    if ($text === '/start') {
        server_selection_menu($chat_id);
        exit;
    }

    server_selection_menu($chat_id);
    exit;
}

// معالجة callback_query (أزرار Inline)
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $callback_id = $callback['id'];
    $from_id = $callback['from']['id'];
    $message = $callback['message'] ?? null;
    $chat_id = $message['chat']['id'] ?? $from_id;
    $data = $callback['data'] ?? '';

    // أجب Telegram فوراً
    answerCallback($callback_id, '');

    $sessions = load_sessions();

    // --- مشرف ---
    if ($chat_id == ADMIN_ID) {
        if ($data === 'admin_add_number') {
            $sessions[(string)$chat_id] = ['action' => 'awaiting_admin_add_number'];
            save_sessions($sessions);
            sendMessage($chat_id, "✍️ *أرسل الرقم الكامل الذي تريد إضافته:* (مثال: +963935550906)");
            exit;
        }
        if ($data === 'admin_add_list') {
            $sessions[(string)$chat_id] = ['action' => 'awaiting_admin_add_list'];
            save_sessions($sessions);
            sendMessage($chat_id, "📤 *أرسل قائمة الأرقام (كل رقم في سطر):*");
            exit;
        }
        if ($data === 'admin_add_country') {
            $sessions[(string)$chat_id] = ['action' => 'awaiting_admin_add_country'];
            save_sessions($sessions);
            sendMessage($chat_id, "🌍 *أرسل اسم الدولة التي تريد إضافتها:*");
            exit;
        }
        if ($data === 'admin_view_numbers') {
            $nums = load_numbers();
            $msg = "📋 *أرقام المخزن (عرض مختصر):*\n\n";
            foreach ($nums as $app => $arr) {
                $msg .= "• *$app*: " . count($arr) . " رقم\n";
            }
            sendMessage($chat_id, $msg);
            exit;
        }
        if ($data === 'admin_delete_numbers') {
            $empty = ["whatsapp" => [], "facebook" => [], "telegram" => [], "other" => []];
            save_numbers($empty);
            sendMessage($chat_id, "✅ *تم حذف جميع الأرقام.*");
            admin_panel($chat_id);
            exit;
        }
        if ($data === 'admin_refresh') {
            admin_panel($chat_id);
            exit;
        }

        // بعد استقبال رقم مفرد -> اختيار التطبيق
        if (strpos($data, 'admin_app_') === 0) {
            $app_key = substr($data, strlen('admin_app_'));
            $session = $sessions[(string)$chat_id] ?? null;
            if ($session && ($session['action'] ?? '') === 'admin_add_number_pending' && !empty($session['full_number'])) {
                $full = $session['full_number'];
                $ok = add_number_to_app($app_key, $full, "غير محدد");
                if ($ok) {
                    sendMessage($chat_id, "✅ *تم إضافة الرقم:* `{$full}` إلى *{$app_key}*");
                } else {
                    sendMessage($chat_id, "❌ فشل إضافة الرقم. تأكد من صحة الرقم.");
                }
                unset($sessions[(string)$chat_id]);
                save_sessions($sessions);
                admin_panel($chat_id);
            } else {
                sendMessage($chat_id, "❌ لا يوجد رقم معلق للإضافة. اضغط 'إضافة رقم' ثم أرسل الرقم.");
            }
            exit;
        }

        // بعد استقبال قائمة -> اختيار التطبيق واضافة الجميع
        if (strpos($data, 'admin_list_app_') === 0) {
            $app_key = substr($data, strlen('admin_list_app_'));
            $session = $sessions[(string)$chat_id] ?? null;
            if ($session && ($session['action'] ?? '') === 'admin_add_list_pending' && !empty($session['numbers'])) {
                $added = 0;
                foreach ($session['numbers'] as $num) {
                    if (add_number_to_app($app_key, $num, "غير محدد")) $added++;
                }
                sendMessage($chat_id, "✅ *تم إضافة {$added} رقم إلى {$app_key}*");
                unset($sessions[(string)$chat_id]);
                save_sessions($sessions);
                admin_panel($chat_id);
            } else {
                sendMessage($chat_id, "❌ لا توجد قائمة مرفوعة. اضغط 'إضافة قائمة' ثم أرسلها.");
            }
            exit;
        }
    } // نهاية بلوك المشرف

    // ---------------- مستخدمون ----------------
    // اختيار سيرفر
    if (strpos($data, 'srv_') === 0) {
        $parts = explode('_', $data);
        $server_idx = intval($parts[1] ?? 0);
        app_selection_menu($chat_id, $server_idx);
        exit;
    }

    // اختيار تطبيق
    if (strpos($data, 'app_') === 0) {
        $parts = explode('_', $data);
        if (count($parts) >= 3) {
            $app_short = $parts[1];
            $server_idx = intval($parts[2]);
            country_selection_menu($chat_id, $app_short, $server_idx);
        } else {
            sendMessage($chat_id, "خطأ في اختيار التطبيق. حاول مجدداً.");
        }
        exit;
    }

    // اختيار دولة
    if (strpos($data, 'cty_') === 0) {
        $parts = explode('_', $data);
        if (count($parts) >= 4) {
            $country_idx = intval($parts[1]);
            $app_short = $parts[2];
            $server_idx = intval($parts[3]);
            get_number_based_on_selection($chat_id, $country_idx, $app_short, $server_idx);
        } else {
            sendMessage($chat_id, "خطأ في اختيار الدولة. حاول مجدداً.");
        }
        exit;
    }

    // جميع الدول
    if (strpos($data, 'cty_all_') === 0) {
        $parts = explode('_', $data);
        if (count($parts) >= 4) {
            $app_short = $parts[2];
            $server_idx = intval($parts[3]);
            get_number_based_on_selection($chat_id, 'all', $app_short, $server_idx);
        } else {
            sendMessage($chat_id, "خطأ في اختيار جميع الدول. حاول مجدداً.");
        }
        exit;
    }

    // طلب مراقبة الكود - الآن يضيف إلى طابور watches.json
    if (strpos($data, 'req_') === 0) {
        $last3 = substr($data, 4);
        add_watch_request($chat_id, $last3, WATCH_TIME);
        answerCallback($callback_id, '✅ تم بدء المراقبة تلقائياً لمدة ' . (WATCH_TIME/60) . ' دقيقة.');
        sendMessage($chat_id, "⏳ جاري المراقبة التلقائية لآخر " . strlen($last3) . " أرقام: *{$last3}*\nستتلقى إشعاراً تلقائياً عند وصول الكود أو عند انتهاء الوقت.", null, 'Markdown');
        exit;
    }

    // تغيير الرقم (إعادة اختيار رقم)
    if (strpos($data, 'chg_') === 0) {
        $parts = explode('_', $data, 4);
        $server_idx = intval($parts[1] ?? 0);
        $app_short = $parts[2] ?? 'wa';
        $country_part = $parts[3] ?? 'all';
        if ($country_part === 'all') {
            get_number_based_on_selection($chat_id, 'all', $app_short, $server_idx);
        } else {
            get_number_based_on_selection($chat_id, intval($country_part), $app_short, $server_idx);
        }
        exit;
    }

    // أزرار العودة والإحصائيات والمساعدة
    if ($data === 'back_srv') {
        server_selection_menu($chat_id);
        exit;
    }
    if (strpos($data, 'back_app_') === 0) {
        // صيغة back_app_{server_idx}
        $parts = explode('_', $data);
        $server_idx = intval($parts[2] ?? 0);
        app_selection_menu($chat_id, $server_idx);
        exit;
    }
    if ($data === 'stats') {
        $numbers = load_numbers();
        $countries = load_countries();
        $servers = load_servers();
        $total = array_sum(array_map('count', $numbers));
        $msg = "📊 *إحصائيات البوت:*\n• إجمالي الأرقام: $total\n• الدول: " . count($countries) . "\n• السيرفرات: " . count($servers);
        sendMessage($chat_id, $msg);
        exit;
    }
    if ($data === 'settings') {
        sendMessage($chat_id, "⚙️ *الإعدادات:* (قابلة للتطوير)");
        exit;
    }
    if ($data === 'help') {
        sendMessage($chat_id, "ℹ️ *دليل الاستخدام:* اختر سيرفر ثم تطبيق ثم دولة. للمشرف: استخدم لوحة التحكم لإدارة الأرقام.");
        exit;
    }

    // رد افتراضي
    sendMessage($chat_id, "تم الضغط على زر: $data");
    exit;
}

// نهاية ملف bot.php
?>
