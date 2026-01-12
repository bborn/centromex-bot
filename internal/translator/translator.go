package translator

import (
	"bytes"
	"fmt"
	"os/exec"
	"strings"
)

// Translator handles Spanish to English translation and formatting
type Translator struct {
	modelPath   string
	contextSize int
	threads     int
}

type Config struct {
	ModelPath   string
	ContextSize int
	Threads     int
}

func New(cfg Config) (*Translator, error) {
	return &Translator{
		modelPath:   cfg.ModelPath,
		contextSize: cfg.ContextSize,
		threads:     cfg.Threads,
	}, nil
}

// TranslateRequest takes Spanish grocery text and returns formatted English
func (t *Translator) TranslateRequest(spanishText string) (string, error) {
	prompt := fmt.Sprintf(`You are a translator for a mutual aid organization helping immigrant families. Translate this Spanish grocery list to English and format it as a bulleted list.

Spanish text:
%s

Respond ONLY with the English bulleted list, nothing else. Use this format:
• item 1
• item 2
• item 3`, spanishText)

	// Call llama-cli
	cmd := exec.Command("/home/sprite/llama.cpp/build/bin/llama-cli",
		"-m", t.modelPath,
		"-p", prompt,
		"-n", "512",
		"--temp", "0.3",
		"--threads", fmt.Sprintf("%d", t.threads),
		"--ctx-size", fmt.Sprintf("%d", t.contextSize),
		"-ngl", "0", // CPU only
		"--no-display-prompt",
	)

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	err := cmd.Run()
	if err != nil {
		return "", fmt.Errorf("llama-cli error: %w, stderr: %s", err, stderr.String())
	}

	translation := strings.TrimSpace(stdout.String())
	if translation == "" {
		return spanishText, nil // Fallback to original if translation fails
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
