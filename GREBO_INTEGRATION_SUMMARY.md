# Xmapenzi Grebo Integration — Implementation Summary

## Changes Made

### New Files Created
- **`includes/grebo.php`** — Grebo API helper functions
  - `grebo_config()` — reads settings from admin panel
  - `grebo_http()` — HTTP wrapper for API calls
  - `grebo_deposit()` — initiate deposit/payment
  - `grebo_verify_signature()` — webhook signature verification
  
- **`api/grebo-webhook.php`** — Webhook endpoint for Grebo
  - Verifies `x-webhook-signature` + `x-webhook-timestamp` using `timestamp.rawBody`
  - Updates payment status when transaction completes/fails
  - Uses `selcom_*` columns for compatibility (no schema change needed)

- **`test-grebo-webhook.php`** — CLI test for webhook flow
  - Simulates a Grebo webhook locally
  - Creates test payment and verifies update
  - Usage: `php test-grebo-webhook.php <reference> <secret>`

- **`test-webhook.sh`** — Curl-based HTTP webhook test
  - Sends properly signed HTTP POST to webhook endpoint
  - Usage: `bash test-webhook.sh <url> <reference> <secret>`

- **`GREBO_TESTING.md`** — Complete testing & troubleshooting guide

### Modified Files

#### `admin/settings.php`
- Added "Payment provider" selector (Selcom / Grebo)
- Added Grebo API section with editable fields:
  - Base URL (configurable, defaults to https://grebo.tesloty.com)
  - API Key
  - Webhook secret
- All Grebo settings are saved via POST and read from database

#### `api/initiate-payment.php`
- Routes payment to Selcom or Grebo based on `payment_provider` setting
- Calls `grebo_deposit()` if provider is 'grebo'
- Stores provider in `selcom_message` column for identification
- Marks payment as pending until webhook arrives

#### `api/poll-payment.php`
- Skips Selcom query if payment was initiated via Grebo
- For Grebo: payment status only updates via webhook
- Clients poll `/api/poll-payment.php` to check for unlock_token

#### `admin/payments.php`
- Displays "Provider" column (Selcom / Grebo)
- Shows provider + resultcode/message for each payment
- Detects provider by checking `selcom_message` or `selcom_reference` format

#### `install.sql`
- Added default settings for payment_provider and Grebo fields:
  - `payment_provider` → 'selcom' (default)
  - `grebo_base_url` → 'https://grebo.tesloty.com'
  - `grebo_api_key` → '' (empty, to be filled by admin)
  - `grebo_webhook_secret` → '' (empty, to be filled by admin)

## Database Compatibility

No schema changes. The integration reuses existing `payments` table columns:
- `selcom_reference` → Grebo transaction ID
- `selcom_resultcode` → Grebo status ('completed', 'failed', etc.)
- `selcom_message` → 'grebo' (provider identifier) or error JSON
- `unlock_token` → Generated on payment confirmation (same as Selcom)
- `status` → pending / paid / failed (same states)

## Admin Configuration Steps

1. **Go to Admin → Settings → Grebo API**
2. **Set:**
   - **Base URL**: `https://grebo.tesloty.com` (or your custom URL — admin can change anytime)
   - **API Key**: Paste from [https://tesloty.lovable.app/dashboard/api-keys](https://tesloty.lovable.app/dashboard/api-keys)
   - **Webhook secret**: Strong secret string (shared with Grebo dashboard)

3. **Go to Admin → Settings → Selcom API**
   - Set **Payment provider** to `Grebo` (or `Selcom` to use Selcom)

4. **In Grebo Dashboard:**
   - Set webhook URL to: `[YOUR_SITE]/api/grebo-webhook.php`
   - Set webhook secret to match the one in step 2

## Testing the Integration

### Option 1: PHP CLI Test (Recommended for local)
```bash
cd /path/to/xmapenzi-php
php test-grebo-webhook.php XMP20260531ABC123 your_webhook_secret
```

### Option 2: HTTP/Curl Test (Recommended for live servers)
```bash
bash test-webhook.sh https://xmapenzi.flatbet.online XMP20260531ABC123 your_webhook_secret
```

See `GREBO_TESTING.md` for detailed testing guide.

## API Flow

### Initiate Payment
```
POST /api/initiate-payment.php
{
  "itemType": "video",
  "itemId": "...",
  "msisdn": "255712345678"
}

Response (if provider='grebo'):
{
  "reference": "XMP20260531ABC123",
  "message": "Angalia simu yako...",
  "amount": 1000,
  "label": "Video Title"
}
```

### Poll for Status
```
POST /api/poll-payment.php
{"reference": "XMP20260531ABC123"}

Response (pending):
{"status": "pending"}

Response (completed):
{"status": "paid", "unlock_token": "def456..."}
```

### Webhook (Grebo → Server)
```
POST /api/grebo-webhook.php
Headers:
  X-Webhook-Signature: <hmac-sha256>
  X-Webhook-Timestamp: <iso8601>
  Content-Type: application/json

Body:
{
  "event": "transaction.completed",
  "data": {
    "id": "tx_...",
    "status": "completed",
    "reference": "XMP20260531ABC123",
    ...
  }
}
```

## URL Configuration

- **Grebo Base URL**: Configurable via Admin → Settings → Grebo API → "Base URL"
  - Defaults to `https://grebo.tesloty.com`
  - Can be changed to `https://api.grebo.co.tz` or any custom URL without code changes
  - Value is read from database, not hardcoded

## Production Checklist

- [ ] Get Grebo API key from [https://tesloty.lovable.app/dashboard/api-keys](https://tesloty.lovable.app/dashboard/api-keys)
- [ ] Set strong webhook secret in Admin Settings
- [ ] Configure webhook URL in Grebo dashboard
- [ ] Test with test key (`grb_test_...`) first
- [ ] Switch to production key (`grb_live_...`) after approval
- [ ] Monitor webhook delivery logs
- [ ] Keep Grebo Base URL configurable (for future changes or failover)

## Files Overview

```
xmapenzi-php/
├── includes/
│   ├── grebo.php          ← NEW: Grebo API helpers
│   ├── selcom.php         ← UNCHANGED
│   ├── db.php
│   ├── auth.php
│   └── config.php
├── api/
│   ├── grebo-webhook.php  ← NEW: Webhook endpoint
│   ├── initiate-payment.php ← MODIFIED: routes to Grebo
│   ├── poll-payment.php   ← MODIFIED: skips Selcom for Grebo
│   ├── selcom-webhook.php ← UNCHANGED
│   └── ...
├── admin/
│   ├── settings.php       ← MODIFIED: Grebo config UI
│   ├── payments.php       ← MODIFIED: shows provider
│   └── ...
├── test-grebo-webhook.php ← NEW: CLI test
├── test-webhook.sh        ← NEW: HTTP test
├── GREBO_TESTING.md       ← NEW: Testing guide
├── install.sql            ← MODIFIED: default settings
├── README.md
└── ...
```

## Support

- Grebo docs: https://tesloty.lovable.app/docs
- Grebo API: https://grebo.tesloty.com/api/v1
- Dashboard: https://tesloty.lovable.app/dashboard
