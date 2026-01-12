#!/bin/bash
set -e

# Load environment
source .env

echo "Building for Linux..."
# Use Docker to build Linux binary with CGO
docker run --rm -v "$(pwd)":/app -w /app golang:1.22-alpine sh -c "
  apk add --no-cache gcc musl-dev sqlite-dev git &&
  CGO_ENABLED=1 go build -o bot ./cmd/bot
"

echo "Creating deployment package..."
tar -czvf deploy.tar.gz bot

echo "Uploading to sprite..."
# For now, manual upload via scp when SSH is working
# scp deploy.tar.gz centromex-bot@sprites.dev:/home/sprite/centromex/

echo ""
echo "Manual steps (SSH is currently having issues):"
echo "1. When SSH works: scp deploy.tar.gz centromex-bot@sprites.dev:/home/sprite/centromex/"
echo "2. SSH in and run: cd /home/sprite/centromex && tar -xzf deploy.tar.gz && pkill -f ./bot; ./bot &"
echo ""
echo "Or use the Sprites web console if available."

rm deploy.tar.gz
echo "Done!"
