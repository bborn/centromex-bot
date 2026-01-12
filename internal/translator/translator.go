package translator

import (
	"bytes"
	"embed"
	"fmt"
	"strings"
	"text/template"

	llama "github.com/ggerganov/llama.cpp/bindings/go"
)

//go:embed prompts/translate.txt
var promptFS embed.FS

type Translator struct {
	model  *llama.Model
	prompt *template.Template
}

type Config struct {
	ModelPath   string
	ContextSize int
	Threads     int
}

func New(cfg Config) (*Translator, error) {
	// Load the LLM model
	params := llama.DefaultModelParams()
	params.NGPULayers = 0 // CPU only for Sprite deployment

	model, err := llama.LoadModel(cfg.ModelPath, params)
	if err != nil {
		return nil, fmt.Errorf("failed to load model: %w", err)
	}

	// Load the prompt template
	promptBytes, err := promptFS.ReadFile("prompts/translate.txt")
	if err != nil {
		return nil, fmt.Errorf("failed to load prompt template: %w", err)
	}

	tmpl, err := template.New("translate").Parse(string(promptBytes))
	if err != nil {
		return nil, fmt.Errorf("failed to parse prompt template: %w", err)
	}

	return &Translator{
		model:  model,
		prompt: tmpl,
	}, nil
}

// TranslateRequest takes Spanish grocery text and returns formatted English
func (t *Translator) TranslateRequest(spanishText string) (string, error) {
	// Build the prompt
	var promptBuf bytes.Buffer
	err := t.prompt.Execute(&promptBuf, map[string]string{
		"input": spanishText,
	})
	if err != nil {
		return "", fmt.Errorf("failed to build prompt: %w", err)
	}

	// Create context for inference
	ctxParams := llama.DefaultContextParams()
	ctxParams.Context = 2048
	ctxParams.Threads = 4

	ctx, err := llama.NewContext(t.model, ctxParams)
	if err != nil {
		return "", fmt.Errorf("failed to create context: %w", err)
	}
	defer ctx.Free()

	// Run inference
	response, err := ctx.Predict(promptBuf.String(), llama.PredictOption{
		Temperature: 0.1, // Low temperature for consistent formatting
		TopP:        0.9,
		MaxTokens:   1024,
		StopWords:   []string{"\n\n\n", "---"},
	})
	if err != nil {
		return "", fmt.Errorf("failed to run inference: %w", err)
	}

	return strings.TrimSpace(response), nil
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

// Close releases the model resources
func (t *Translator) Close() error {
	if t.model != nil {
		t.model.Free()
	}
	return nil
}
