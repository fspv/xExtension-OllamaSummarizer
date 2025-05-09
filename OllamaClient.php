<?php

declare(strict_types=1);

interface OllamaClient
{
    /**
     * Generate a summary and tags for the given content.
     *
     * @param string $content       The content to summarize
     * @param int    $summaryLength The desired length of the summary in words
     * @param int    $numTags       The number of tags to generate
     *
     * @throws Exception If the generation fails
     *
     * @return array{summary: string, tags: string[]} The generated summary and tags
     */
    public function generateSummary(string $content, int $summaryLength = 150, int $numTags = 5): array;
}

class OllamaClientImpl implements OllamaClient
{
    private Logger $logger;

    private string $ollamaHost;

    private string $ollamaModel;

    private array $modelOptions;

    private int $promptLengthLimit;

    public function __construct(
        Logger $logger,
        string $ollamaHost = 'http://localhost:11434',
        string $ollamaModel = 'llama3',
        array $modelOptions = [],
        int $promptLengthLimit = 8192
    ) {
        $this->logger = $logger;
        $this->ollamaHost = rtrim($ollamaHost, '/');
        $this->ollamaModel = $ollamaModel;
        $this->modelOptions = $modelOptions;
        $this->promptLengthLimit = $promptLengthLimit;
    }

    public function generateSummary(string $content, int $summaryLength = 150, int $numTags = 5): array
    {
        $this->logger->debug('Starting Ollama summary generation');

        if (empty($content)) {
            $this->logger->error('Empty content provided to generateSummary');

            throw new Exception('No content provided for Ollama to summarize');
        }

        $this->logger->debug('Content length: ' . strlen($content) . ' bytes');

        $summaryPrompt = <<<EOT
Based on the following article content, please provide:
1. A concise summary (around $summaryLength words)
2. $numTags relevant tags (single words or short phrases)

Article content:
$content
EOT;

        // Truncate prompt if it exceeds the limit
        if (strlen($summaryPrompt) > $this->promptLengthLimit) {
            $this->logger->debug('Prompt length (' . strlen($summaryPrompt) . ") exceeds limit ({$this->promptLengthLimit}), truncating");
            $summaryPrompt = substr($summaryPrompt, 0, $this->promptLengthLimit);
            $summaryPrompt .= "\n\n[Content truncated due to length limit]";
        }

        // Define the response format schema
        $format = [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => 'A concise summary of the article',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                    'description' => 'Relevant tags for the article',
                ],
            ],
            'required' => ['summary', 'tags'],
        ];

        $response = $this->callOllama($summaryPrompt, $format);
        $responseData = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response: ' . json_last_error_msg());
        }

        if (!isset($responseData['summary']) || !isset($responseData['tags'])) {
            throw new Exception('Missing required fields in response');
        }

        return [
            'summary' => trim($responseData['summary']),
            'tags' => array_map('trim', $responseData['tags']),
        ];
    }

    private function callOllama(string $prompt, ?array $format = null): string
    {
        $apiEndpoint = "{$this->ollamaHost}/api/generate";
        $this->logger->debug("Sending request to Ollama at $apiEndpoint");

        // Ensure prompt is properly encoded
        $prompt = mb_convert_encoding($prompt, 'UTF-8', 'auto');

        $data = [
            'model' => $this->ollamaModel,
            'prompt' => $prompt,
            'stream' => false,
        ];

        if (!empty($this->modelOptions)) {
            $data['options'] = $this->modelOptions;
            $this->logger->debug('Using model options: ' . json_encode($this->modelOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        if ($format !== null) {
            $data['format'] = $format;
            $this->logger->debug('Using format: ' . json_encode($format, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonData === false) {
            $this->logger->error('JSON encoding failed: ' . json_last_error_msg());

            throw new Exception('Failed to encode request data as JSON: ' . json_last_error_msg());
        }

        try {
            $ch = curl_init($apiEndpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);

            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            curl_setopt($ch, CURLOPT_STDERR, $verbose);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->logger->debug("Ollama response (HTTP $httpCode): " . substr($result, 0, 500) . '...');

            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            $this->logger->debug('cURL verbose output: ' . $verboseLog);

            if ($result === false) {
                $error = curl_error($ch);
                curl_close($ch);

                throw new Exception('Failed to connect to Ollama service: ' . $error);
            }

            curl_close($ch);

            $response = json_decode($result, true);

            if (!isset($response['response'])) {
                $this->logger->debug('Unexpected response format: ' . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                throw new Exception('Unexpected response format from Ollama');
            }

            $this->logger->debug('Received Ollama response, length: ' . strlen($response['response']));

            return $response['response'] ?? '';
        } catch (Exception $e) {
            $this->logger->error('Ollama error: ' . $e->getMessage());

            throw $e;
        }
    }
}
