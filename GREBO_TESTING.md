# Grebo Integration Testing Guide

## Overview
This document describes how to test the Grebo payment integration and verify the end-to-end flow works correctly.

## Quick Setup

### 1. Configure Grebo in Admin Settings

1. Go to **Admin → Settings → Grebo API**
2. Set:
   - **Base URL**: `https://grebo.tesloty.com` (or your custom URL)
   - **API Key**: Get from [https://tesloty.lovable.app/dashboard/api-keys](https://tesloty.lovable.app/dashboard/api-keys)
   - **Webhook secret**: Generate a strong secret (e.g., `test_secret_xyz_123`)
3. Save settings
4. Copy the **Webhook URL** displayed (should be `[YOUR_SITE]/api/grebo-webhook.php`)
5. Set this webhook URL in the Grebo dashboard at [https://tesloty.lovable.app/dashboard/webhooks](https://tesloty.lovable.app/dashboard/webhooks)

### 2. Choose Grebo as Payment Provider

In **Admin → Settings → Selcom API** section:
- Set **Payment provider** to `Grebo`
- Save

## Testing Methods

### Method 1: PHP CLI Test (No HTTP needed)

This test creates a payment record and simulates a webhook locally:

```bash
cd /path/to/xmapenzi-php
php test-grebo-webhook.php XMP20260531123456ABC123456 "your_webhook_secret"
```

**Example output:**
```
=== Grebo Webhook Test ===
Reference: XMP20260531123456ABC123456
Secret: your_webhook_secret
Signature: abc123...
[INFO] Creating test payment with reference: XMP20260531123456ABC123456
[TEST] Simulating webhook call...
[OK] Signature verified
[OK] Payment marked as PAID
    Unlock token: def456...

=== Result ===
Status: paid
Unlock token: def456...

✓ TEST PASSED: Payment marked as paid with unlock token
```

### Method 2: HTTP Webhook Test (curl)

If you have curl and openssl:

```bash
bash test-webhook.sh "https://xmapenzi.flatbet.online" "XMP20260531123456ABC123456" "your_webhook_secret"
```

This sends a real HTTP POST to your webhook endpoint with a properly signed Grebo payload.

### Method 3: Manual Database Test

If you want to test just the database flow:

```bash
# 1. Insert a pending payment
mysql -u xmapenzi_user -p xmapenzi_db -e "
INSERT INTO payments (reference, msisdn, item_type, item_id, amount_tzs, status, selcom_message) 
VALUES ('XMP20260531TEST001', '255712345678', 'video', UUID(), 1000, 'pending', 'grebo');
"

# 2. Run PHP test to mark it as paid
php test-grebo-webhook.php XMP20260531TEST001 "your_webhook_secret"

# 3. Verify in admin panel
# Admin → Payments → check the payment status is "paid" with provider "Grebo"
```

## Integration Flow

```
┌─ Frontend                     ┌─ Xmapenzi API              ┌─ Grebo API
│                              │                             │
├─ User clicks "Pay"           │                             │
├─ Calls POST /api/initiate-payment.php with:              │
│   - itemType: 'video'        │                             │
│   - itemId: '...'            │                             │
│   - msisdn: '255712345678'   │                             │
│                              │                             │
│                              ├─ Check payment_provider setting
│                              ├─ If 'grebo': Call grebo_deposit()
│                              ├─────────────────────────────→ POST /api/v1/deposits
│                              │                             │ {amount, phone, reference, callback_url}
│                              │                             │
│                              │←─────────────────────────── {id, status, ...}
│                              │ (payment now PENDING)
│←───────────────────────────── {reference, message, amount}
│
├─ User sees USSD prompt
├─ User enters PIN
├─ Grebo processes payment
├─ Grebo calls webhook with: X-Webhook-Signature + X-Webhook-Timestamp
│  (signature = HMAC-SHA256(timestamp.rawBody))
│
│                              ├─ POST /api/grebo-webhook.php
│                              ├─ Verify signature with grebo_webhook_secret
│                              ├─ Update payments SET status='paid', unlock_token='...'
│                              ├─ Return 200 OK
│
├─ Frontend polls /api/poll-payment.php
├─ Gets unlock_token
├─ Unlocks content for user
```

## Columns Used in Payments Table

Even though we reuse Selcom columns for compatibility:

| Column | Grebo Usage |
|--------|-------------|
| `reference` | Xmapenzi reference (e.g., `XMP20260531...`) |
| `selcom_reference` | Grebo transaction ID (e.g., `tx_...`) |
| `selcom_resultcode` | Grebo transaction status (e.g., `completed`, `failed`) |
| `selcom_message` | `'grebo'` (to identify provider) or Grebo error JSON |
| `unlock_token` | Generated when payment is confirmed |
| `status` | `pending` → `paid` or `failed` |

## Troubleshooting

### Signature verification fails
- Verify **Webhook secret** is the same in:
  - Admin Settings (Grebo API)
  - Grebo dashboard (webhooks)
  - Test script parameter
- Ensure the raw body is signed (not form-encoded)

### Payment not created
- Check that `payment_provider` is set to `grebo` in Admin Settings
- Verify `grebo_api_key` is set (not empty)

### Webhook not received
- Confirm webhook URL is set in Grebo dashboard
- Check firewall/NAT allows inbound on your webhook endpoint
- Monitor your server logs at `/api/grebo-webhook.php`

### Unlock token not generated
- Check that webhook reached `/api/grebo-webhook.php` (add logging)
- Verify `grebo_webhook_secret` matches what Grebo is signing with
- Inspect database: `SELECT * FROM payments WHERE reference = '...'`

## Next Steps

1. **Test with Grebo test key** (`grb_test_...`)
   - No real money moves
   - Useful for development
   
2. **Switch to production key** (`grb_live_...`)
   - Requires Business Application approval
   - Real payments are processed

3. **Monitor webhook delivery**
   - Grebo retries failed webhooks (1m, 5m, 30m, 2h, 6h, 12h, 24h, 48h)
   - Always respond with `200 OK` within 5 seconds

## File Locations

- **Config**: `includes/grebo.php` (reads from admin settings)
- **Initiate**: `api/initiate-payment.php` (routes to Grebo if provider='grebo')
- **Poll**: `api/poll-payment.php` (skips Selcom query for Grebo)
- **Webhook**: `api/grebo-webhook.php` (receives and signs Grebo events)
- **Admin Settings**: `admin/settings.php` (editable Grebo config)
- **Tests**: `test-grebo-webhook.php`, `test-webhook.sh`

