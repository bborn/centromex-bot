package translator

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
)

// Translator handles Spanish to English translation and formatting
type Translator struct {
	apiKey string
}

type Config struct {
	ModelPath   string // Not used for OpenAI, kept for compatibility
	ContextSize int    // Not used for OpenAI
	Threads     int    // Not used for OpenAI
	OpenAIKey   string // OpenAI API key
}

func New(cfg Config) (*Translator, error) {
	return &Translator{
		apiKey: cfg.OpenAIKey,
	}, nil
}

type openAIRequest struct {
	Model    string    `json:"model"`
	Messages []message `json:"messages"`
	MaxTokens int      `json:"max_tokens"`
	Temperature float64 `json:"temperature"`
}

type message struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

type openAIResponse struct {
	Choices []struct {
		Message message `json:"message"`
	} `json:"choices"`
	Error *struct {
		Message string `json:"message"`
	} `json:"error"`
}

// TranslateRequest takes Spanish grocery text and returns formatted English
func (t *Translator) TranslateRequest(spanishText string) (string, error) {
	// If no API key, return Spanish as-is
	if t.apiKey == "" {
		return spanishText, nil
	}

	prompt := fmt.Sprintf(`Translate this Spanish grocery list to English. Format as a bulleted list with • bullets.

Spanish text:
%s

Respond ONLY with the English bulleted list, nothing else.`, spanishText)

	reqBody := openAIRequest{
		Model: "gpt-4o-mini",
		Messages: []message{
			{Role: "system", Content: "You are a helpful translator for a mutual aid organization. Translate grocery lists accurately and format them as bulleted lists."},
			{Role: "user", Content: prompt},
		},
		MaxTokens: 500,
		Temperature: 0.3,
	}

	jsonData, err := json.Marshal(reqBody)
	if err != nil {
		return "", fmt.Errorf("failed to marshal request: %w", err)
	}

	req, err := http.NewRequest("POST", "https://api.openai.com/v1/chat/completions", bytes.NewBuffer(jsonData))
	if err != nil {
		return "", fmt.Errorf("failed to create request: %w", err)
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+t.apiKey)

	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		return "", fmt.Errorf("API request failed: %w", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", fmt.Errorf("failed to read response: %w", err)
	}

	var openAIResp openAIResponse
	if err := json.Unmarshal(body, &openAIResp); err != nil {
		return "", fmt.Errorf("failed to parse response: %w", err)
	}

	if openAIResp.Error != nil {
		return "", fmt.Errorf("OpenAI API error: %s", openAIResp.Error.Message)
	}

	if len(openAIResp.Choices) == 0 {
		return spanishText, nil // Fallback to original
	}

	translation := strings.TrimSpace(openAIResp.Choices[0].Message.Content)
	if translation == "" {
		return spanishText, nil
	}

	return translation, nil
}

// FormatRequest creates the final formatted message for volunteers
func (t *Translator) FormatRequest(requestID int64, zone string, budget string, translatedText string) string {
	var sb strings.Builder

	sb.WriteString("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n")
	sb.WriteString(fmt.Sprintf("📋 REQUEST #%d", requestID))
	if zone != "" {
		sb.WriteString(fmt.Sprintf(" • %s", zone))
	}
	sb.WriteString("\n")

	if budget != "" {
		sb.WriteString(fmt.Sprintf("💵 %s\n", budget))
	}

	sb.WriteString("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n")
	sb.WriteString(translatedText)
	sb.WriteString("\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n")
	sb.WriteString(fmt.Sprintf("Reply /claim %d to take this request\n", requestID))
	sb.WriteString("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━")

	return sb.String()
}

// Close releases resources
func (t *Translator) Close() error {
	return nil
}
