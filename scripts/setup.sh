#!/bin/bash
set -euo pipefail

echo "=== Centromex Bot - Local Setup ==="
echo ""

# Check for required tools
command -v go >/dev/null 2>&1 || { echo "Go is required but not installed. Aborting." >&2; exit 1; }

# Create directories
mkdir -p ./data ./models

# Create .env file if it doesn't exist
if [ ! -f ".env" ]; then
    echo "Creating .env file..."
    cat > .env << 'EOF'
# Telegram Bot Token (get from @BotFather)
TELEGRAM_BOT_TOKEN=your_bot_token_here

# Telegram Chat ID for volunteer group
# (add bot to group, send a message, then check:
#  curl https://api.telegram.org/bot<TOKEN>/getUpdates)
VOLUNTEER_CHAT_ID=-1001234567890

# Comma-separated Telegram user IDs for coordinators
COORDINATOR_IDS=123456789

# Database encryption key (generate with: openssl rand -base64 32)
DB_ENCRYPTION_KEY=your_encryption_key_here

# Paths (for local development)
DB_PATH=./data/centromex.db
MODEL_PATH=./models/llama-3.2-3b.Q4_K_M.gguf

# Leave empty for polling mode (local dev)
# Set to sprite URL for webhook mode (production)
WEBHOOK_URL=
EOF

    echo ""
    echo "Created .env - edit it with your config before running."
    echo ""
else
    echo ".env already exists"
fi

# Download model if not present
MODEL_FILE="./models/llama-3.2-3b.Q4_K_M.gguf"
if [ ! -f "$MODEL_FILE" ]; then
    echo ""
    echo "LLM model not found at $MODEL_FILE"
    echo ""
    read -p "Download Llama 3.2 3B model (~2GB)? [y/N] " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo "Downloading model (this will take a while)..."
        curl -L -o "$MODEL_FILE" \
            "https://huggingface.co/lmstudio-community/Llama-3.2-3B-Instruct-GGUF/resolve/main/Llama-3.2-3B-Instruct-Q4_K_M.gguf"
        echo "Model downloaded."
    else
        echo "Skipping model download. You'll need to provide your own."
    fi
fi

# Build
echo ""
echo "Building bot..."
go build -o ./bot ./cmd/bot

echo ""
echo "=== Setup Complete ==="
echo ""
echo "To run locally:"
echo "  1. Edit .env with your Telegram bot token and other config"
echo "  2. Run: source .env && ./bot"
echo ""
echo "To deploy to Sprites:"
echo "  1. Get a token from https://sprites.dev"
echo "  2. Run: SPRITES_TOKEN=xxx ./scripts/sprite-setup.sh"
