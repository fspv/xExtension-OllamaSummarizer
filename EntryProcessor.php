<?php

declare(strict_types=1);

require_once dirname(__FILE__) . '/constants.php';
require_once dirname(__FILE__) . '/Logger.php';
require_once dirname(__FILE__) . '/WebpageFetcher.php';

class EntryProcessor
{
    private Logger $logger;

    public function processEntry(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        # Construct a unique identifier for the entry to identify processing of the same entry in the logs
        $entryId = $entry->guid();
        $entryIdHash = substr(hash('sha256', $entryId), 0, 8);
        $timestamp = round(microtime(true));
        $prefix = LOG_PREFIX . " [id:{$entryIdHash}] [start_timestamp:{$timestamp}]";
        $this->logger = new Logger($prefix);
        $this->logger->debug('Processing entry: ' . json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($entry->hasAttribute('ai-processed')) {
            $this->logger->debug('Entry already processed, restoring tags from attributes');

            if ($entry->hasAttribute('ai-tags')) {
                $savedTags = $entry->attributeArray('ai-tags');
                if (!empty($savedTags)) {
                    $currentTags = $entry->tags();
                    $this->logger->debug('Current tags: ' . json_encode($currentTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                    foreach ($savedTags as $tag) {
                        if (!empty($tag) && !in_array($tag, $currentTags)) {
                            $currentTags[] = $tag;
                        }
                    }

                    $entry->_tags($currentTags);
                    $this->logger->debug('Restored tags: ' . json_encode($currentTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }

            return $entry;
        }

        if ($entry->isUpdated()) {
            $this->logger->debug('Entry is updated, skipping');
            return $entry;
        }

        $url = $entry->link();

        if (empty($url)) {
            $this->logger->debug('No URL found, skipping');
            return $entry;
        }

        try {
            $this->logger->debug('Fetching content for URL: ' . $url);

            // Initialize WebpageFetcher with configuration
            $devtoolsHost = FreshRSS_Context::$user_conf->freshrss_ollama_chrome_host ?? 'localhost';
            $devtoolsPort = FreshRSS_Context::$user_conf->freshrss_ollama_chrome_port ?? 9222;
            $webpageFetcher = new WebpageFetcher($this->logger, $devtoolsHost, $devtoolsPort);

            // Fetch full content using Chrome with WebSocket
            $content = $webpageFetcher->fetchContent($url, $entry->feed()->pathEntries() ?: 'article');
            $ollamaResponse = '';

            if (!empty($content)) {
                $this->logger->debug('Content fetched successfully, length: ' . strlen($content));

                // Generate tags and summary using Ollama
                $this->logger->debug('Sending content to Ollama');
                $ollamaResponse = $this->generateOllamaSummary($content);

                if (!empty($ollamaResponse)) {
                    $this->logger->debug('Ollama response received, length: ' . strlen($ollamaResponse));

                    // Update the entry with tags and summary
                    $this->logger->debug('Updating entry with summary and tags');
                    $this->updateEntryWithSummary($entry, $ollamaResponse);
                } else {
                    $this->logger->debug('Empty response from Ollama');
                }
            } else {
                $this->logger->debug('No content fetched from URL');
            }

            $entry->_attribute('ai-processed', true);

            $debugInfo = [
                'content' => $content,
                'ollamaResponse' => $ollamaResponse
            ];
            $entry->_attribute('ai-debug', json_encode($debugInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $this->logger->debug('Finished processing entry ' . json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $entry;
        } catch (Exception $e) {
            $this->logger->error('error: ' . $e->getMessage());
            $this->logger->error('stack trace: ' . $e->getTraceAsString());

            throw $e;
        }
    }

    private function generateOllamaSummary(string $content): string
    {
        $this->logger->debug('Starting Ollama summary generation');

        // Validate content
        if (empty($content)) {
            $this->logger->error('Empty content provided to generateOllamaSummary');
            throw new Exception("No content provided for Ollama to summarize");
        }

        $this->logger->debug('Content length: ' . strlen($content) . ' bytes');

        $ollamaHost = FreshRSS_Context::$user_conf->freshrss_ollama_ollama_host ?? 'http://localhost:11434';
        $ollamaHost = rtrim($ollamaHost, '/');
        $ollamaModel = FreshRSS_Context::$user_conf->freshrss_ollama_ollama_model ?? 'llama3';

        // Get model options from config
        $modelOptions = FreshRSS_Context::$user_conf->freshrss_ollama_model_options ?? [];

        // Get prompt length limit (default ~2048 tokens, assuming ~4 chars per token)
        $promptLengthLimit = FreshRSS_Context::$user_conf->freshrss_ollama_prompt_length_limit ?? 8192;

        $numTags = FreshRSS_Context::$user_conf->freshrss_ollama_num_tags ?? 5;
        $summaryLength = FreshRSS_Context::$user_conf->freshrss_ollama_summary_length ?? 150;

        $summaryPrompt = <<<EOT
Based on the following article content, please provide:
1. A concise summary (around $summaryLength words)
2. $numTags relevant tags (single words or short phrases)

Article content:
$content
EOT;

        // Truncate prompt if it exceeds the limit
        if (strlen($summaryPrompt) > $promptLengthLimit) {
            $this->logger->debug("Prompt length (" . strlen($summaryPrompt) . ") exceeds limit ($promptLengthLimit), truncating");
            $summaryPrompt = substr($summaryPrompt, 0, $promptLengthLimit);
            $summaryPrompt .= "\n\n[Content truncated due to length limit]";
        }

        // Define the response format schema
        $format = [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => 'A concise summary of the article'
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string'
                    ],
                    'description' => 'Relevant tags for the article'
                ]
            ],
            'required' => ['summary', 'tags']
        ];

        return $this->callOllama($ollamaHost, $ollamaModel, $summaryPrompt, false, $modelOptions, $format);
    }

    private function callOllama(string $ollamaHost, string $model, string $prompt, bool $stream = false, array $modelOptions = [], array $format = null): string
    {
        $apiEndpoint = "$ollamaHost/api/generate";
        $this->logger->debug("Sending request to Ollama at $apiEndpoint");

        // Ensure prompt is properly encoded
        $prompt = mb_convert_encoding($prompt, 'UTF-8', 'auto');

        $data = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => $stream
        ];

        // Add model options if provided
        if (!empty($modelOptions)) {
            $data['options'] = $modelOptions;
            $this->logger->debug("Using model options: " . json_encode($modelOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // Add format if provided
        if ($format !== null) {
            $data['format'] = $format;
            $this->logger->debug("Using format: " . json_encode($format, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // Use JSON_UNESCAPED_UNICODE to properly handle Unicode characters
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->logger->debug("Request payload: " . $jsonData);

        // Debug data structure before encoding
        $this->logger->debug("Data structure before JSON encoding: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Check if JSON encoding succeeded
        if ($jsonData === false) {
            $this->logger->error("JSON encoding failed: " . json_last_error_msg());
            throw new Exception("Failed to encode request data as JSON: " . json_last_error_msg());
        }

        try {
            $ch = curl_init($apiEndpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);

            // Add verbose debugging for cURL
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            curl_setopt($ch, CURLOPT_STDERR, $verbose);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->logger->debug("Ollama response (HTTP $httpCode): " . substr($result, 0, 500) . "...");

            // Log verbose cURL output
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            $this->logger->debug("cURL verbose output: " . $verboseLog);

            if ($result === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new Exception("Failed to connect to Ollama service: " . $error);
            }

            curl_close($ch);

            $response = json_decode($result, true);

            if (!isset($response['response'])) {
                $this->logger->debug("Unexpected response format: " . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                throw new Exception("Unexpected response format from Ollama");
            }

            $this->logger->debug("Received Ollama response, length: " . strlen($response['response']));
            return $response['response'] ?? '';
        } catch (Exception $e) {
            $this->logger->error("Ollama error: " . $e->getMessage());
            throw $e;
        }
    }

    private function normalizeTag(string $tag): string
    {
        // Convert to lowercase
        $tag = mb_strtolower($tag, 'UTF-8');

        // Remove any non-English characters and non-spaces
        $tag = preg_replace('/[^a-z\s]/', '', $tag);

        // Remove extra spaces and trim
        $tag = preg_replace('/\s+/', ' ', $tag);
        $tag = trim($tag);

        return $tag;
    }

    private function updateEntryWithSummary(FreshRSS_Entry $entry, string $ollamaResponse): void
    {
        $this->logger->debug('Updating entry with summary and tags');

        // Check if this is a placeholder page response
        if (trim($ollamaResponse) === 'PLACEHOLDER_PAGE') {
            $this->logger->debug('Detected placeholder page, skipping summary and tags');
            $entry->_attribute('ai-summary', 'Summary generation skipped - page appears to be a placeholder, consent screen, or requires authentication.');
            return;
        }

        // Parse the JSON response
        $this->logger->debug('Parsing Ollama response: ' . substr($ollamaResponse, 0, 100) . '...');

        try {
            $responseData = json_decode($ollamaResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON response: " . json_last_error_msg());
            }

            if (!isset($responseData['summary']) || !isset($responseData['tags'])) {
                throw new Exception("Missing required fields in response");
            }

            $summary = trim($responseData['summary']);
            $tags = array_map('trim', $responseData['tags']);

            $this->logger->debug('Extracted summary: ' . substr($summary, 0, 100) . '...');
            $this->logger->debug('Extracted tags: ' . json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $entry->_attribute('ai-summary', $summary);
            $entry->_attribute('ai-tags', []);

            if (!empty($tags)) {
                $currentTags = $entry->tags();
                $this->logger->debug('Current tags: ' . json_encode($currentTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                $addedTags = [];
                foreach ($tags as $tag) {
                    if (!empty($tag)) {
                        // Normalize the tag
                        $tag = $this->normalizeTag($tag);
                        if (!empty($tag)) {
                            $currentTags[] = $tag;
                            $addedTags[] = $tag;
                        }
                    }
                }

                $entry->_attribute('ai-tags', $addedTags);

                // Use array_values to reindex the array after array_unique
                $uniqueTags = array_values(array_unique($currentTags));
                $entry->_tags($uniqueTags);

                $this->logger->debug('Added tags: ' . json_encode($addedTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->logger->debug('Final tags: ' . json_encode($uniqueTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } catch (Exception $e) {
            $this->logger->error('Error parsing Ollama response: ' . $e->getMessage());
            $entry->_attribute('ai-summary', 'Summary generation failed - could not parse the response.');
        }
    }
}
