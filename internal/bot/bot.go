package bot

import (
	"fmt"
	"log"
	"regexp"
	"strconv"
	"strings"

	tgbotapi "github.com/go-telegram-bot-api/telegram-bot-api/v5"

	"github.com/centromex/grocery-bot/internal/db"
	"github.com/centromex/grocery-bot/internal/models"
	"github.com/centromex/grocery-bot/internal/translator"
)

type Bot struct {
	api            *tgbotapi.BotAPI
	db             *db.DB
	translator     *translator.Translator
	volunteerChat  int64 // Telegram chat ID for volunteer group
	coordinatorIDs []int64
}

type Config struct {
	Token         string
	VolunteerChat int64
	CoordinatorIDs []int64
}

func New(cfg Config, database *db.DB, trans *translator.Translator) (*Bot, error) {
	api, err := tgbotapi.NewBotAPI(cfg.Token)
	if err != nil {
		return nil, fmt.Errorf("failed to create bot: %w", err)
	}

	log.Printf("Authorized on account %s", api.Self.UserName)

	return &Bot{
		api:            api,
		db:             database,
		translator:     trans,
		volunteerChat:  cfg.VolunteerChat,
		coordinatorIDs: cfg.CoordinatorIDs,
	}, nil
}

func (b *Bot) Run() error {
	u := tgbotapi.NewUpdate(0)
	u.Timeout = 60

	updates := b.api.GetUpdatesChan(u)

	for update := range updates {
		if update.Message == nil {
			continue
		}

		if update.Message.IsCommand() {
			b.handleCommand(update.Message)
		} else {
			b.handleMessage(update.Message)
		}
	}

	return nil
}

func (b *Bot) handleCommand(msg *tgbotapi.Message) {
	userID := msg.From.ID

	switch msg.Command() {
	case "start":
		b.sendMessage(msg.Chat.ID, "Welcome to Centromex Grocery Coordination Bot!\n\n"+
			"Commands:\n"+
			"/list - See open requests\n"+
			"/claim <id> - Claim a request\n"+
			"/mine - See your claimed requests\n"+
			"/done <id> - Mark a request as delivered\n"+
			"/cancel <id> - Cancel your claim\n"+
			"/help - Show this help message")

	case "help":
		b.sendMessage(msg.Chat.ID, "Commands:\n"+
			"/list - See open requests\n"+
			"/claim <id> - Claim a request\n"+
			"/mine - See your claimed requests\n"+
			"/done <id> - Mark a request as delivered\n"+
			"/cancel <id> - Cancel your claim\n\n"+
			"Coordinators:\n"+
			"/new <text> - Create a new request\n"+
			"/status - See all request statuses")

	case "list":
		b.handleList(msg)

	case "claim":
		b.handleClaim(msg, userID)

	case "mine":
		b.handleMine(msg, userID)

	case "done":
		b.handleDone(msg, userID)

	case "cancel":
		b.handleCancel(msg, userID)

	case "new":
		b.handleNew(msg, userID)

	case "status":
		b.handleStatus(msg, userID)

	case "approve":
		b.handleApprove(msg, userID)

	default:
		b.sendMessage(msg.Chat.ID, "Unknown command. Use /help to see available commands.")
	}
}

func (b *Bot) handleMessage(msg *tgbotapi.Message) {
	// Check if this is a coordinator forwarding a request
	if b.isCoordinator(msg.From.ID) && msg.ForwardDate != 0 {
		// This is a forwarded message from coordinator - treat as new request
		b.createRequest(msg.Chat.ID, msg.Text, "", "")
		return
	}

	// For non-command messages from non-coordinators, just acknowledge
	b.sendMessage(msg.Chat.ID, "Use /help to see available commands.")
}

func (b *Bot) handleList(msg *tgbotapi.Message) {
	requests, err := b.db.GetOpenRequests()
	if err != nil {
		b.sendMessage(msg.Chat.ID, "Error fetching requests. Please try again.")
		log.Printf("Error fetching open requests: %v", err)
		return
	}

	if len(requests) == 0 {
		b.sendMessage(msg.Chat.ID, "No open requests at the moment. Check back later!")
		return
	}

	var sb strings.Builder
	sb.WriteString(fmt.Sprintf("📋 OPEN REQUESTS (%d)\n\n", len(requests)))

	for _, req := range requests {
		sb.WriteString(fmt.Sprintf("━━━ #%d ", req.ID))
		if req.Zone != "" {
			sb.WriteString(fmt.Sprintf("• %s ", req.Zone))
		}
		if req.Budget != "" {
			sb.WriteString(fmt.Sprintf("• %s", req.Budget))
		}
		sb.WriteString("\n")

		// Show truncated list
		lines := strings.Split(req.TranslatedText, "\n")
		shown := 0
		for _, line := range lines {
			if strings.HasPrefix(line, "•") && shown < 3 {
				sb.WriteString(line + "\n")
				shown++
			}
		}
		if shown < countItems(req.TranslatedText) {
			sb.WriteString(fmt.Sprintf("   ...and %d more items\n", countItems(req.TranslatedText)-shown))
		}
		sb.WriteString(fmt.Sprintf("→ /claim %d\n\n", req.ID))
	}

	b.sendMessage(msg.Chat.ID, sb.String())
}

func (b *Bot) handleClaim(msg *tgbotapi.Message, userID int64) {
	// Check if volunteer is approved
	approved, err := b.db.IsVolunteerApproved(userID)
	if err != nil {
		log.Printf("Error checking volunteer approval: %v", err)
	}
	if !approved && !b.isCoordinator(userID) {
		b.sendMessage(msg.Chat.ID, "You're not yet approved as a volunteer. Please contact a coordinator.")
		return
	}

	// Parse request ID
	requestID, err := parseID(msg.CommandArguments())
	if err != nil {
		b.sendMessage(msg.Chat.ID, "Usage: /claim <request_id>\nExample: /claim 42")
		return
	}

	// Get volunteer name
	volunteerName := msg.From.FirstName
	if msg.From.LastName != "" {
		volunteerName += " " + msg.From.LastName
	}

	// Claim the request
	err = b.db.ClaimRequest(requestID, userID, volunteerName)
	if err != nil {
		b.sendMessage(msg.Chat.ID, fmt.Sprintf("Could not claim request #%d: %s", requestID, err.Error()))
		return
	}

	// Get request details
	req, err := b.db.GetRequest(requestID)
	if err != nil {
		b.sendMessage(msg.Chat.ID, "Request claimed, but error fetching details.")
		return
	}

	// Get address
	address, err := b.db.GetAddress(requestID)
	if err != nil {
		address = "Address not available - contact coordinator"
	}

	// Send confirmation with full details via DM
	response := fmt.Sprintf("✅ CLAIMED! Request #%d is yours.\n\n", requestID)
	response += fmt.Sprintf("📍 ADDRESS (private):\n%s\n\n", address)
	response += fmt.Sprintf("💵 BUDGET: %s\n\n", req.Budget)
	response += "SHOPPING LIST:\n"
	response += req.TranslatedText
	response += fmt.Sprintf("\n\n━━━━━━━━━━━━━━━━━━━━━━━━\nWhen done: /done %d", requestID)

	b.sendMessage(msg.Chat.ID, response)

	// Notify coordinator
	b.notifyCoordinators(fmt.Sprintf("✋ Request #%d claimed by %s", requestID, volunteerName))

	// Notify volunteer group
	b.sendMessage(b.volunteerChat, fmt.Sprintf("✋ Request #%d claimed by %s", requestID, volunteerName))
}

func (b *Bot) handleMine(msg *tgbotapi.Message, userID int64) {
	requests, err := b.db.GetVolunteerRequests(userID)
	if err != nil {
		b.sendMessage(msg.Chat.ID, "Error fetching your requests.")
		log.Printf("Error fetching volunteer requests: %v", err)
		return
	}

	if len(requests) == 0 {
		b.sendMessage(msg.Chat.ID, "You don't have any active claims. Use /list to see open requests.")
		return
	}

	var sb strings.Builder
	sb.WriteString("📋 YOUR CLAIMED REQUESTS\n\n")

	for _, req := range requests {
		sb.WriteString(fmt.Sprintf("━━━ #%d • %s\n", req.ID, req.Status))
		sb.WriteString(fmt.Sprintf("Budget: %s\n", req.Budget))
		sb.WriteString(fmt.Sprintf("→ /done %d when delivered\n\n", req.ID))
	}

	b.sendMessage(msg.Chat.ID, sb.String())
}

func (b *Bot) handleDone(msg *tgbotapi.Message, userID int64) {
	requestID, err := parseID(msg.CommandArguments())
	if err != nil {
		b.sendMessage(msg.Chat.ID, "Usage: /done <request_id>\nExample: /done 42")
		return
	}

	err = b.db.CompleteRequest(requestID, userID)
	if err != nil {
		b.sendMessage(msg.Chat.ID, fmt.Sprintf("Could not complete request #%d: %s", requestID, err.Error()))
		return
	}

	b.sendMessage(msg.Chat.ID, fmt.Sprintf("✅ Request #%d marked as delivered. Thank you for helping!", requestID))

	// Notify coordinator
	volunteerName := msg.From.FirstName
	b.notifyCoordinators(fmt.Sprintf("✅ Request #%d delivered by %s", requestID, volunteerName))

	// Notify volunteer group
	b.sendMessage(b.volunteerChat, fmt.Sprintf("✅ Request #%d delivered!", requestID))
}

func (b *Bot) handleCancel(msg *tgbotapi.Message, userID int64) {
	requestID, err := parseID(msg.CommandArguments())
	if err != nil {
		b.sendMessage(msg.Chat.ID, "Usage: /cancel <request_id>\nExample: /cancel 42")
		return
	}

	// For now, cancellation requires coordinator approval
	// Just notify the coordinator
	volunteerName := msg.From.FirstName
	b.notifyCoordinators(fmt.Sprintf("⚠️ %s wants to cancel claim on request #%d", volunteerName, requestID))
	b.sendMessage(msg.Chat.ID, "Cancellation request sent to coordinator. They will release the claim if appropriate.")
}

func (b *Bot) handleNew(msg *tgbotapi.Message, userID int64) {
	if !b.isCoordinator(userID) {
		b.sendMessage(msg.Chat.ID, "Only coordinators can create new requests.")
		return
	}

	text := msg.CommandArguments()
	if text == "" {
		b.sendMessage(msg.Chat.ID, "Usage: /new <spanish text>\n\nOr just forward a WhatsApp message to me.")
		return
	}

	b.createRequest(msg.Chat.ID, text, "", "")
}

func (b *Bot) createRequest(chatID int64, spanishText string, budget string, zone string) {
	// Extract budget if present in text
	if budget == "" {
		budget = extractBudget(spanishText)
	}

	// Create the request in DB
	req, err := b.db.CreateRequest(spanishText, budget, zone)
	if err != nil {
		b.sendMessage(chatID, "Error creating request. Please try again.")
		log.Printf("Error creating request: %v", err)
		return
	}

	b.sendMessage(chatID, fmt.Sprintf("📝 Request #%d created. Translating...", req.ID))

	// Translate using LLM
	translated, err := b.translator.TranslateRequest(spanishText)
	if err != nil {
		b.sendMessage(chatID, fmt.Sprintf("Error translating request #%d: %v", req.ID, err))
		log.Printf("Error translating request: %v", err)
		return
	}

	// Update with translation
	err = b.db.UpdateRequestTranslation(req.ID, translated)
	if err != nil {
		log.Printf("Error updating translation: %v", err)
	}

	// Format and post to volunteer channel
	formatted := b.translator.FormatRequest(req.ID, zone, budget, translated)
	b.sendMessage(b.volunteerChat, formatted)

	b.sendMessage(chatID, fmt.Sprintf("✅ Request #%d posted to volunteers.\n\n"+
		"Now send me the address (I'll store it securely and only share with the volunteer who claims).", req.ID))
}

func (b *Bot) handleStatus(msg *tgbotapi.Message, userID int64) {
	if !b.isCoordinator(userID) {
		b.sendMessage(msg.Chat.ID, "Only coordinators can view full status.")
		return
	}

	// Get counts by status
	// This is a simplified version - could be expanded
	open, _ := b.db.GetOpenRequests()

	b.sendMessage(msg.Chat.ID, fmt.Sprintf("📊 STATUS\n\nOpen requests: %d\n\nUse /list to see details.", len(open)))
}

func (b *Bot) handleApprove(msg *tgbotapi.Message, userID int64) {
	if !b.isCoordinator(userID) {
		b.sendMessage(msg.Chat.ID, "Only coordinators can approve volunteers.")
		return
	}

	args := msg.CommandArguments()
	if args == "" {
		b.sendMessage(msg.Chat.ID, "Usage: /approve <telegram_user_id>")
		return
	}

	volunteerID, err := strconv.ParseInt(args, 10, 64)
	if err != nil {
		b.sendMessage(msg.Chat.ID, "Invalid user ID. Must be a number.")
		return
	}

	err = b.db.AddVolunteer(volunteerID, "", "", true)
	if err != nil {
		b.sendMessage(msg.Chat.ID, "Error approving volunteer.")
		return
	}

	b.sendMessage(msg.Chat.ID, fmt.Sprintf("✅ Volunteer %d approved.", volunteerID))
}

func (b *Bot) sendMessage(chatID int64, text string) {
	msg := tgbotapi.NewMessage(chatID, text)
	_, err := b.api.Send(msg)
	if err != nil {
		log.Printf("Error sending message: %v", err)
	}
}

func (b *Bot) notifyCoordinators(text string) {
	for _, coordID := range b.coordinatorIDs {
		b.sendMessage(coordID, text)
	}
}

func (b *Bot) isCoordinator(userID int64) bool {
	for _, id := range b.coordinatorIDs {
		if id == userID {
			return true
		}
	}
	return false
}

// Helper functions

func parseID(args string) (int64, error) {
	args = strings.TrimSpace(args)
	if args == "" {
		return 0, fmt.Errorf("no ID provided")
	}
	return strconv.ParseInt(args, 10, 64)
}

func countItems(text string) int {
	count := 0
	for _, line := range strings.Split(text, "\n") {
		if strings.HasPrefix(strings.TrimSpace(line), "•") {
			count++
		}
	}
	return count
}

func extractBudget(text string) string {
	// Look for common budget patterns
	patterns := []string{
		`\$\d+`,
		`(?i)tengo\s+\$?\d+`,
		`(?i)pagar[eé]\s+con\s+\$?\d+`,
		`(?i)\d+\s+d[oó]lares`,
	}

	for _, pattern := range patterns {
		re := regexp.MustCompile(pattern)
		if match := re.FindString(text); match != "" {
			// Clean up and return
			numRe := regexp.MustCompile(`\d+`)
			if num := numRe.FindString(match); num != "" {
				return "$" + num + " cash"
			}
		}
	}

	return ""
}
