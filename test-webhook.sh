#!/bin/bash
# Test Grebo webhook endpoint with curl
# Usage: ./test-webhook.sh <SITE_URL> <REFERENCE> <WEBHOOK_SECRET>
# Example: ./test-webhook.sh https://xmapenzi.flatbet.online XMP20260531123456ABC123456 test_secret

if [ "$#" -lt 3 ]; then
    echo "Usage: $0 <SITE_URL> <REFERENCE> <WEBHOOK_SECRET>"
    echo "Example: $0 https://xmapenzi.flatbet.online XMP20260531123456ABC123456 test_secret"
    exit 1
fi

SITE_URL="$1"
REFERENCE="$2"
SECRET="$3"

WEBHOOK_URL="$SITE_URL/api/grebo-webhook.php"

# Generate transaction ID
TX_ID="tx_$(openssl rand -hex 8)"

# Create payload
PAYLOAD=$(cat <<EOF
{
  "event": "transaction.completed",
  "data": {
    "id": "$TX_ID",
    "type": "deposit",
    "method": "mobile",
    "amount_tzs": 1000,
    "status": "completed",
    "reference": "$REFERENCE",
    "completed_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  }
}
EOF
)

# Sign payload using Grebo's documented scheme: HMAC-SHA256("${timestamp}.${rawBody}")
TIMESTAMP=$(date -u +%Y-%m-%dT%H:%M:%SZ)
SIGNATURE=$(printf '%s.%s' "$TIMESTAMP" "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -hex | cut -d ' ' -f2)

echo "=== Grebo Webhook Curl Test ==="
echo "URL: $WEBHOOK_URL"
echo "Reference: $REFERENCE"
echo "Signature: $SIGNATURE"
echo "Payload:"
echo "$PAYLOAD" | jq .
echo ""

# Send webhook
echo "Sending webhook..."
curl -X POST "$WEBHOOK_URL" \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Signature: $SIGNATURE" \
  -H "X-Webhook-Timestamp: $TIMESTAMP" \
  -d "$PAYLOAD" \
  -v

echo ""
echo "Done."
