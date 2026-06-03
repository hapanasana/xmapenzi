<?php
/**
 * Test Grebo Webhook Integration
 * 
 * This script simulates a Grebo webhook to test the payment flow.
 * Run: php test-grebo-webhook.php <REFERENCE> <SECRET>
 * Example: php test-grebo-webhook.php XMP20260531123456ABC123456 test_secret_xyz
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/grebo.php';

if ($argc < 3) {
    echo "Usage: php test-grebo-webhook.php <REFERENCE> <WEBHOOK_SECRET>\n";
    echo "Example: php test-grebo-webhook.php XMP20260531123456ABC123456 test_secret_xyz\n";
    exit(1);
}

$reference = $argv[1];
$secret = $argv[2];

// 1. Create a test payload
$payload = [
    'event' => 'transaction.completed',
    'data' => [
        'id' => 'tx_' . bin2hex(random_bytes(8)),
        'type' => 'deposit',
        'method' => 'mobile',
        'amount_tzs' => 1000,
        'status' => 'completed',
        'reference' => $reference,
        'completed_at' => date('c'),
    ],
];

$raw = json_encode($payload);
$timestamp = gmdate('D, d M Y H:i:s T');

// 2. Sign it using Grebo's documented scheme: HMAC-SHA256(timestamp.rawBody)
$signature = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);

echo "=== Grebo Webhook Test ===\n";
echo "Reference: $reference\n";
echo "Secret: $secret\n";
echo "Signature: $signature\n";
echo "Payload:\n" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

// 3. Insert a test payment (pending)
try {
    $s = db()->prepare('SELECT id FROM payments WHERE reference = ?');
    $s->execute([$reference]);
    $existing = $s->fetch();
    
    if (!$existing) {
        echo "[INFO] Creating test payment with reference: $reference\n";
        db()->prepare('INSERT INTO payments (reference, msisdn, item_type, item_id, amount_tzs, status, selcom_message)
            VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
            $reference,
            '255712345678',
            'video',
            bin2hex(random_bytes(18)), // 36-char UUID
            1000,
            'pending',
            'grebo',
        ]);
    }
    
    // 4. Simulate the webhook call
    echo "[TEST] Simulating webhook call...\n";
    
    // Manually process as the webhook would
    $cfg = grebo_config();
    $cfg['webhook_secret'] = $secret;
    
    if (!grebo_verify_signature($secret, $raw, $signature, $timestamp)) {
        echo "[ERROR] Signature verification failed!\n";
        exit(1);
    }
    echo "[OK] Signature verified\n";
    
    $data = json_decode($raw, true);
    $event = $data['event'] ?? '';
    $txData = $data['data'] ?? [];
    $ref = $txData['reference'] ?? '';
    
    // Update payment as webhook would
    $s = db()->prepare('SELECT id, status FROM payments WHERE reference = ?');
    $s->execute([$ref]);
    $p = $s->fetch();
    
    if (!$p) {
        echo "[ERROR] Payment not found!\n";
        exit(1);
    }
    
    if ($p['status'] !== 'paid') {
        if ($txData['status'] === 'completed' || $event === 'transaction.completed') {
            $token = bin2hex(random_bytes(16));
            db()->prepare('UPDATE payments SET status="paid", paid_at=NOW(), selcom_reference=?, selcom_resultcode=?, selcom_message=?, unlock_token=? WHERE reference=?')
                ->execute([$txData['id'] ?? null, $txData['status'] ?? 'completed', substr(json_encode($txData), 0, 250), $token, $ref]);
            echo "[OK] Payment marked as PAID\n";
            echo "    Unlock token: $token\n";
        }
    }
    
    // 5. Verify update
    $s = db()->prepare('SELECT status, unlock_token FROM payments WHERE reference = ?');
    $s->execute([$reference]);
    $updated = $s->fetch();
    
    echo "\n=== Result ===\n";
    echo "Status: " . ($updated['status'] ?? 'unknown') . "\n";
    echo "Unlock token: " . ($updated['unlock_token'] ?? 'N/A') . "\n";
    
    if ($updated['status'] === 'paid' && $updated['unlock_token']) {
        echo "\n✓ TEST PASSED: Payment marked as paid with unlock token\n";
    } else {
        echo "\n✗ TEST FAILED: Payment not properly updated\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
?>
