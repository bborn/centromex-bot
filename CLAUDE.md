# CLAUDE.md - AI Assistant Guide for Centromex Bot

## Project Overview

Centromex Bot is a multi-component system supporting a Hispanic grocery store and mutual aid community in St. Paul, Minnesota. It consists of:

1. **Telegram Bot (Go)**: Coordinates grocery delivery requests between families and volunteers
2. **WooCommerce Store (PHP/WordPress)**: Online ordering with AI-powered product catalog generation
3. **Image Processing Pipeline (Ruby)**: Batch processing of shelf photos to generate product catalogs

## Repository Structure

```
centromex-bot/
├── cmd/bot/main.go              # Go bot entry point
├── internal/                    # Go application code
│   ├── bot/bot.go               # Telegram bot logic (handlers, commands)
│   ├── db/db.go                 # SQLite database operations
│   ├── models/models.go         # Data structures
│   └── translator/translator.go # OpenAI translation service
├── ruby/                        # Image processing pipeline
│   ├── product_importer.rb      # ML pipeline for product detection
│   ├── generate_csv.rb          # CSV generation for WooCommerce
│   └── update_prices.rb         # Price updates from Open Food Facts
├── woocommerce-store/           # WordPress/WooCommerce setup
│   ├── docker-compose.yml       # Local development services
│   ├── wp-content/plugins/centromex-grocery/  # Custom plugin
│   └── README.md                # WooCommerce setup guide
├── scripts/
│   ├── setup.sh                 # Local development setup
│   └── sprite-setup.sh          # Production deployment
├── .github/workflows/deploy.yml # CI/CD pipeline
└── Dockerfile                   # Go app containerization
```

## Technology Stack

### Go Application (Telegram Bot)
- **Go 1.22** with CGO enabled (required for SQLite)
- **Dependencies** (minimal):
  - `github.com/go-telegram-bot-api/telegram-bot-api/v5` - Telegram API
  - `github.com/mattn/go-sqlite3` - SQLite with encryption support

### WooCommerce (E-commerce)
- WordPress with WooCommerce plugin
- MySQL 8.0, Redis 7 (optional caching)
- Custom `centromex-grocery` plugin for photo import

### Ruby Scripts (Image Processing)
- Gemini API for product identification
- Open Food Facts API for validation
- Replicate API for image segmentation/upscaling

### External APIs
- **Telegram Bot API** - Bot communication
- **OpenAI API** - Spanish→English translation (GPT-4o-mini)
- **Google Gemini API** - Product identification from images
- **Replicate API** - ML models (SAM-2, image upscaling)
- **Open Food Facts API** - Product validation (free)

## Quick Commands

### Build and Run (Go Bot)
```bash
# First-time setup
./scripts/setup.sh

# Build
go build -o ./bot ./cmd/bot

# Run locally (polling mode)
source .env && ./bot
```

### WooCommerce Development
```bash
cd woocommerce-store
docker-compose up -d
# Access: http://localhost:8080
# Admin: http://localhost:8080/wp-admin (admin/changeme123)
```

### Deploy (Production)
Deployment happens automatically via GitHub Actions on push to `main`. The workflow:
1. Deploys Go bot to Sprites.dev
2. Deploys WordPress plugin to Sprites WordPress instance

## Environment Variables

Required for production:
```
TELEGRAM_BOT_TOKEN      # From @BotFather
VOLUNTEER_CHAT_ID       # Telegram group ID for volunteers
COORDINATOR_IDS         # Comma-separated admin user IDs
DB_ENCRYPTION_KEY       # openssl rand -base64 32
OPENAI_API_KEY          # For translation
GEMINI_API_KEY          # For product identification
REPLICATE_API_TOKEN     # For image processing
```

Optional:
```
DB_PATH                 # Default: ./data/centromex.db
WEBHOOK_URL             # If set: webhook mode; else: polling
WEBHOOK_SECRET          # For webhook verification
```

## Key Patterns and Conventions

### Request Lifecycle (Telegram Bot)
```
new → posted → claimed → shopping → delivered → [auto-deleted 48h]
                        → cancelled
```

### Privacy-First Design
- Addresses extracted during translation, stored separately
- Addresses auto-deleted after delivery (48h)
- Encrypted SQLite database
- PII never exposed in volunteer group messages

### Database Schema (SQLite)
- `requests` - Delivery requests with status tracking
- `volunteers` - Approved volunteer management
- `addresses` - Sensitive address data (auto-purged)

### Code Style
- Go: Standard library patterns, minimal dependencies
- PHP: WordPress plugin conventions, PSR-4 autoloading
- Ruby: Functional style, thread pools for concurrency

## Important Files

| File | Purpose |
|------|---------|
| `internal/bot/bot.go` | Core bot logic, all Telegram handlers |
| `internal/db/db.go` | All database operations |
| `internal/translator/translator.go` | OpenAI integration for translation |
| `ruby/product_importer.rb` | ML pipeline for product detection |
| `woocommerce-store/wp-content/plugins/centromex-grocery/includes/class-photo-importer.php` | WP photo import logic |
| `photo-import-plan.md` | Detailed architecture documentation |

## Testing

### Go Bot
- No formal test suite currently
- Health endpoint: `/health` returns 200 OK
- Manual testing via Telegram groups

### Ruby Scripts
- `test_products.rb` for price update testing
- Direct script execution for integration testing

### WooCommerce
- Docker Compose for local development
- Manual UI testing in browser

## Deployment

### Production Environment
- **Bot**: `centromex-bot-74l.sprites.app`
- **Health Check**: `https://centromex-bot-74l.sprites.app/health`
- **WordPress**: Separate Sprites instance (`centromex-grocery`)

### GitHub Actions (`.github/workflows/deploy.yml`)
Triggered on push to `main`:
1. Stops existing bot process
2. Pulls latest code, rebuilds
3. Starts bot with environment variables
4. Verifies health endpoint
5. Deploys WordPress plugin

## Common Tasks

### Adding a New Bot Command
1. Edit `internal/bot/bot.go`
2. Add command handler in the message processing switch
3. Register command with BotFather if user-facing

### Modifying Database Schema
1. Edit `internal/db/db.go` - update `initSchema()` and related queries
2. Consider migration path for existing data

### Updating Product Processing
1. Ruby pipeline: Edit `ruby/product_importer.rb`
2. WordPress plugin: Edit `includes/class-photo-importer.php`

### Adding Translations
1. All translation happens in `internal/translator/translator.go`
2. Uses OpenAI GPT-4o-mini with structured JSON output
3. Extracts address/phone during translation

## Architecture Notes

### Translation Pipeline
```
Spanish text → OpenAI GPT-4o-mini → {
  translated_text: "English translation",
  address: "Extracted address or null",
  phone: "Extracted phone or null"
}
```

### Image Processing Pipeline
```
Shelf photo → SAM-2 segmentation → Product crops
→ Gemini identification → Open Food Facts validation
→ WooCommerce product CSV
```

### Bot Modes
- **Polling Mode** (local dev): Bot polls Telegram for updates
- **Webhook Mode** (production): Telegram pushes updates to bot

## Troubleshooting

### Bot not responding
1. Check health endpoint: `curl https://centromex-bot-74l.sprites.app/health`
2. View logs: `sprite -s centromex-bot exec tail -f /home/sprite/centromex/bot.log`

### Database issues
- Database is encrypted with `DB_ENCRYPTION_KEY`
- Located at `DB_PATH` (default: `./data/centromex.db`)

### WooCommerce issues
- Check Docker logs: `docker-compose logs wordpress`
- Plugin errors in WordPress admin: Tools → Site Health

## Contributing Guidelines

1. Keep dependencies minimal - prefer standard library
2. Maintain privacy-first approach for user data
3. Test locally before pushing to main (auto-deploys)
4. Document API changes in this file
5. Ruby/PHP code should match existing style patterns
