package main

import (
	"log"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/centromex/grocery-bot/internal/bot"
	"github.com/centromex/grocery-bot/internal/db"
	"github.com/centromex/grocery-bot/internal/translator"
)

func main() {
	log.Println("Starting Centromex Grocery Bot...")

	// Load configuration from environment
	config := loadConfig()

	// Initialize database
	log.Println("Initializing database...")
	database, err := db.New(config.DBPath, config.DBKey)
	if err != nil {
		log.Fatalf("Failed to initialize database: %v", err)
	}
	defer database.Close()

	// Initialize translator
	log.Println("Initializing translator...")
	trans, err := translator.New(translator.Config{
		ModelPath:   config.ModelPath,
		ContextSize: 2048,
		Threads:     4,
		OpenAIKey:   config.OpenAIKey,
	})
	if err != nil {
		log.Fatalf("Failed to initialize translator: %v", err)
	}
	defer trans.Close()

	// Initialize bot
	log.Println("Starting Telegram bot...")
	telegramBot, err := bot.New(bot.Config{
		Token:          config.TelegramToken,
		VolunteerChat:  config.VolunteerChat,
		CoordinatorIDs: config.CoordinatorIDs,
		WebhookURL:     config.WebhookURL,
		WebhookSecret:  config.WebhookSecret,
	}, database, trans)
	if err != nil {
		log.Fatalf("Failed to initialize bot: %v", err)
	}

	// Start background cleanup job
	go func() {
		ticker := time.NewTicker(1 * time.Hour)
		defer ticker.Stop()
		for range ticker.C {
			purged, err := database.PurgeOldRequests(48 * time.Hour)
			if err != nil {
				log.Printf("Error purging old requests: %v", err)
			} else if purged > 0 {
				log.Printf("Purged %d old requests", purged)
			}
		}
	}()

	log.Println("Bot is running. Press Ctrl+C to stop.")

	// Run the bot (blocks until shutdown)
	if err := telegramBot.Run(); err != nil {
		log.Fatalf("Bot error: %v", err)
	}
}

type Config struct {
	TelegramToken  string
	VolunteerChat  int64
	CoordinatorIDs []int64
	DBPath         string
	DBKey          string
	ModelPath      string
	WebhookURL     string
	WebhookSecret  string
	OpenAIKey      string
}

func loadConfig() Config {
	config := Config{
		TelegramToken: mustGetEnv("TELEGRAM_BOT_TOKEN"),
		DBPath:        getEnvOrDefault("DB_PATH", "./data/centromex.db"),
		DBKey:         mustGetEnv("DB_ENCRYPTION_KEY"),
		ModelPath:     getEnvOrDefault("MODEL_PATH", "./models/llama-3.2-3b.Q4_K_M.gguf"),
		WebhookURL:    os.Getenv("WEBHOOK_URL"),    // Optional - if set, uses webhook mode
		WebhookSecret: os.Getenv("WEBHOOK_SECRET"), // Secret token for webhook verification
		OpenAIKey:     os.Getenv("OPENAI_API_KEY"), // Optional - for translation
	}

	// Parse volunteer chat ID
	volunteerChatStr := mustGetEnv("VOLUNTEER_CHAT_ID")
	volunteerChat, err := strconv.ParseInt(volunteerChatStr, 10, 64)
	if err != nil {
		log.Fatalf("Invalid VOLUNTEER_CHAT_ID: %v", err)
	}
	config.VolunteerChat = volunteerChat

	// Parse coordinator IDs (comma-separated)
	coordinatorStr := mustGetEnv("COORDINATOR_IDS")
	for _, idStr := range strings.Split(coordinatorStr, ",") {
		idStr = strings.TrimSpace(idStr)
		if idStr == "" {
			continue
		}
		id, err := strconv.ParseInt(idStr, 10, 64)
		if err != nil {
			log.Fatalf("Invalid coordinator ID '%s': %v", idStr, err)
		}
		config.CoordinatorIDs = append(config.CoordinatorIDs, id)
	}

	if len(config.CoordinatorIDs) == 0 {
		log.Fatal("At least one coordinator ID is required")
	}

	return config
}

func mustGetEnv(key string) string {
	value := os.Getenv(key)
	if value == "" {
		log.Fatalf("Required environment variable %s is not set", key)
	}
	return value
}

func getEnvOrDefault(key, defaultValue string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return defaultValue
}
