#!/bin/bash
set -euo pipefail

# Sprites deployment script for Centromex Bot
# This script sets up a Fly.io Sprite with the bot and LLM model

SPRITE_NAME="${SPRITE_NAME:-centromex-bot}"

echo "=== Centromex Bot - Sprites Deployment ==="
echo ""

# Check for sprites token
if [ -z "${SPRITES_TOKEN:-}" ]; then
    echo "SPRITES_TOKEN environment variable is required."
    echo "Get your token from https://sprites.dev"
    exit 1
fi

# Check for required config
if [ -z "${TELEGRAM_BOT_TOKEN:-}" ]; then
    read -p "Enter TELEGRAM_BOT_TOKEN: " TELEGRAM_BOT_TOKEN
fi

if [ -z "${VOLUNTEER_CHAT_ID:-}" ]; then
    read -p "Enter VOLUNTEER_CHAT_ID: " VOLUNTEER_CHAT_ID
fi

if [ -z "${COORDINATOR_IDS:-}" ]; then
    read -p "Enter COORDINATOR_IDS (comma-separated): " COORDINATOR_IDS
fi

# Generate encryption key if not provided
if [ -z "${DB_ENCRYPTION_KEY:-}" ]; then
    DB_ENCRYPTION_KEY=$(openssl rand -base64 32)
    echo "Generated DB_ENCRYPTION_KEY: $DB_ENCRYPTION_KEY"
    echo "(Save this somewhere safe!)"
fi

API_URL="https://api.sprites.dev/v1"

# Create sprite if it doesn't exist
echo ""
echo "Creating sprite: $SPRITE_NAME"
SPRITE_RESPONSE=$(curl -s -X POST "$API_URL/sprites" \
    -H "Authorization: Bearer $SPRITES_TOKEN" \
    -H "Content-Type: application/json" \
    -d "{\"name\":\"$SPRITE_NAME\"}" || true)

echo "Sprite response: $SPRITE_RESPONSE"

# Get sprite URL
SPRITE_URL=$(echo "$SPRITE_RESPONSE" | jq -r '.url // empty')
if [ -z "$SPRITE_URL" ]; then
    # Sprite might already exist, try to get it
    SPRITE_RESPONSE=$(curl -s "$API_URL/sprites/$SPRITE_NAME" \
        -H "Authorization: Bearer $SPRITES_TOKEN")
    SPRITE_URL=$(echo "$SPRITE_RESPONSE" | jq -r '.url // empty')
fi

echo "Sprite URL: $SPRITE_URL"

# Function to run commands on the sprite
run_on_sprite() {
    local cmd="$1"
    curl -s -X POST "$API_URL/sprites/$SPRITE_NAME/exec?cmd=bash" \
        -H "Authorization: Bearer $SPRITES_TOKEN" \
        -H "Content-Type: text/plain" \
        --data-binary "$cmd"
}

echo ""
echo "Setting up sprite environment..."

# Install dependencies
run_on_sprite "apt-get update && apt-get install -y golang-go cmake build-essential git curl"

# Create directories
run_on_sprite "mkdir -p /app /data /models"

# Set environment variables (stored in sprite)
run_on_sprite "cat > /app/.env << 'ENVEOF'
TELEGRAM_BOT_TOKEN=$TELEGRAM_BOT_TOKEN
VOLUNTEER_CHAT_ID=$VOLUNTEER_CHAT_ID
COORDINATOR_IDS=$COORDINATOR_IDS
DB_ENCRYPTION_KEY=$DB_ENCRYPTION_KEY
DB_PATH=/data/centromex.db
MODEL_PATH=/models/llama-3.2-3b.Q4_K_M.gguf
WEBHOOK_URL=$SPRITE_URL
ENVEOF"

echo ""
echo "Uploading source code..."

# Create a tarball of the source and upload
tar -czf /tmp/centromex-src.tar.gz -C "$(dirname "$0")/.." \
    --exclude='.git' \
    --exclude='models' \
    --exclude='*.db' \
    --exclude='.env' \
    .

# Upload via exec with stdin
curl -s -X POST "$API_URL/sprites/$SPRITE_NAME/exec?cmd=tar&args=-xzf%20-%20-C%20/app&stdin=true" \
    -H "Authorization: Bearer $SPRITES_TOKEN" \
    -H "Content-Type: application/octet-stream" \
    --data-binary @/tmp/centromex-src.tar.gz

rm /tmp/centromex-src.tar.gz

echo ""
echo "Building bot..."
run_on_sprite "cd /app && CGO_ENABLED=1 go build -o /app/bot ./cmd/bot"

echo ""
echo "Downloading LLM model (this will take a while)..."
run_on_sprite "curl -L -o /models/llama-3.2-3b.Q4_K_M.gguf \
    'https://huggingface.co/lmstudio-community/Llama-3.2-3B-Instruct-GGUF/resolve/main/Llama-3.2-3B-Instruct-Q4_K_M.gguf'"

echo ""
echo "Creating systemd service..."
run_on_sprite "cat > /etc/systemd/system/centromex-bot.service << 'SVCEOF'
[Unit]
Description=Centromex Grocery Bot
After=network.target

[Service]
Type=simple
WorkingDirectory=/app
EnvironmentFile=/app/.env
ExecStart=/app/bot
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
SVCEOF"

run_on_sprite "systemctl daemon-reload && systemctl enable centromex-bot && systemctl start centromex-bot"

echo ""
echo "Creating checkpoint..."
curl -s -X POST "$API_URL/sprites/$SPRITE_NAME/checkpoints" \
    -H "Authorization: Bearer $SPRITES_TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"name":"initial-setup"}'

echo ""
echo "=== Deployment Complete ==="
echo ""
echo "Sprite URL: $SPRITE_URL"
echo "Webhook URL for Telegram: ${SPRITE_URL}/webhook"
echo ""
echo "To set webhook, run:"
echo "  curl 'https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/setWebhook?url=${SPRITE_URL}/webhook'"
echo ""
echo "To check status:"
echo "  curl -H 'Authorization: Bearer $SPRITES_TOKEN' '$API_URL/sprites/$SPRITE_NAME'"
echo ""
echo "To view logs:"
echo "  Run: scripts/sprite-logs.sh"
