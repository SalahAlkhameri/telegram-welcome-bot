<?php
// Telegram Welcome Bot
// Admin ID and Token configuration
$bot_token = getenv('BOT_TOKEN') ?: '7954391684:AAEUOWnBMhLb1BbR7uBOsI_ETTLQ5v_9jBs';
$admin_id  = intval(getenv('ADMIN_ID') ?: 7505722949);

// API request helper
function apiRequest(string $method, array $params = []) {
    global $bot_token;
    $url = "https://api.telegram.org/bot{$bot_token}/" . $method;
    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($params),
        ],
    ];
    $context  = stream_context_create($options);
    return json_decode(file_get_contents($url, false, $context), true);
}

// Data helpers
define('DATA_DIR', __DIR__ . '/data');
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

function loadChannels(): array {
    $file = DATA_DIR . '/channels.json';
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function saveChannels(array $channels): void {
    $file = DATA_DIR . '/channels.json';
    file_put_contents($file, json_encode($channels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function loadState(int $user_id) {
    $file = DATA_DIR . "/state_{$user_id}.json";
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true);
}

function saveState(int $user_id, $state): void {
    $file = DATA_DIR . "/state_{$user_id}.json";
    if ($state === null) {
        if (file_exists($file)) unlink($file);
        return;
    }
    file_put_contents($file, json_encode($state));
}

function defaultKeyboard(): array {
    return [
        'keyboard' => [["/start"]],
        'resize_keyboard' => true
    ];
}

function sendWelcomeMessage(int $user_id, int $channel_id, string $channel_title): void {
    $channels = loadChannels();
    $settings = $channels[$channel_id] ?? [
        'title' => $channel_title,
        'welcome' => "👇 مرحبًا بك صديقي! تقدر تزور موقعنا:",
        'buttons' => [
            ['text' => '🔗 زور موقعنا', 'url' => 'https://his-lawyer.com'],
            ['text' => '🔥 رابط آخر', 'url' => 'https://example.com'],
        ],
    ];
    $channels[$channel_id] = $settings;
    saveChannels($channels);

    $keyboard = ['inline_keyboard' => array_map(
        fn($b) => [['text' => $b['text'], 'url' => $b['url']]],
        $settings['buttons']
    )];
    apiRequest('sendMessage', [
        'chat_id' => $user_id,
        'text' => $settings['welcome'],
        'reply_markup' => $keyboard,
    ]);
}

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) { echo 'No update'; exit; }

if (isset($update['chat_join_request'])) {
    $req = $update['chat_join_request'];
    $user_id = $req['from']['id'];
    $chat = $req['chat'];
    sendWelcomeMessage($user_id, $chat['id'], $chat['title'] ?? '');
    exit;
}

if (isset($update['message'])) {
    $message = $update['message'];
    $from_id = $message['from']['id'];
    $text = $message['text'] ?? '';

    if ($from_id != $admin_id) {
        exit; // ignore non-admin messages
    }

    $state = loadState($from_id);
    if ($state) {
        if ($state['action'] === 'edit_text') {
            $channels = loadChannels();
            $channels[$state['channel_id']]['welcome'] = $text;
            saveChannels($channels);
            apiRequest('sendMessage', [
                'chat_id' => $from_id,
                'text' => 'تم تحديث رسالة الترحيب.',
                'reply_markup' => defaultKeyboard()
            ]);
            saveState($from_id, null);
            exit;
        }
        if ($state['action'] === 'add_button') {
            if (!strpos($text, '|')) {
                apiRequest('sendMessage', [
                    'chat_id' => $from_id,
                    'text' => 'الرجاء إرسال النص والرابط بهذا الشكل: اسم الزر | الرابط',
                    'reply_markup' => defaultKeyboard()
                ]);
                exit;
            }
            [$btn_text, $btn_url] = array_map('trim', explode('|', $text, 2));
            $channels = loadChannels();
            $channels[$state['channel_id']]['buttons'][] = ['text'=>$btn_text,'url'=>$btn_url];
            saveChannels($channels);
            apiRequest('sendMessage', [
                'chat_id' => $from_id,
                'text' => 'تم إضافة الزر.',
                'reply_markup' => defaultKeyboard()
            ]);
            saveState($from_id, null);
            exit;
        }
    }

    if ($text === '/start') {
        // show persistent start button
        apiRequest('sendMessage', [
            'chat_id' => $from_id,
            'text' => 'القائمة الرئيسية',
            'reply_markup' => defaultKeyboard()
        ]);

        $channels = loadChannels();
        if (!$channels) {
            apiRequest('sendMessage', [
                'chat_id'=>$from_id,
                'text'=>'لا توجد قنوات حالياً.',
                'reply_markup' => defaultKeyboard()
            ]);
            exit;
        }
        $keyboard = ['inline_keyboard'=>[]];
        foreach ($channels as $id => $ch) {
            $keyboard['inline_keyboard'][] = [['text'=>$ch['title'],'callback_data'=>'channel_'.$id]];
        }
        apiRequest('sendMessage', ['chat_id'=>$from_id,'text'=>'اختر القناة:','reply_markup'=>$keyboard]);
        exit;
    }
}

if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $data = $callback['data'];
    $from_id = $callback['from']['id'];
    $message_id = $callback['message']['message_id'];

    if ($from_id != $admin_id) exit;

    if (strpos($data,'channel_') === 0) {
        $channel_id = substr($data,8);
        $channels = loadChannels();
        $ch = $channels[$channel_id];
        $keyboard = [
            'inline_keyboard' => [
                [['text'=>'تعديل الترحيب','callback_data'=>'edittext_'.$channel_id]],
                [['text'=>'إضافة زر','callback_data'=>'addbutton_'.$channel_id]],
                [['text'=>'حذف زر','callback_data'=>'delbutton_'.$channel_id]],
                [['text'=>'⬅️ رجوع','callback_data'=>'back']]
            ]
        ];
        apiRequest('editMessageText',[
            'chat_id'=>$from_id,
            'message_id'=>$message_id,
            'text'=>'إعدادات القناة: '.$ch['title'],
            'reply_markup'=>$keyboard
        ]);
        exit;
    }

    if ($data === 'back') {
        $channels = loadChannels();
        $keyboard = ['inline_keyboard'=>[]];
        foreach ($channels as $id=>$ch) {
            $keyboard['inline_keyboard'][] = [['text'=>$ch['title'],'callback_data'=>'channel_'.$id]];
        }
        apiRequest('editMessageText',[
            'chat_id'=>$from_id,
            'message_id'=>$message_id,
            'text'=>'اختر القناة:',
            'reply_markup'=>$keyboard
        ]);
        exit;
    }

    if (strpos($data,'edittext_') === 0) {
        $channel_id = substr($data,9);
        saveState($from_id,['action'=>'edit_text','channel_id'=>$channel_id]);
        apiRequest('sendMessage', [
            'chat_id' => $from_id,
            'text' => 'أرسل رسالة الترحيب الجديدة.',
            'reply_markup' => defaultKeyboard()
        ]);
        exit;
    }

    if (strpos($data,'addbutton_') === 0) {
        $channel_id = substr($data,10);
        saveState($from_id,['action'=>'add_button','channel_id'=>$channel_id]);
        apiRequest('sendMessage', [
            'chat_id' => $from_id,
            'text' => 'أرسل النص والرابط بهذا الشكل: اسم الزر | الرابط',
            'reply_markup' => defaultKeyboard()
        ]);
        exit;
    }

    if (strpos($data,'delbutton_') === 0) {
        $channel_id = substr($data,10);
        $channels = loadChannels();
        $buttons = $channels[$channel_id]['buttons'] ?? [];
        if (!$buttons) {
            apiRequest('answerCallbackQuery',['callback_query_id'=>$callback['id'],'text'=>'لا توجد أزرار']);
            exit;
        }
        $keyboard = ['inline_keyboard'=>[]];
        foreach ($buttons as $i=>$btn) {
            $keyboard['inline_keyboard'][] = [['text'=>($i+1).'. '.$btn['text'],'callback_data'=>'removebtn_'.$channel_id.'_'.$i]];
        }
        $keyboard['inline_keyboard'][] = [['text'=>'⬅️ رجوع','callback_data'=>'channel_'.$channel_id]];
        apiRequest('editMessageText',[
            'chat_id'=>$from_id,
            'message_id'=>$message_id,
            'text'=>'اختر الزر للحذف:',
            'reply_markup'=>$keyboard
        ]);
        exit;
    }

    if (strpos($data,'removebtn_') === 0) {
        [$prefix,$channel_id,$index] = explode('_',$data);
        $channels = loadChannels();
        array_splice($channels[$channel_id]['buttons'],$index,1);
        saveChannels($channels);
        apiRequest('answerCallbackQuery',['callback_query_id'=>$callback['id'],'text'=>'تم حذف الزر']);
        // refresh list
        $buttons = $channels[$channel_id]['buttons'] ?? [];
        if (!$buttons) {
            apiRequest('editMessageText',[
                'chat_id'=>$from_id,
                'message_id'=>$message_id,
                'text'=>'لا توجد أزرار.',
                'reply_markup'=>['inline_keyboard'=>[[['text'=>'⬅️ رجوع','callback_data'=>'channel_'.$channel_id]]]]
            ]);
            exit;
        }
        $keyboard = ['inline_keyboard'=>[]];
        foreach ($buttons as $i=>$btn) {
            $keyboard['inline_keyboard'][] = [['text'=>($i+1).'. '.$btn['text'],'callback_data'=>'removebtn_'.$channel_id.'_'.$i]];
        }
        $keyboard['inline_keyboard'][] = [['text'=>'⬅️ رجوع','callback_data'=>'channel_'.$channel_id]];
        apiRequest('editMessageText',[
            'chat_id'=>$from_id,
            'message_id'=>$message_id,
            'text'=>'اختر الزر للحذف:',
            'reply_markup'=>$keyboard
        ]);
        exit;
    }
}
?>
