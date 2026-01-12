package bot

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"regexp"
	"strconv"
	"strings"

	tgbotapi "github.com/go-telegram-bot-api/telegram-bot-api/v5"

	"github.com/centromex/grocery-bot/internal/db"
	"github.com/centromex/grocery-bot/internal/translator"
)

type Bot struct {
	api            *tgbotapi.BotAPI
	db             *db.DB
	translator     *translator.Translator
	volunteerChat  int64 // Telegram chat ID for volunteer group
	coordinatorIDs []int64
	webhookURL     string
	webhookSecret  string
}

type Config struct {
	Token          string
	VolunteerChat  int64
	CoordinatorIDs []int64
	WebhookURL     string // If set, use webhook mode; otherwise use polling
	WebhookSecret  string // Secret token for webhook verification
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
		webhookURL:     cfg.WebhookURL,
		webhookSecret:  cfg.WebhookSecret,
	}, nil
}

// Run starts the bot in either webhook or polling mode
func (b *Bot) Run() error {
	if b.webhookURL != "" {
		return b.runWebhook()
	}
	return b.runPolling()
}

// runWebhook starts an HTTP server for Telegram webhook updates
func (b *Bot) runWebhook() error {
	// Set up the webhook with Telegram
	webhookConfig, err := tgbotapi.NewWebhook(b.webhookURL + "/webhook")
	if err != nil {
		return fmt.Errorf("failed to create webhook config: %w", err)
	}

	// Note: SecretToken requires newer telegram-bot-api version
	// For now, security relies on validating Telegram update format

	_, err = b.api.Request(webhookConfig)
	if err != nil {
		return fmt.Errorf("failed to set webhook: %w", err)
	}

	log.Printf("Webhook set to %s/webhook", b.webhookURL)

	// Set up HTTP handlers
	http.HandleFunc("/webhook", b.handleWebhook)
	http.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		w.Write([]byte("OK"))
	})

	log.Println("Starting webhook server on :8080")
	return http.ListenAndServe(":8080", nil)
}

// handleWebhook processes incoming Telegram updates via HTTP
func (b *Bot) handleWebhook(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	body, err := io.ReadAll(r.Body)
	if err != nil {
		log.Printf("Error reading webhook body: %v", err)
		http.Error(w, "Bad request", http.StatusBadRequest)
		return
	}

	var update tgbotapi.Update
	if err := json.Unmarshal(body, &update); err != nil {
		log.Printf("Error parsing webhook update: %v", err)
		http.Error(w, "Bad request", http.StatusBadRequest)
		return
	}

	// Process the update
	b.processUpdate(update)

	w.WriteHeader(http.StatusOK)
}

// runPolling uses long polling for updates (for local development)
func (b *Bot) runPolling() error {
	// Remove any existing webhook
	_, err := b.api.Request(tgbotapi.DeleteWebhookConfig{})
	if err != nil {
		log.Printf("Warning: could not remove webhook: %v", err)
	}

	u := tgbotapi.NewUpdate(0)
	u.Timeout = 60

	updates := b.api.GetUpdatesChan(u)

	log.Println("Starting polling mode")

	for update := range updates {
		b.processUpdate(update)
	}

	return nil
}

// processUpdate handles a single Telegram update
func (b *Bot) processUpdate(update tgbotapi.Update) {
	if update.Message == nil {
		return
	}

	// Check for new members joining the group
	if update.Message.NewChatMembers != nil {
		b.handleNewMembers(update.Message)
		return
	}

	if update.Message.IsCommand() {
		b.handleCommand(update.Message)
	} else {
		b.handleMessage(update.Message)
	}
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

	case "address":
		b.handleAddress(msg, userID)

	case "view":
		b.handleView(msg, userID)

	default:
		b.sendMessage(msg.Chat.ID, "Unknown command. Use /help to see available commands.")
	}
}

func (b *Bot) handleMessage(msg *tgbotapi.Message) {
	// Check if this is a coordinator forwarding a request
	if b.isCoordinator(msg.From.ID) && msg.ForwardDate != 0 {
		// This is a forwarded message from coordinator - treat as new request
		b.createRequest(msg.Chat.ID, msg.Text, "", "", "")
		return
	}

	// For non-command messages from non-coordinators, just acknowledge
	b.sendMessage(msg.Chat.ID, "Use /help to see available commands.")
}

// handleNewMembers sends a welcome message when someone joins the volunteer group
func (b *Bot) handleNewMembers(msg *tgbotapi.Message) {
	// Only send welcome in the volunteer group chat
	if msg.Chat.ID != b.volunteerChat {
		return
	}

	for _, member := range msg.NewChatMembers {
		// Skip if the bot itself joined
		if member.ID == b.api.Self.ID {
			continue
		}

		// Get the new member's name
		name := member.FirstName
		if name == "" {
			name = "there"
		}

		// Send welcome message to the group
		welcome := fmt.Sprintf(`Welcome %s! Thank you for joining Centromex Grocery Volunteers.

HOW THIS WORKS:
Families in our community need help getting groceries. When a request comes in, you'll see it posted here with a shopping list and budget.

COMMANDS:
/list - See open grocery requests
/claim <id> - Claim a request to shop for
/mine - See requests you've claimed
/done <id> - Mark a delivery as complete
/help - Full command list

QUICK START:
1. When you see a request, type /claim followed by the request number
2. You'll receive the address via DM (private message)
3. Shop for the items, deliver them, then type /done with the request number

Questions? Reach out to a coordinator. We're glad you're here!`, name)

		b.sendMessage(msg.Chat.ID, welcome)

		// Also register them as a volunteer (not yet approved)
		username := member.UserName
		displayName := member.FirstName
		if member.LastName != "" {
			displayName += " " + member.LastName
		}

		err := b.db.AddVolunteer(member.ID, username, displayName, false)
		if err != nil {
			log.Printf("Error registering new volunteer %d: %v", member.ID, err)
		}

		// Notify coordinators
		b.notifyCoordinators(fmt.Sprintf("New volunteer joined: %s (@%s, ID: %d)", displayName, username, member.ID))
	}
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
		sb.WriteString(fmt.Sprintf("━━━ #%d", req.ID))
		if req.Zone != "" {
			sb.WriteString(fmt.Sprintf(" • %s", req.Zone))
		}
		sb.WriteString("\n")

		if req.Budget != "" {
			sb.WriteString(fmt.Sprintf("💵 %s\n", req.Budget))
		}

		// Show preview of shopping list
		lines := strings.Split(req.TranslatedText, "\n")
		shown := 0
		const maxPreviewItems = 5

		for _, line := range lines {
			line = strings.TrimSpace(line)
			if line == "" {
				continue
			}

			// Show lines that start with bullet or are numbered
			if strings.HasPrefix(line, "•") || strings.HasPrefix(line, "-") ||
			   (len(line) > 2 && line[0] >= '0' && line[0] <= '9' && line[1] == '.') {
				if shown < maxPreviewItems {
					sb.WriteString(line + "\n")
					shown++
				}
			}
		}

		// If no formatted items found, show first non-empty line
		if shown == 0 {
			for _, line := range lines {
				line = strings.TrimSpace(line)
				if line != "" {
					preview := line
					if len(preview) > 60 {
						preview = preview[:60] + "..."
					}
					sb.WriteString(preview + "\n")
					break
				}
			}
		}

		totalItems := countItems(req.TranslatedText)
		if shown > 0 && shown < totalItems {
			sb.WriteString(fmt.Sprintf("   ...and %d more items\n", totalItems-shown))
		}

		sb.WriteString(fmt.Sprintf("\n→ /claim %d or /view %d for full list\n\n", req.ID, req.ID))
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

	// Send confirmation with full details via DM (not group!)
	response := fmt.Sprintf("✅ CLAIMED! Request #%d is yours.\n\n", requestID)
	response += fmt.Sprintf("📍 ADDRESS:\n%s\n\n", address)
	response += fmt.Sprintf("💵 BUDGET: %s\n\n", req.Budget)
	response += "SHOPPING LIST:\n"
	response += req.TranslatedText
	response += fmt.Sprintf("\n\n━━━━━━━━━━━━━━━━━━━━━━━━\nWhen done: /done %d", requestID)

	b.sendMessage(userID, response) // Send to user's DM

	// Acknowledge in group if that's where the claim was made
	if msg.Chat.ID != userID {
		b.sendMessage(msg.Chat.ID, fmt.Sprintf("✅ Request #%d claimed by %s. Details sent via DM.", requestID, volunteerName))
	}

	// Notify coordinator
	b.notifyCoordinators(fmt.Sprintf("✋ Request #%d claimed by %s", requestID, volunteerName))
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

	// Only allow /new via DM to protect family info
	if msg.Chat.ID != userID {
		b.sendMessage(msg.Chat.ID, "⚠️ Please send /new via DM to protect family information.")
		return
	}

	text := msg.CommandArguments()
	if text == "" {
		b.sendMessage(msg.Chat.ID, "Usage: /new <spanish grocery list>\n\nExample:\n/new 2 libras de arroz, 1 pollo, 3 aguacates")
		return
	}

	b.createRequest(msg.Chat.ID, text, "", "", "")
}

func (b *Bot) handleAddress(msg *tgbotapi.Message, userID int64) {
	if !b.isCoordinator(userID) {
		b.sendMessage(msg.Chat.ID, "Only coordinators can set addresses.")
		return
	}

	args := msg.CommandArguments()
	parts := strings.SplitN(args, " ", 2)
	if len(parts) < 2 {
		b.sendMessage(msg.Chat.ID, "Usage: /address <request_id> <address>\nExample: /address 1 123 Main St, St Paul MN 55101")
		return
	}

	requestID, err := strconv.ParseInt(parts[0], 10, 64)
	if err != nil {
		b.sendMessage(msg.Chat.ID, "Invalid request ID.")
		return
	}

	address := strings.TrimSpace(parts[1])
	err = b.db.SaveAddress(requestID, address)
	if err != nil {
		b.sendMessage(msg.Chat.ID, fmt.Sprintf("Error saving address: %v", err))
		return
	}

	b.sendMessage(msg.Chat.ID, fmt.Sprintf("✅ Address saved for request #%d", requestID))
}

func (b *Bot) handleView(msg *tgbotapi.Message, userID int64) {
	requestID, err := parseID(msg.CommandArguments())
	if err != nil {
		b.sendMessage(msg.Chat.ID, "Usage: /view <request_id>\nExample: /view 1")
		return
	}

	req, err := b.db.GetRequest(requestID)
	if err != nil {
		b.sendMessage(msg.Chat.ID, fmt.Sprintf("Request #%d not found.", requestID))
		return
	}

	isCoord := b.isCoordinator(userID)
	isUnclaimed := req.Status == "posted" || req.Status == "new"

	// Build the public response (no address)
	var sb strings.Builder
	sb.WriteString(fmt.Sprintf("━━━ REQUEST #%d ━━━\n\n", req.ID))
	sb.WriteString(fmt.Sprintf("Status: %s\n", req.Status))
	if req.Budget != "" {
		sb.WriteString(fmt.Sprintf("Budget: %s\n", req.Budget))
	}

	sb.WriteString("\nShopping list:\n")
	sb.WriteString(req.TranslatedText)

	if req.ClaimedByName != "" {
		sb.WriteString(fmt.Sprintf("\n\nClaimed by: %s", req.ClaimedByName))
	}

	if isUnclaimed {
		// Unclaimed: show in group for everyone
		b.sendMessage(msg.Chat.ID, sb.String())

		// Coordinator also gets address via DM
		if isCoord {
			address, _ := b.db.GetAddress(requestID)
			if address != "" {
				b.sendMessage(userID, fmt.Sprintf("📍 Address for #%d: %s", requestID, address))
			}
		}
	} else {
		// Claimed: send full details to DM
		if isCoord {
			address, _ := b.db.GetAddress(requestID)
			if address != "" {
				sb.WriteString(fmt.Sprintf("\n\n📍 Address: %s", address))
			}
		}
		b.sendMessage(userID, sb.String())
		if msg.Chat.ID != userID {
			b.sendMessage(msg.Chat.ID, "📬 Details sent to your DM.")
		}
	}
}

func (b *Bot) createRequest(chatID int64, spanishText string, budget string, zone string, address string) {
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

	// Save address if provided
	if address != "" {
		err = b.db.SaveAddress(req.ID, address)
		if err != nil {
			log.Printf("Error saving address: %v", err)
		}
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

	if address != "" {
		b.sendMessage(chatID, fmt.Sprintf("✅ Request #%d posted to volunteers with address.", req.ID))
	} else {
		b.sendMessage(chatID, fmt.Sprintf("✅ Request #%d posted to volunteers.\n\nTo add address: /address %d <address>", req.ID, req.ID))
	}
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
