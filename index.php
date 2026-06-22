<?php
/**
 * Telegram Reward Bot - Backend
 * Handles: Webhook, Commands, Withdrawals, Admin Notifications, Mini App API
 */

// ==================== CONFIGURATION ====================
define('BOT_TOKEN', '7998031513:AAFYjPb7J8rtOcvg8ezqaOHK9PFPWaglP_k');
define('BOT_USERNAME', 'TaskProBot99_bot');
define('ADMIN_CHAT_ID', '7052955667');
define('MINI_APP_URL', 'https://YOUR-DOMAIN.com/index.html'); // ⚠️ Change to your HTTPS domain
define('REWARD_PER_CHAIN', 10);            // 10 coins per full ad chain
define('MIN_WITHDRAWAL', 10000);           // Min coins for withdrawal
define('COINS_PER_BDT', 200);              // 200 coins = 1 BDT (10000 coins = 50 BDT)
define('AD_COOLDOWN', 25);                 // Seconds between ad rewards (anti-abuse)

// ==================== STORAGE ====================
define('DATA_DIR', __DIR__ . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');
define('WITHDRAWALS_FILE', DATA_DIR . '/withdrawals.json');
define('STATES_FILE', DATA_DIR . '/states.json');

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);

// ==================== HELPERS ====================
function api(string $method, array $params = []): array {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true) ?? [];
}

function loadJson(string $file): array {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveJson(string $file, array $data): void {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function getUsers(): array { return loadJson(USERS_FILE); }
function saveUsers(array $u): void { saveJson(USERS_FILE, $u); }
function getStates(): array { return loadJson(STATES_FILE); }
function saveStates(array $s): void { saveJson(STATES_FILE, $s); }

function getUser($id): ?array {
    $users = getUsers();
    return $users[$id] ?? null;
}

function createUser($id, $username, $firstName, $refBy = null): bool {
    $users = getUsers();
    if (isset($users[$id])) return false;
    $users[$id] = [
        'id' => (string)$id,
        'username' => $username,
        'first_name' => $firstName,
        'balance' => 0,
        'ref_by' => $refBy,
        'refs' => [],
        'total_ads_watched' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    saveUsers($users);
    return true;
}

// ==================== KEYBOARDS ====================
function mainKeyboard(): array {
    return [
        'inline_keyboard' => [
            [
                ['text' => '📺 Watch Ads', 'web_app' => ['url' => MINI_APP_URL]],
                ['text' => '👤 Profile', 'callback_data' => 'profile'],
            ],
            [
                ['text' => '🔗 Refer', 'callback_data' => 'refer'],
                ['text' => '💸 Withdraw', 'callback_data' => 'withdraw'],
            ],
        ],
    ];
}

function backButton(string $data = 'main_menu'): array {
    return ['inline_keyboard' => [[['text' => '⬅️ Back to Menu', 'callback_data' => $data]]]];
}

// ==================== COMMAND HANDLERS ====================
function handleStart($chatId, $userId, $username, $firstName, $refParam = null): void {
    $refBy = ($refParam && $refParam != $userId) ? (string)$refParam : null;
    $isNew = createUser($userId, $username, $firstName, $refBy);

    // Track referral
    if ($isNew && $refBy) {
        $users = getUsers();
        if (isset($users[$refBy]) && !in_array((string)$userId, $users[$refBy]['refs'])) {
            $users[$refBy]['refs'][] = (string)$userId;
            saveUsers($users);
        }
    }

    // Clear any pending withdrawal state
    $states = getStates();
    unset($states[$userId]);
    saveStates($states);

    $user = getUser($userId);
    $name = $user['first_name'] ?? 'User';
    $balance = $user['balance'] ?? 0;
    $uname = !empty($user['username']) ? '@' . $user['username'] : 'N/A';

    $text = "👋 <b>Welcome to @" . BOT_USERNAME . ", {$name}!</b>\n\n"
          . "💰 <b>Balance:</b> {$balance} Coins\n"
          . "👤 <b>Username:</b> {$uname}\n\n"
          . "📺 Watch 3 ads in sequence to earn <b>10 Coins</b>.\n"
          . "💸 10,000 Coins = 50 BDT (bKash/Nagad/Rocket/Binance).\n\n"
          . "Choose an option below 👇";

    api('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(mainKeyboard()),
    ]);
}

function showProfile($chatId, $userId): void {
    $user = getUser($userId);
    $balance = $user['balance'] ?? 0;
    $refs = count($user['refs'] ?? []);
    $ads = $user['total_ads_watched'] ?? 0;
    $uname = !empty($user['username']) ? '@' . $user['username'] : 'N/A';

    $text = "👤 <b>Profile</b>\n\n"
          . "🆔 <b>ID:</b> <code>{$userId}</code>\n"
          . "👤 <b>Username:</b> {$uname}\n"
          . "💰 <b>Balance:</b> {$balance} Coins\n"
          . "📺 <b>Ads Watched:</b> {$ads}\n"
          . "👥 <b>Referrals:</b> {$refs}\n"
          . "📅 <b>Joined:</b> {$user['created_at']}\n";

    api('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(backButton()),
    ]);
}

function showRefer($chatId, $userId): void {
    $refLink = "https://t.me/" . BOT_USERNAME . "?start={$userId}";
    $user = getUser($userId);
    $refs = count($user['refs'] ?? []);
    $text = "🔗 <b>Referral Program</b>\n\n"
          . "Share your link with friends:\n\n"
          . "<code>{$refLink}</code>\n\n"
          . "👥 Total Referrals: <b>{$refs}</b>\n\n"
          . "Your friends join & earn, you grow the community!";

    api('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(backButton()),
    ]);
}

function startWithdrawal($chatId, $userId): void {
    $user = getUser($userId);
    $balance = $user['balance'] ?? 0;

    if ($balance < MIN_WITHDRAWAL) {
        api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❌ <b>Insufficient Balance!</b>\n\n"
                   . "Min withdrawal: <b>" . MIN_WITHDRAWAL . " Coins</b>\n"
                   . "Your balance: <b>{$balance} Coins</b>\n\n"
                   . "You need <b>" . (MIN_WITHDRAWAL - $balance) . "</b> more coins.",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(backButton()),
        ]);
        return;
    }

    $maxBdt = floor($balance / COINS_PER_BDT);
    $minBdt = MIN_WITHDRAWAL / COINS_PER_BDT;

    $text = "💸 <b>Withdrawal</b>\n\n"
          . "💰 Balance: <b>{$balance} Coins</b>\n"
          . "💵 Equivalent: <b>{$maxBdt} BDT</b>\n"
          . "📊 Rate: 10,000 Coins = 50 BDT\n"
          . "⚠️ Minimum: <b>{$minBdt} BDT</b>\n\n"
          . "Select payment method 👇";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🟠 bKash', 'callback_data' => 'pay_method_bKash'],
                ['text' => '🟣 Nagad', 'callback_data' => 'pay_method_Nagad'],
            ],
            [
                ['text' => '🚀 Rocket', 'callback_data' => 'pay_method_Rocket'],
                ['text' => '🟡 Binance', 'callback_data' => 'pay_method_Binance'],
            ],
            [['text' => '❌ Cancel', 'callback_data' => 'cancel_withdraw']],
        ],
    ];

    api('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode($keyboard),
    ]);
}

function setWithdrawalMethod($userId, $method): void {
    $states = getStates();
    $states[$userId] = ['step' => 'awaiting_wallet', 'method' => $method];
    saveStates($states);
}

function handleWithdrawalState($userId, $chatId, $text): void {
    $states = getStates();
    if (!isset($states[$userId])) {
        api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Unknown command. Send /start to open the menu.",
        ]);
        return;
    }
    $state = $states[$userId];

    if ($state['step'] === 'awaiting_wallet') {
        $wallet = trim($text);
        if (strlen($wallet) < 5) {
            api('sendMessage', [
                'chat_id' => $chatId,
                'text' => "❌ Invalid wallet. Please send a valid number/address:",
            ]);
            return;
        }
        $states[$userId]['wallet'] = $wallet;
        $states[$userId]['step'] = 'awaiting_amount';
        saveStates($states);

        $user = getUser($userId);
        $balance = $user['balance'] ?? 0;
        $maxBdt = floor($balance / COINS_PER_BDT);

        api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "✅ Wallet saved: <code>{$wallet}</code>\n\n"
                   . "Now send the amount in <b>BDT</b> (Max: <b>{$maxBdt} BDT</b>):",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[['text' => '❌ Cancel', 'callback_data' => 'cancel_withdraw']]],
            ]),
        ]);
    } elseif ($state['step'] === 'awaiting_amount') {
        $amount = floatval($text);
        $user = getUser($userId);
        $balance = $user['balance'] ?? 0;
        $maxBdt = floor($balance / COINS_PER_BDT);
        $minBdt = MIN_WITHDRAWAL / COINS_PER_BDT;

        if ($amount < $minBdt) {
            api('sendMessage', [
                'chat_id' => $chatId,
                'text' => "❌ Minimum withdrawal is <b>{$minBdt} BDT</b>. Try again:",
                'parse_mode' => 'HTML',
            ]);
            return;
        }
        if ($amount > $maxBdt) {
            api('sendMessage', [
                'chat_id' => $chatId,
                'text' => "❌ Max you can withdraw is <b>{$maxBdt} BDT</b>. Try again:",
                'parse_mode' => 'HTML',
            ]);
            return;
        }

        $coinsToDeduct = (int)($amount * COINS_PER_BDT);
        $method = $state['method'];
        $wallet = $state['wallet'];

        // Deduct coins
        $users = getUsers();
        $users[$userId]['balance'] -= $coinsToDeduct;
        saveUsers($users);

        // Save withdrawal record
        $withdrawals = loadJson(WITHDRAWALS_FILE);
        $withdrawId = 'WD' . date('YmdHis') . '_' . $userId;
        $withdrawals[$withdrawId] = [
            'id' => $withdrawId,
            'user_id' => (string)$userId,
            'username' => $user['username'] ?? '',
            'first_name' => $user['first_name'] ?? '',
            'method' => $method,
            'wallet' => $wallet,
            'amount_bdt' => $amount,
            'coins' => $coinsToDeduct,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        saveJson(WITHDRAWALS_FILE, $withdrawals);

        // Clear state
        unset($states[$userId]);
        saveStates($states);

        // Notify user
        api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "✅ <b>Withdrawal Submitted!</b>\n\n"
                   . "🆔 ID: <code>{$withdrawId}</code>\n"
                   . "💸 Method: <b>{$method}</b>\n"
                   . "💳 Wallet: <code>{$wallet}</code>\n"
                   . "💰 Amount: <b>{$amount} BDT</b>\n"
                   . "📉 Coins Deducted: {$coinsToDeduct}\n"
                   . "⏳ Status: <b>Pending</b>\n\n"
                   . "Your request is sent to admin for approval.",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(mainKeyboard()),
        ]);

        // Notify admin
        $uname = !empty($user['username']) ? '@' . $user['username'] : ($user['first_name'] ?? 'Unknown');
        $adminText = "🔔 <b>New Withdrawal Request</b>\n\n"
                   . "🆔 Request ID: <code>{$withdrawId}</code>\n"
                   . "👤 User: {$uname}\n"
                   . "🆔 User ID: <code>{$userId}</code>\n"
                   . "💸 Method: <b>{$method}</b>\n"
                   . "💳 Wallet/Address: <code>{$wallet}</code>\n"
                   . "💰 Amount: <b>{$amount} BDT</b>\n"
                   . "📉 Coins: {$coinsToDeduct}\n"
                   . "📅 Time: " . date('Y-m-d H:i:s') . "\n";

        api('sendMessage', [
            'chat_id' => ADMIN_CHAT_ID,
            'text' => $adminText,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '✅ Approve', 'callback_data' => 'approve_' . $withdrawId],
                    ['text' => '❌ Reject', 'callback_data' => 'reject_' . $withdrawId],
                ]],
            ]),
        ]);
    }
}

function handleWithdrawalDecision($withdrawId, $decision): void {
    $withdrawals = loadJson(WITHDRAWALS_FILE);
    if (!isset($withdrawals[$withdrawId])) return;

    $w = $withdrawals[$withdrawId];
    if ($w['status'] !== 'pending') return;

    $withdrawals[$withdrawId]['status'] = $decision;
    $withdrawals[$withdrawId]['decided_at'] = date('Y-m-d H:i:s');
    saveJson(WITHDRAWALS_FILE, $withdrawals);

    // If rejected, refund coins
    if ($decision === 'rejected') {
        $users = getUsers();
        if (isset($users[$w['user_id']])) {
            $users[$w['user_id']]['balance'] += $w['coins'];
            saveUsers($users);
        }
    }

    $emoji = $decision === 'approved' ? '✅' : '❌';
    $status = $decision === 'approved' ? 'APPROVED & PAID' : 'REJECTED (Coins Refunded)';

    // Notify user
    api('sendMessage', [
        'chat_id' => $w['user_id'],
        'text' => "{$emoji} <b>Withdrawal Update</b>\n\n"
               . "🆔 ID: <code>{$withdrawId}</code>\n"
               . "💰 Amount: {$w['amount_bdt']} BDT\n"
               . "💸 Method: {$w['method']}\n"
               . "📋 Status: <b>{$status}</b>",
        'parse_mode' => 'HTML',
    ]);

    // Notify admin confirmation
    api('sendMessage', [
        'chat_id' => ADMIN_CHAT_ID,
        'text' => "{$emoji} Withdrawal <code>{$withdrawId}</code> marked as <b>{$decision}</b>.",
        'parse_mode' => 'HTML',
    ]);
}

// ==================== MINI APP API ====================
function verifyTelegramInitData(string $initData): array {
    if (!$initData) return ['ok' => false];
    $parsed = [];
    parse_str($initData, $parsed);
    $hash = $parsed['hash'] ?? '';
    if (!$hash) return ['ok' => false];
    unset($parsed['hash']);

    $dataCheck = [];
    foreach ($parsed as $k => $v) $dataCheck[] = "{$k}={$v}";
    sort($dataCheck);
    $dataCheckString = implode("\n", $dataCheck);

    $secretKey = hash_hmac('sha256', BOT_TOKEN, 'WebAppData', true);
    $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

    if (!hash_equals($computedHash, $hash)) return ['ok' => false];

    $user = isset($parsed['user']) ? json_decode($parsed['user'], true) : null;
    return ['ok' => true, 'user' => $user, 'auth_date' => $parsed['auth_date'] ?? 0];
}

function handleApiRequest(): void {
    header('Content-Type: application/json');

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) $data = $_POST;

    $action = $data['action'] ?? '';
    $initData = $data['init_data'] ?? '';

    $verification = verifyTelegramInitData($initData);
    if (!$verification['ok']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid Telegram signature']);
        return;
    }

    $tgUser = $verification['user'];
    $userId = (string)$tgUser['id'];

    // Anti-replay (auth_date within 24h)
    if (time() - (int)$verification['auth_date'] > 86400) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Expired session']);
        return;
    }

    if ($action === 'get_balance') {
        $user = getUser($userId);
        if (!$user) {
            createUser($userId, $tgUser['username'] ?? '', $tgUser['first_name'] ?? 'User', null);
            $user = getUser($userId);
        }
        echo json_encode(['ok' => true, 'balance' => $user['balance'] ?? 0]);
        return;
    }

    if ($action === 'credit_ad_reward') {
        $users = getUsers();
        if (!isset($users[$userId])) {
            createUser($userId, $tgUser['username'] ?? '', $tgUser['first_name'] ?? 'User', null);
            $users = getUsers();
        }

        // Anti-abuse: cooldown
        $lastReward = $users[$userId]['last_reward_time'] ?? 0;
        if (time() - $lastReward < AD_COOLDOWN) {
            http_response_code(429);
            $wait = AD_COOLDOWN - (time() - $lastReward);
            echo json_encode(['ok' => false, 'error' => 'cooldown', 'wait' => $wait]);
            return;
        }

        $users[$userId]['balance'] = ($users[$userId]['balance'] ?? 0) + REWARD_PER_CHAIN;
        $users[$userId]['last_reward_time'] = time();
        $users[$userId]['total_ads_watched'] = ($users[$userId]['total_ads_watched'] ?? 0) + 1;
        saveUsers($users);

        echo json_encode([
            'ok' => true,
            'rewarded' => REWARD_PER_CHAIN,
            'new_balance' => $users[$userId]['balance'],
        ]);
        return;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

// ==================== ROUTER ====================
// If no Telegram update payload, treat as Mini App API
 $rawInput = file_get_contents("php://input");
 $update = json_decode($rawInput, true);

// Detect API requests (they have 'action' field)
if (is_array($update) && isset($update['action'])) {
    handleApiRequest();
    exit;
}
// Also handle form POST API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['action'])) {
    handleApiRequest();
    exit;
}

// ==================== WEBHOOK UPDATE HANDLER ====================
if (!$update) {
    http_response_code(200);
    echo 'OK';
    exit;
}

if (isset($update['message'])) {
    $msg = $update['message'];
    $chatId = $msg['chat']['id'];
    $userId = (string)$msg['from']['id'];
    $username = $msg['from']['username'] ?? '';
    $firstName = $msg['from']['first_name'] ?? 'User';
    $text = $msg['text'] ?? '';

    if (strpos($text, '/start') === 0) {
        $parts = explode(' ', $text, 2);
        $refParam = isset($parts[1]) ? trim($parts[1]) : null;
        handleStart($chatId, $userId, $username, $firstName, $refParam);
    } elseif ($text === '/help' || $text === '/menu') {
        handleStart($chatId, $userId, $username, $firstName, null);
    } else {
        handleWithdrawalState($userId, $chatId, $text);
    }
} elseif (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $userId = (string)$cb['from']['id'];
    $chatId = $cb['message']['chat']['id'];
    $data = $cb['data'];
    $username = $cb['from']['username'] ?? '';
    $firstName = $cb['from']['first_name'] ?? 'User';

    createUser($userId, $username, $firstName, null);
    api('answerCallbackQuery', ['callback_query_id' => $cb['id']]);

    if (strpos($data, 'approve_') === 0 || strpos($data, 'reject_') === 0) {
        $parts = explode('_', $data, 2);
        $decision = $parts[0];   // 'approve' or 'reject'
        $wid = $parts[1];
        // Only admin can decide
        if ($userId != ADMIN_CHAT_ID) {
            api('sendMessage', ['chat_id' => $chatId, 'text' => '🚫 Only admin can perform this action.']);
        } else {
            handleWithdrawalDecision($wid, $decision === 'approve' ? 'approved' : 'rejected');
        }
    } elseif (strpos($data, 'pay_method_') === 0) {
        $method = str_replace('pay_method_', '', $data);
        setWithdrawalMethod($userId, $method);
        api('sendMessage', [
            'chat_id' => $chatId,
            'text' => "✅ Selected: <b>{$method}</b>\n\nSend your <b>{$method}</b> wallet number/address:",
            'parse_mode' => 'HTML',
        ]);
    } else {
        switch ($data) {
            case 'profile':
                showProfile($chatId, $userId);
                break;
            case 'refer':
                showRefer($chatId, $userId);
                break;
            case 'withdraw':
                startWithdrawal($chatId, $userId);
                break;
            case 'cancel_withdraw':
                $states = getStates();
                unset($states[$userId]);
                saveStates($states);
                api('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => '❌ Withdrawal cancelled.',
                    'reply_markup' => json_encode(mainKeyboard()),
                ]);
                break;
            case 'main_menu':
                handleStart($chatId, $userId, $username, $firstName, null);
                break;
        }
    }
}