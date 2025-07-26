<?php
// Telegram Welcome Bot with per-channel settings

$bot_token = getenv('BOT_TOKEN') ?: '7954391684:AAEUOWnBMhLb1BbR7uBOsI_ETTLQ5v_9jBs';
$admin_id  = intval(getenv('ADMIN_ID') ?: 7505722949);

function apiRequest(string $method, array $params = [], ?string $token = null)
{
    global $bot_token;
    $token = $token ?: $bot_token;
    $url = "https://api.telegram.org/bot{$token}/" . $method;
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

// --- Data helpers ---

define('DATA_DIR', __DIR__ . '/data');
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

function channelFile(int|string $id): string
{
    return DATA_DIR . "/{$id}.json";
}

function loadChannel(int|string $id): array
{
    $file = channelFile($id);
    if (!file_exists($file)) {
        return [];
    }
    return json_decode(file_get_contents($file), true) ?: [];
}

function saveChannel(int|string $id, array $data): void
{
    $file = channelFile($id);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function listChannels(): array
{
    $channels = [];
    foreach (glob(DATA_DIR . '/*.json') as $file) {
        $name = basename($file, '.json');
        if (str_starts_with($name, 'state_')) {
            continue;
        }
        $data = json_decode(file_get_contents($file), true);
        $channels[$name] = $data['title'] ?? $name;
    }
    return $channels;
}

function stateFile(int $user_id): string
{
    return DATA_DIR . "/state_{$user_id}.json";
}

function loadState(int $user_id)
{
    $file = stateFile($user_id);
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true);
}

function saveState(int $user_id, $state): void
{
    $file = stateFile($user_id);
    if ($state === null) {
        if (file_exists($file)) unlink($file);
        return;
    }
    file_put_contents($file, json_encode($state));
}

function defaultKeyboard(): array
{
    return [
        'keyboard' => [["/start"]],
        'resize_keyboard' => true,
    ];
}

function sendWelcomeMessage(int $user_id, int $channel_id, string $channel_title): void
{
    $settings = loadChannel($channel_id);
    if (!$settings) {
        $settings = [
            'title' => $channel_title,
            'welcome_message' => "👇 مرحبًا بك صديقي! تقدر تزور موقعنا:",
            'buttons' => [
                ['label' => '🔗 زور موقعنا', 'url' => 'https://his-lawyer.com'],
                ['label' => '🔥 رابط آخر', 'url' => 'https://example.com'],
            ],
            'custom_bot_token' => ''
        ];
        saveChannel($channel_id, $settings);
    }

    $keyboard = ['inline_keyboard' => []];
    foreach ($settings['buttons'] as $b) {
        $keyboard['inline_keyboard'][] = [['text' => $b['label'], 'url' => $b['url']]];
    }

    $token = $settings['custom_bot_token'] ?: null;

    apiRequest('sendMessage', [
        'chat_id' => $user_id,
        'text' => $settings['welcome_message'],
        'reply_markup' => $keyboard,
    ], $token);
}

// --- Update handling ---

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) {
    echo 'No update';
    exit;
}

// Handle join requests
if (isset($update['chat_join_request'])) {
    $req = $update['chat_join_request'];
    $user_id = $req['from']['id'];
    $chat = $req['chat'];
    sendWelcomeMessage($user_id, $chat['id'], $chat['title'] ?? '');
    exit;
}

// Handle text messages from admin
if (isset($update['message'])) {
    $message = $update['message'];
    $from_id = $message['from']['id'];
    $text = trim($message['text'] ?? '');

    if ($from_id != $admin_id) {
        exit; // ignore non-admins
    }

    $state = loadState($from_id);
    if ($state) {
        if ($state['action'] === 'edit_welcome') {
            if ($state['step'] === 'await_text') {
                $state['welcome_text'] = $text;
                $state['buttons'] = [];
                $state['step'] = 'await_buttons';
                saveState($from_id, $state);
                apiRequest('sendMessage', [
                    'chat_id' => $from_id,
                    'text' => "أرسل الأزرار بالشكل: اسم الزر | الرابط. أرسل /done عند الانتهاء.",
                    'reply_markup' => defaultKeyboard(),
                ]);
                exit;
            }
            if ($state['step'] === 'await_buttons') {
                if ($text === '/done') {
                    $settings = loadChannel($state['channel_id']);
                    $settings['welcome_message'] = $state['welcome_text'];
                    $settings['buttons'] = $state['buttons'];
                    saveChannel($state['channel_id'], $settings);
                    saveState($from_id, null);
                    apiRequest('sendMessage', [
                        'chat_id' => $from_id,
                        'text' => 'تم حفظ الإعدادات.',
                        'reply_markup' => defaultKeyboard(),
                    ]);
                    exit;
                }
                if (!strpos($text, '|')) {
                    apiRequest('sendMessage', [
                        'chat_id' => $from_id,
                        'text' => 'الرجاء إرسال بالصيغة: اسم الزر | الرابط أو /done للإنهاء.',
                        'reply_markup' => defaultKeyboard(),
                    ]);
                    exit;
                }
                [$label, $url] = array_map('trim', explode('|', $text, 2));
                $state['buttons'][] = ['label' => $label, 'url' => $url];
                saveState($from_id, $state);
                apiRequest('sendMessage', [
                    'chat_id' => $from_id,
                    'text' => 'تم إضافة الزر، أرسل زرًا آخر أو /done للإنهاء.',
                    'reply_markup' => defaultKeyboard(),
                ]);
                exit;
            }
        }
        if ($state['action'] === 'set_bot') {
            if ($state['step'] === 'ask_use') {
                $lower = mb_strtolower($text);
                if (in_array($lower, ['نعم', 'yes', 'y'])) {
                    $state['step'] = 'await_token';
                    saveState($from_id, $state);
                    apiRequest('sendMessage', [
                        'chat_id' => $from_id,
                        'text' => 'أرسل توكن البوت المخصص.',
                        'reply_markup' => defaultKeyboard(),
                    ]);
                    exit;
                } elseif (in_array($lower, ['لا', 'no', 'n'])) {
                    $settings = loadChannel($state['channel_id']);
                    $settings['custom_bot_token'] = '';
                    saveChannel($state['channel_id'], $settings);
                    saveState($from_id, null);
                    apiRequest('sendMessage', [
                        'chat_id' => $from_id,
                        'text' => 'تم الاعتماد على البوت الحالي.',
                        'reply_markup' => defaultKeyboard(),
                    ]);
                    exit;
                } else {
                    apiRequest('sendMessage', [
                        'chat_id' => $from_id,
                        'text' => 'الرجاء الإجابة بنعم أو لا.',
                        'reply_markup' => defaultKeyboard(),
                    ]);
                    exit;
                }
            }
            if ($state['step'] === 'await_token') {
                $token = $text;
                $settings = loadChannel($state['channel_id']);
                $settings['custom_bot_token'] = $token;
                saveChannel($state['channel_id'], $settings);
                saveState($from_id, null);
                apiRequest('sendMessage', [
                    'chat_id' => $from_id,
                    'text' => 'تم حفظ التوكن المخصص.',
                    'reply_markup' => defaultKeyboard(),
                ]);
                exit;
            }
        }
    }

    if ($text === '/start') {
        apiRequest('sendMessage', [
            'chat_id' => $from_id,
            'text' => 'القائمة الرئيسية',
            'reply_markup' => defaultKeyboard(),
        ]);

        $channels = listChannels();
        if (!$channels) {
            apiRequest('sendMessage', [
                'chat_id' => $from_id,
                'text' => 'لا توجد قنوات حالياً.',
                'reply_markup' => defaultKeyboard(),
            ]);
            exit;
        }
        $keyboard = ['inline_keyboard' => []];
        foreach ($channels as $id => $title) {
            $keyboard['inline_keyboard'][] = [[
                'text' => $title,
                'callback_data' => 'ch_' . $id,
            ]];
        }
        apiRequest('sendMessage', [
            'chat_id' => $from_id,
            'text' => 'اختر القناة:',
            'reply_markup' => $keyboard,
        ]);
        exit;
    }
}

// Handle callbacks from admin
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $data = $callback['data'];
    $from_id = $callback['from']['id'];
    $message_id = $callback['message']['message_id'];

    if ($from_id != $admin_id) {
        exit;
    }

    if ($data === 'back') {
        $channels = listChannels();
        $keyboard = ['inline_keyboard' => []];
        foreach ($channels as $id => $title) {
            $keyboard['inline_keyboard'][] = [[
                'text' => $title,
                'callback_data' => 'ch_' . $id,
            ]];
        }
        apiRequest('editMessageText', [
            'chat_id' => $from_id,
            'message_id' => $message_id,
            'text' => 'اختر القناة:',
            'reply_markup' => $keyboard,
        ]);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
        exit;
    }

    if (strpos($data, 'ch_') === 0) {
        $ch_id = substr($data, 3);
        $settings = loadChannel($ch_id);
        $keyboard = [
            'inline_keyboard' => [
                [[ 'text' => '✏️ تعديل رسالة الترحيب', 'callback_data' => 'edit_' . $ch_id ]],
                [[ 'text' => '🤖 تعيين بوت مخصص',    'callback_data' => 'bot_'  . $ch_id ]],
                [[ 'text' => '⬅️ رجوع', 'callback_data' => 'back' ]],
            ]
        ];
        apiRequest('editMessageText', [
            'chat_id' => $from_id,
            'message_id' => $message_id,
            'text' => 'إعدادات القناة: ' . ($settings['title'] ?? $ch_id),
            'reply_markup' => $keyboard,
        ]);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
        exit;
    }

    if (strpos($data, 'edit_') === 0) {
        $ch_id = substr($data, 5);
        saveState($from_id, [
            'action' => 'edit_welcome',
            'channel_id' => $ch_id,
            'step' => 'await_text',
        ]);
        apiRequest('sendMessage', [
            'chat_id' => $from_id,
            'text' => 'أرسل نص رسالة الترحيب.',
            'reply_markup' => defaultKeyboard(),
        ]);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
        exit;
    }

    if (strpos($data, 'bot_') === 0) {
        $ch_id = substr($data, 4);
        saveState($from_id, [
            'action' => 'set_bot',
            'channel_id' => $ch_id,
            'step' => 'ask_use',
        ]);
        apiRequest('sendMessage', [
            'chat_id' => $from_id,
            'text' => 'هل تريد استخدام بوت آخر لإرسال رسالة الترحيب؟ (نعم/لا)',
            'reply_markup' => defaultKeyboard(),
        ]);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
        exit;
    }
}
?>
