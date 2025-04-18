<?php

declare(strict_types=1);

require_once dirname(__FILE__) . '/constants.php';
require_once dirname(__FILE__) . '/Logger.php';

class EntryProcessor {
    private Logger $logger;

    public function processEntry(FreshRSS_Entry $entry): FreshRSS_Entry {
        $entryId = $entry->id();
        $entryIdHash = substr(hash('sha256', $entryId), 0, 8);
        $timestamp = round(microtime(true));
        $prefix = LOG_PREFIX . " [id:{$entryIdHash}] [start_timestamp:{$timestamp}]";
        $this->logger = new Logger($prefix);
        $this->logger->debug('Processing entry: ' . json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Get current tags as an array
        $tags = $entry->tags();
        $this->logger->debug('Current tags: ' . json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Skip if already processed
        if (in_array('ai-processed', $tags)) {
            $this->logger->debug('Entry already processed, skipping');
            return $entry;
        }

        // Check if ai-summary div is present in the content using proper HTML parsing
        $dom = new DOMDocument();
        // Suppress warnings about invalid HTML
        @$dom->loadHTML($entry->content(), LIBXML_NOERROR);
        $xpath = new DOMXPath($dom);
        $aiSummaryDivs = $xpath->query('//div[contains(@class, "ai-summary")]');

        if ($aiSummaryDivs->length > 0) {
            $this->logger->debug('Entry already processed (found ai-summary div), skipping');
            return $entry;
        }

        // Get the URL from the entry
        $url = $entry->link();

        if (empty($url)) {
            $this->logger->debug('No URL found, skipping');
            return $entry;
        }

        try {
            $this->logger->debug('Fetching content for URL: ' . $url);
            // Fetch full content using Chrome with WebSocket
            $content = $this->fetchContentWithChromeWebSocket($url);

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

            // Mark as processed to avoid reprocessing
            $tags = $entry->tags();
            if (!in_array('ai-processed', $tags)) {
                $tags[] = 'ai-processed';
                $entry->_tags($tags);
                $this->logger->debug('Marked entry as processed');
            }

        } catch (Exception $e) {
            $this->logger->error('error: ' . $e->getMessage());
            $this->logger->error('stack trace: ' . $e->getTraceAsString());

            // Re-throw the exception so the caller can handle it
            throw $e;
        }

        $this->logger->debug('Finished processing entry ' . json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $entry;
    }

    private function fetchContentWithChromeWebSocket(string $url): string {
        $this->logger->debug('Connecting to Chrome DevTools via WebSocket');

        // Chrome DevTools WebSocket endpoint (typically on port 9222)
        $devtoolsHost = FreshRSS_Context::$user_conf->freshrss_ollama_chrome_host ?? 'localhost';
        $devtoolsPort = FreshRSS_Context::$user_conf->freshrss_ollama_chrome_port ?? 9222;

        // Create a new target/page if none found
        $this->logger->debug('No available targets found, creating a new one');

        $ch = curl_init("http://{$devtoolsHost}:{$devtoolsPort}/json/new");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT"); // Using PUT method instead of GET
        $createResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        $this->logger->debug('Create new target response: ' . $createResponse);
        if (!$createResponse) {
            throw new Exception('Could not create new tab in Chrome: ' . $curlError);
        }

        $newTarget = json_decode($createResponse, true);
        $wsUrl = '';
        $targetId = '';
        if (isset($newTarget['webSocketDebuggerUrl'])) {
            $wsUrl = $newTarget['webSocketDebuggerUrl'];
            $targetId = $newTarget['id'] ?? '';
        } else {
            throw new Exception('No WebSocket URL in new target response');
        }

        $this->logger->debug('WebSocket URL: ' . $wsUrl);
        $this->logger->debug('Target ID: ' . $targetId);

        // Connect to the WebSocket
        $options = [
           'timeout' => 60,
           'context' => stream_context_create([
               'socket' => [
                   'tcp_nodelay' => true,
               ],
           ]),
        ];
        $client = new WebSocket\Client($wsUrl, $options);

        try {
            // Navigate to the URL
            $navigateMessage = json_encode([
                'id' => 1,
                'method' => 'Page.navigate',
                'params' => ['url' => $url]
            ]);
            $this->logger->debug('Sending navigation command: ' . $navigateMessage);
            $client->send($navigateMessage);

            // Wait for navigation to complete
            $navigationComplete = false;
            while (!$navigationComplete) {
                $response = $client->receive();
                $data = json_decode($response, true);
                $this->logger->debug('Received response: ' . $response);

                if (isset($data['method']) && $data['method'] === 'Page.frameStoppedLoading') {
                    $navigationComplete = true;
                }

                // TODO: for some reason, we never receive the Page.frameStoppedLoading event. The only event we receive is this
                // {"id":1,"result":{"frameId":"6C9BB82D57A684D8882532811FFE2BC4","loaderId":"7FC35F7645DE5DDCA9E3F420555017AD"}}
                break;
            }

            // Wait a bit for JavaScript to execute (adjust as needed)
            sleep(10);

            // First try to find and get content from article tag
            $articleEvalMessage = json_encode([
                'id' => 2,
                'method' => 'Runtime.evaluate',
                'params' => [
                    'expression' => 'document.querySelector("article")?.innerText || document.body.innerText',
                    'returnByValue' => true
                ]
            ]);
            $this->logger->debug('Sending article evaluation command: ' . $articleEvalMessage);
            $client->send($articleEvalMessage);

            // Get the evaluation result
            $content = '';
            $evalComplete = false;
            while (!$evalComplete) {
                $response = $client->receive();
                $data = json_decode($response, true);
                $this->logger->debug('Received evaluation response: ' . $response);

                if (isset($data['id']) && $data['id'] === 2) {
                    if (isset($data['result']['result']['value'])) {
                        $content = $data['result']['result']['value'];
                    }
                    $evalComplete = true;
                }
            }

            return $content;
        } finally {
            $this->logger->debug('Starting cleanup process');

            try {
                // Close the WebSocket connection
                if (isset($client)) {
                    $this->logger->debug('Closing WebSocket connection');
                    $client->close();
                }

                // Close the Chrome tab if we have a target ID
                if (!empty($targetId)) {
                    $this->logger->debug('Closing Chrome tab with ID: ' . $targetId);

                    $ch = curl_init("http://{$devtoolsHost}:{$devtoolsPort}/json/close/{$targetId}");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Add timeout to prevent hanging

                    $result = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                    if ($result === false) {
                        $this->logger->error('Error closing Chrome tab: ' . curl_error($ch));
                    } else {
                        $this->logger->debug('Chrome tab closed successfully (HTTP ' . $httpCode . ')');
                    }

                    curl_close($ch);
                } else {
                    $this->logger->warning('No target ID available for cleanup');
                }
            } catch (Exception $e) {
                $this->logger->error('Error during cleanup: ' . $e->getMessage());
            }
        }
    }

    private function generateOllamaSummary(string $content): string {
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

        // First, check if this is a valid article.  This is just a rough check
        // to avoid processing placeholder pages, but it's not foolproof
        $validationPrompt = <<<EOT
Analyze the following content and determine if it is a valid article. A valid article should:
- Have substantial content (not just a few sentences)
- Not be a login page, consent screen, or error page
- Not be a list of links or navigation elements
- Not be a placeholder or redirect page
- Not be a captcha or security check page

If the content is NOT a valid article, respond with exactly: INVALID_CONTENT
Otherwise, output nothing.

Content to analyze:
$content
EOT;

        $validationResponse = $this->callOllama($ollamaHost, $ollamaModel, $validationPrompt, false);

        if (trim($validationResponse) === 'INVALID_CONTENT') {
            $this->logger->debug('Content validation failed - not a valid article');
            return 'PLACEHOLDER_PAGE';
        }

        // If we get here, we have a valid article - proceed with summary generation
        $numTags = FreshRSS_Context::$user_conf->freshrss_ollama_num_tags ?? 5;
        $summaryLength = FreshRSS_Context::$user_conf->freshrss_ollama_summary_length ?? 150;

        $summaryPrompt = <<<EOT
Based on the following article content, please provide:
1. A concise summary (around $summaryLength words)
2. $numTags relevant tags (single words or short phrases)

Format your response exactly like this:
SUMMARY: your summary here
TAGS: tag1, tag2, tag3, ...

Article content:
$content
EOT;

        return $this->callOllama($ollamaHost, $ollamaModel, $summaryPrompt, false);
    }

    private function callOllama(string $ollamaHost, string $model, string $prompt, bool $stream = false): string {
        $apiEndpoint = "$ollamaHost/api/generate";
        $this->logger->debug("Sending request to Ollama at $apiEndpoint");

        // Ensure prompt is properly encoded
        $prompt = mb_convert_encoding($prompt, 'UTF-8', 'auto');

        $data = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => $stream
        ];

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

    private function updateEntryWithSummary(FreshRSS_Entry $entry, string $ollamaResponse): void {
        $this->logger->debug('Updating entry with summary and tags');

        // Check if this is a placeholder page response
        if (trim($ollamaResponse) === 'PLACEHOLDER_PAGE') {
            $this->logger->debug('Detected placeholder page, skipping summary and tags');

            // Add a summary div to prevent reprocessing
            $content = $entry->content();
            $updatedContent = $content . '<hr/><div class="ai-summary"><strong>Note:</strong> Summary generation skipped - page appears to be a placeholder, consent screen, or requires authentication.</div><hr>';
            $entry->_content($updatedContent);

            return;
        }

        // Extract summary and tags from Ollama response
        $summary = '';
        $tags = [];

        // Parse the response
        $this->logger->debug('Parsing Ollama response: ' . substr($ollamaResponse, 0, 100) . '...');

        if (preg_match('/SUMMARY:\s*(.*?)(?:\r?\n|$)/s', $ollamaResponse, $summaryMatch)) {
            $summary = trim($summaryMatch[1]);
            $this->logger->debug('Extracted summary: ' . substr($summary, 0, 100) . '...');
        } else {
            $this->logger->debug('No summary found in response');

            // Add a summary div to prevent reprocessing even when no valid summary was found
            $content = $entry->content();
            $updatedContent = $content . '<hr/><div class="ai-summary"><strong>Note:</strong> Summary generation failed - no valid summary could be extracted from the response.</div><hr>';
            $entry->_content($updatedContent);

            return;
        }

        // Update entry summary if found
        if (!empty($summary)) {
            // Prepend the summary to the content
            $content = $entry->content();
            $this->logger->debug('Original content length: ' . strlen($content));

            $updatedContent = $content . '<hr/><div class="ai-summary"><strong>Summary:</strong> ' . htmlspecialchars($summary) . '</div><hr>';
            $entry->_content($updatedContent);

            $this->logger->debug('Updated content length: ' . strlen($updatedContent));
        }

        // Add tags if found
        if (preg_match('/TAGS:\s*(.*?)(?:\r?\n|$)/s', $ollamaResponse, $tagsMatch)) {
            $tagsList = trim($tagsMatch[1]);
            $tags = array_map('trim', explode(',', $tagsList));
            $this->logger->debug('Extracted tags: ' . json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if (!empty($tags)) {
                $currentTags = $entry->tags();
                $this->logger->debug('Current tags: ' . json_encode($currentTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                $addedTags = [];
                foreach ($tags as $tag) {
                    if (!empty($tag)) {
                        // Strip any # prefix if it exists and clean the tag
                        $tag = trim(ltrim(trim($tag), '#'));
                        if (!empty($tag)) {
                            $currentTags[] = $tag;
                            $addedTags[] = $tag;
                        }
                    }
                }

                // Use array_values to reindex the array after array_unique
                $uniqueTags = array_values(array_unique($currentTags));
                $entry->_tags($uniqueTags);

                $this->logger->debug('Added tags: ' . json_encode($addedTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->logger->debug('Final tags: ' . json_encode($uniqueTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
    }
} 