package translator

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"log"
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

type TranslationResult struct {
	CleanedText string // Safe for public posting (no PII)
	Address     string // Extracted address (private, DM only)
	Phone       string // Extracted phone (private, DM only)
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

// TranslateRequest takes Spanish grocery text and returns formatted English with PII extracted
func (t *Translator) TranslateRequest(spanishText string) (*TranslationResult, error) {
	// If no API key, return Spanish as-is
	if t.apiKey == "" {
		return &TranslationResult{CleanedText: spanishText}, fmt.Errorf("no OpenAI API key configured")
	}

	prompt := fmt.Sprintf(`You are translating a grocery request for a mutual aid organization. Extract any private information (address, phone, full last names) and provide a clean translation safe for public posting.

Spanish text:
%s

Respond in JSON format:
{
  "translation": "bulleted list of grocery items in English with • bullets",
  "address": "extracted address if any, or empty string",
  "phone": "extracted phone number if any, or empty string"
}

IMPORTANT:
- Only include first names in the translation, remove last names
- Remove addresses and phone numbers from the translation
- Extract them to the address/phone fields
- The translation should be SAFE for public posting`, spanishText)

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
		return &TranslationResult{CleanedText: spanishText}, fmt.Errorf("failed to marshal request: %w", err)
	}

	req, err := http.NewRequest("POST", "https://api.openai.com/v1/chat/completions", bytes.NewBuffer(jsonData))
	if err != nil {
		return &TranslationResult{CleanedText: spanishText}, fmt.Errorf("failed to create request: %w", err)
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+t.apiKey)

	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		log.Printf("OpenAI API request failed: %v", err)
		return &TranslationResult{CleanedText: spanishText}, fmt.Errorf("API request failed: %w", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		log.Printf("Failed to read OpenAI response: %v", err)
		return &TranslationResult{CleanedText: spanishText}, fmt.Errorf("failed to read response: %w", err)
	}

	log.Printf("OpenAI API response status: %d, body: %s", resp.StatusCode, string(body))

	var openAIResp openAIResponse
	if err := json.Unmarshal(body, &openAIResp); err != nil {
		log.Printf("Failed to parse OpenAI response: %v", err)
		return &TranslationResult{CleanedText: spanishText}, fmt.Errorf("failed to parse response: %w", err)
	}

	if openAIResp.Error != nil {
		log.Printf("OpenAI API error: %s", openAIResp.Error.Message)
		return &TranslationResult{CleanedText: spanishText}, fmt.Errorf("OpenAI API error: %s", openAIResp.Error.Message)
	}

	if len(openAIResp.Choices) == 0 {
		log.Printf("No choices in OpenAI response, falling back to original")
		return &TranslationResult{CleanedText: spanishText}, fmt.Errorf("no translation returned from OpenAI")
	}

	content := strings.TrimSpace(openAIResp.Choices[0].Message.Content)
	if content == "" {
		log.Printf("Empty translation from OpenAI, falling back to original")
		return &TranslationResult{CleanedText: spanishText}, fmt.Errorf("empty translation from OpenAI")
	}

	// Parse JSON response
	var result struct {
		Translation string `json:"translation"`
		Address     string `json:"address"`
		Phone       string `json:"phone"`
	}

	if err := json.Unmarshal([]byte(content), &result); err != nil {
		log.Printf("Failed to parse translation JSON, using as plain text: %v", err)
		// Fallback to using content directly if not JSON
		return &TranslationResult{CleanedText: content}, nil
	}

	log.Printf("Translation successful: %d chars -> %d chars, extracted address: %v, phone: %v",
		len(spanishText), len(result.Translation), result.Address != "", result.Phone != "")

	return &TranslationResult{
		CleanedText: result.Translation,
		Address:     result.Address,
		Phone:       result.Phone,
	}, nil
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
