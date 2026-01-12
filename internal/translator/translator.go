package translator

import (
	"fmt"
	"strings"
)

// Translator handles Spanish to English translation and formatting
// For now, this is a stub - real LLM integration will be added later
type Translator struct {
	// Will hold llama.cpp model later
}

type Config struct {
	ModelPath   string
	ContextSize int
	Threads     int
}

func New(cfg Config) (*Translator, error) {
	// Stub - just return an empty translator
	// Real implementation will load the LLM model
	return &Translator{}, nil
}

// TranslateRequest takes Spanish grocery text and returns formatted English
// For now, returns the original text (LLM translation not yet configured)
func (t *Translator) TranslateRequest(spanishText string) (string, error) {
	// TODO: Replace with actual LLM translation
	// For now, just return the Spanish text as-is
	return spanishText, nil
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
