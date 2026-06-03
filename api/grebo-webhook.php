<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/grebo.php';

$cfg = grebo_config();
$secret = $cfg['webhook_secret'] ?? '';

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? $_SERVER['HTTP_X_GREBO_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';

if (!$secret || !$sig || !grebo_verify_signature($secret, $raw, $sig, $timestamp)) {
    http_response_code(401); die('Unauthorized');
}

$payload = json_decode($raw, true);
if (!is_array($payload) || empty($payload['event'])) { http_response_code(400); die('Bad payload'); }

$event = $payload['event'];
$data = $payload['data'] ?? [];
$reference = $data['reference'] ?? '';

if (!$reference) { http_response_code(400); die('Missing reference'); }

$s = db()->prepare('SELECT id, status FROM payments WHERE reference = ?');
$s->execute([$reference]); $p = $s->fetch();
if (!$p) { http_response_code(404); die('Unknown'); }
if ($p['status'] === 'paid') { echo 'ok'; exit; }

// Grebo event statuses: check data.status
$status = $data['status'] ?? '';
if ($status === 'completed' || $event === 'transaction.completed') {
    $token = bin2hex(random_bytes(16));
    db()->prepare('UPDATE payments SET status="paid", paid_at=NOW(), selcom_reference=?, selcom_resultcode=?, selcom_message=?, unlock_token=? WHERE reference=?')
        ->execute([$data['id'] ?? null, $status, substr(json_encode($data), 0, 250), $token, $reference]);
        // credit user wallet
        db()->exec("CREATE TABLE IF NOT EXISTS `wallets` (
            `msisdn` VARCHAR(20) NOT NULL,
            `balance_tzs` INT NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`msisdn`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $s2 = db()->prepare('SELECT msisdn, amount_tzs FROM payments WHERE reference = ?'); $s2->execute([$reference]); $pd = $s2->fetch();
        if ($pd) {
            db()->prepare('INSERT INTO wallets (msisdn, balance_tzs) VALUES (?, ?) ON DUPLICATE KEY UPDATE balance_tzs = balance_tzs + VALUES(balance_tzs)')
                ->execute([$pd['msisdn'], (int)$pd['amount_tzs']]);
        }
} elseif ($status === 'failed' || $event === 'transaction.failed') {
    db()->prepare('UPDATE payments SET status="failed", selcom_reference=?, selcom_resultcode=?, selcom_message=? WHERE reference=?')
        ->execute([$data['id'] ?? null, $status, substr(json_encode($data), 0, 250), $reference]);
}
echo 'ok';
