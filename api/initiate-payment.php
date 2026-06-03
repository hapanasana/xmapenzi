<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/selcom.php';
require_once __DIR__ . '/../includes/grebo.php';

header('Content-Type: application/json');
$in = require_post();

$itemType = $in['itemType'] ?? '';
$itemId   = $in['itemId']   ?? '';
$msisdn   = $in['msisdn']   ?? '';

if (!in_array($itemType, ['video','status_call','status_chat'], true)) json_out(['error' => 'Bad itemType'], 400);
if (!preg_match('/^[0-9a-f-]{36}$/i', $itemId)) json_out(['error' => 'Bad itemId'], 400);

$normalized = selcom_normalize_msisdn($msisdn);
if (!preg_match('/^2557\d{8}$/', $normalized)) json_out(['error' => 'Namba ya simu si sahihi'], 400);

// Resolve amount
$amount = 0; $label = '';
if ($itemType === 'video') {
    $s = db()->prepare('SELECT title, is_paid, price_tzs FROM videos WHERE id = ? AND is_active = 1');
    $s->execute([$itemId]); $v = $s->fetch();
    if (!$v) json_out(['error' => 'Video haipo'], 404);
    if (!(int)$v['is_paid']) json_out(['error' => 'Video hii ni bure'], 400);
    $amount = (int)$v['price_tzs']; $label = $v['title'];
} else {
    $s = db()->prepare('SELECT name, call_price_tzs, chat_price_tzs FROM statuses WHERE id = ? AND is_active = 1');
    $s->execute([$itemId]); $st = $s->fetch();
    if (!$st) json_out(['error' => 'Status haipo'], 404);
    $amount = (int)($itemType === 'status_call' ? $st['call_price_tzs'] : $st['chat_price_tzs']);
    $label  = $st['name'];
}
if ($amount <= 0) json_out(['error' => 'Kiasi si sahihi'], 400);

// Decide provider
$provider = setting('payment_provider', 'selcom');

$reference = 'XMP' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

// Insert a pending payment (mark provider in selcom_message for compatibility)
db()->prepare('INSERT INTO payments (reference, msisdn, item_type, item_id, amount_tzs, status, selcom_message)
    VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$reference, $normalized, $itemType, $itemId, $amount, 'pending', $provider]);

if ($provider === 'grebo') {
    $cfg = grebo_config();
    if (!$cfg['key']) json_out(['error' => 'Grebo haijasanidiwa. Wasiliana na admin.'], 500);
    $callback = SITE_URL . '/api/grebo-webhook.php';
    $res = grebo_deposit($cfg, [
        'amount' => $amount,
        'phone'  => $normalized,
        'reference' => $reference,
        'callback_url' => $callback,
    ]);
    $tx = $res['json']['data'] ?? $res['json'];
    $txId = $tx['id'] ?? $res['json']['id'] ?? null;
    $ok = ($res['status'] >= 200 && $res['status'] < 300)
        && (($res['json']['status'] ?? '') === 'success' || !empty($txId));

    if (!$ok) {
        db()->prepare('UPDATE payments SET status="failed", selcom_message=? WHERE reference=?')
            ->execute([substr(json_encode($res['json']), 0, 250), $reference]);
        json_out(['error' => $res['json']['message'] ?? 'Imeshindikana kutuma ombi. Jaribu tena.'], 502);
    }

    db()->prepare('UPDATE payments SET selcom_reference=? WHERE reference=?')->execute([$txId, $reference]);
    json_out([
        'reference' => $reference,
        'message'   => $res['json']['message'] ?? 'Angalia simu yako, ingiza PIN kukamilisha malipo.',
        'amount'    => $amount,
        'label'     => $label,
    ]);
}

// Default: Selcom
$cfg = selcom_config();
if (!$cfg['key'] || !$cfg['secret'] || !$cfg['vendor']) json_out(['error' => 'Selcom haijasanidiwa. Wasiliana na admin.'], 500);

$res = selcom_push_ussd($cfg, [
    'transid'    => $reference,
    'utilityref' => $itemId,
    'amount'     => $amount,
    'msisdn'     => $normalized,
]);

$ok = ($res['status'] >= 200 && $res['status'] < 300)
    && (($res['json']['resultcode'] ?? '') === '000' || ($res['json']['result'] ?? '') === 'SUCCESS');

if (!$ok) {
    db()->prepare('UPDATE payments SET status="failed", selcom_message=? WHERE reference=?')
        ->execute([substr(json_encode($res['json']), 0, 250), $reference]);
    json_out(['error' => $res['json']['message'] ?? 'Imeshindikana kutuma ombi. Jaribu tena.'], 502);
}

json_out([
    'reference' => $reference,
    'message'   => $res['json']['message'] ?? 'Angalia simu yako, ingiza PIN kukamilisha malipo.',
    'amount'    => $amount,
    'label'     => $label,
]);