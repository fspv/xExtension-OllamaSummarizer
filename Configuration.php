<?php

declare(strict_types=1);

/**
 * Configuration class for the Ollama Summarizer extension.
 * This class handles all configuration values and their validation.
 */
class Configuration
{
    /**
     * @param string               $chromeHost        Hostname where Chrome is running with remote debugging enabled
     * @param int                  $chromePort        Debug port for Chrome
     * @param string               $ollamaHost        URL of the Ollama API endpoint
     * @param string               $ollamaModel       The model name to use for summarization
     * @param array<string, mixed> $modelOptions      Additional options for the Ollama model
     * @param int                  $promptLengthLimit Maximum length of the prompt in characters
     * @param int                  $contextLength     Context length for the model in tokens
     * @param string               $promptTemplate    Template for the prompt sent to Ollama
     * @param array<int>           $selectedFeeds     Array of feed IDs that should be processed
     */
    public function __construct(
        private readonly string $chromeHost,
        private readonly int $chromePort,
        private readonly string $ollamaHost,
        private readonly string $ollamaModel,
        private readonly array $modelOptions,
        private readonly int $promptLengthLimit,
        private readonly int $contextLength,
        private readonly string $promptTemplate,
        private readonly array $selectedFeeds = [],
    ) {
        $this->validate();
    }

    /**
     * Creates a Configuration instance with default values.
     */
    public static function createDefault(): self
    {
        return new self(
            chromeHost: 'localhost',
            chromePort: 9222,
            ollamaHost: 'http://localhost:11434',
            ollamaModel: 'llama3',
            modelOptions: [],
            promptLengthLimit: 8192,
            contextLength: 4096,
            promptTemplate: <<<EOT
Based on the following article content, please provide:
1. A concise summary (around 150 words)
2. 5 relevant tags (single words or short phrases)

Article content:
EOT,
            selectedFeeds: [],
        );
    }

    public static function fromUserConfiguration(FreshRSS_UserConfiguration $userConfig): self
    {
        $defaults = self::createDefault();
        $modelOptions = $userConfig->attributeArray('ollama_summarizer_model_options') ?? $defaults->getModelOptions();
        $modelOptionsValidated = [];
        foreach ($modelOptions as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Model options must have string keys');
            }
            $modelOptionsValidated[$key] = $value;
        }

        $config = new self(
            chromeHost: $userConfig->attributeString('ollama_summarizer_chrome_host') ?? $defaults->getChromeHost(),
            chromePort: $userConfig->attributeInt('ollama_summarizer_chrome_port') ?? $defaults->getChromePort(),
            ollamaHost: $userConfig->attributeString('ollama_summarizer_ollama_host') ?? $defaults->getOllamaHost(),
            ollamaModel: $userConfig->attributeString('ollama_summarizer_ollama_model') ?? $defaults->getOllamaModel(),
            modelOptions: $modelOptionsValidated,
            promptLengthLimit: $userConfig->attributeInt('ollama_summarizer_prompt_length_limit') ?? $defaults->getPromptLengthLimit(),
            contextLength: $userConfig->attributeInt('ollama_summarizer_context_length') ?? $defaults->getContextLength(),
            promptTemplate: $userConfig->attributeString('ollama_summarizer_prompt_template') ?? $defaults->getPromptTemplate(),
            selectedFeeds: $userConfig->attributeArray('ollama_summarizer_selected_feeds') ?? $defaults->getSelectedFeeds(),
        );
        $config->validate();

        return $config;
    }

    /**
     * Converts the configuration to an array format suitable for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ollama_summarizer_chrome_host' => $this->chromeHost,
            'ollama_summarizer_chrome_port' => $this->chromePort,
            'ollama_summarizer_ollama_host' => $this->ollamaHost,
            'ollama_summarizer_ollama_model' => $this->ollamaModel,
            'ollama_summarizer_model_options' => $this->modelOptions,
            'ollama_summarizer_prompt_length_limit' => $this->promptLengthLimit,
            'ollama_summarizer_context_length' => $this->contextLength,
            'ollama_summarizer_prompt_template' => $this->promptTemplate,
            'ollama_summarizer_selected_feeds' => $this->selectedFeeds,
        ];
    }

    /**
     * Validates all configuration values.
     *
     * @throws InvalidArgumentException If any validation fails
     */
    private function validate(): void
    {
        if ($this->chromePort < 1 || $this->chromePort > 65535) {
            throw new InvalidArgumentException('Chrome port must be between 1 and 65535');
        }

        if (!filter_var($this->ollamaHost, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid Ollama host URL');
        }

        if (empty($this->ollamaModel)) {
            throw new InvalidArgumentException('Ollama model name cannot be empty');
        }

        if ($this->promptLengthLimit < 1024 || $this->promptLengthLimit > 32768) {
            throw new InvalidArgumentException('Prompt length limit must be between 1024 and 32768');
        }

        if ($this->contextLength < 1024 || $this->contextLength > 32768) {
            throw new InvalidArgumentException('Context length must be between 1024 and 32768');
        }

        if (empty($this->promptTemplate)) {
            throw new InvalidArgumentException('Prompt template cannot be empty');
        }

        foreach ($this->modelOptions as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Model options must have string keys');
            }
        }
    }

    public function getChromeHost(): string
    {
        return $this->chromeHost;
    }

    public function getChromePort(): int
    {
        return $this->chromePort;
    }

    public function getOllamaHost(): string
    {
        return $this->ollamaHost;
    }

    public function getOllamaModel(): string
    {
        return $this->ollamaModel;
    }

    /**
     * @return array<string, mixed>
     */
    public function getModelOptions(): array
    {
        return $this->modelOptions;
    }

    public function getPromptLengthLimit(): int
    {
        return $this->promptLengthLimit;
    }

    public function getContextLength(): int
    {
        return $this->contextLength;
    }

    public function getPromptTemplate(): string
    {
        return $this->promptTemplate;
    }

    /**
     * @return array<int>
     */
    public function getSelectedFeeds(): array
    {
        return $this->selectedFeeds;
    }

    /**
     * Checks if a feed ID is selected for processing.
     */
    public function isFeedSelected(int $feedId): bool
    {
        return in_array($feedId, $this->selectedFeeds, true);
    }
}
