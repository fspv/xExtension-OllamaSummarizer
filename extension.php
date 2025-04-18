<?php

declare(strict_types=1);

/**
 * Name: Ollama Summarizer
 * Author: Pavel Safronov
 * Description: Fetches article content using Chrome and uses Ollama to generate tags and summaries
 * Version: 0.1.1
 */

// Extensions guide
// https://freshrss.github.io/FreshRSS/en/developers/03_Backend/05_Extensions.html
//
// TODO:
// - Tests (need to implement dependency injection of fetchers first to avoid mocking stuff)
// - Consistent naming (primarily of config variables)
// - Examples of using with docker (chrome + ollama + freshrss)
// - Set up builds
// - Handle situations when ollama is not available
// - Handle ollama auth
// - Very thorough logging of all the requests and responses in debug mode
// - i don't need cookies extension for chrome
// - set up vim lsp for php
//
// nix-shell -p php83Packages.php-cs-fixer --pure --command 'php-cs-fixer fix extension.php'
// nix-shell -p php83Packages.composer --pure --run 'composer install'
// git clone https://github.com/FreshRSS/FreshRSS.git vendor/freshrss

require_once dirname(__FILE__) . '/../../vendor/autoload.php';


class FreshrssOllamaExtension extends Minz_Extension
{
    private const LOG_PREFIX = 'FreshRssOllama';

    public function init(): void
    {
        Minz_Log::debug(self::LOG_PREFIX . ': Initializing');
        $this->registerHook('entry_before_insert', array($this, 'processEntry'));
    }

    public function handleConfigureAction(): void
    {
        Minz_Log::debug(self::LOG_PREFIX . ': handleConfigureAction called');
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            Minz_Log::debug(self::LOG_PREFIX . ': Processing configuration form submission');

            // Get and log form values
            $chrome_host = Minz_Request::paramString('chrome_host', 'localhost');
            $chrome_port = Minz_Request::paramInt('chrome_port', 9222);
            $ollama_host = Minz_Request::paramString('ollama_host', 'http://localhost:11434');
            $ollama_model = Minz_Request::paramString('ollama_model', 'llama3');
            $num_tags = Minz_Request::paramInt('num_tags', 5);
            $summary_length = Minz_Request::paramInt('summary_length', 150);

            // Strip trailing slash from ollama_host
            $ollama_host = rtrim($ollama_host, '/');

            Minz_Log::debug(self::LOG_PREFIX . ": Config values - Chrome: $chrome_host:$chrome_port, Ollama: $ollama_host, Model: $ollama_model");

            // Save configuration
            FreshRSS_Context::$user_conf->freshrss_ollama_chrome_host = $chrome_host;
            FreshRSS_Context::$user_conf->freshrss_ollama_chrome_port = $chrome_port;
            FreshRSS_Context::$user_conf->freshrss_ollama_ollama_host = $ollama_host;
            FreshRSS_Context::$user_conf->freshrss_ollama_ollama_model = $ollama_model;
            FreshRSS_Context::$user_conf->freshrss_ollama_num_tags = $num_tags;
            FreshRSS_Context::$user_conf->freshrss_ollama_summary_length = $summary_length;

            try {
                $saved = FreshRSS_Context::$user_conf->save();
                Minz_Log::debug(self::LOG_PREFIX . ': Configuration saved: ' . ($saved ? 'success' : 'failed'));

                if (!$saved) {
                    throw new Exception('Failed to save configuration');
                }

                Minz_Request::good(_t('feedback.conf.updated'));
            } catch (Exception $e) {
                Minz_Log::error(self::LOG_PREFIX . ': Error saving configuration: ' . $e->getMessage());
                Minz_Request::bad(_t('feedback.conf.error'));
            }
        } else {
            Minz_Log::debug(self::LOG_PREFIX . ': Displaying configuration form');
        }
    }

    public function processEntry(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        Minz_Log::debug(self::LOG_PREFIX . ': Processing entry: ' . json_encode($entry->toArray()));

        // Get current tags as an array
        $tags = $entry->tags();
        Minz_Log::debug(self::LOG_PREFIX . ': Current tags: ' . json_encode($tags));

        // Skip if already processed
        if (in_array('ai-processed', $tags)) {
            Minz_Log::debug(self::LOG_PREFIX . ': Entry already processed, skipping');
            return $entry;
        }

        // Check if ai-summary div is present in the content using proper HTML parsing
        $dom = new DOMDocument();
        // Suppress warnings about invalid HTML
        @$dom->loadHTML($entry->content(), LIBXML_NOERROR);
        $xpath = new DOMXPath($dom);
        $aiSummaryDivs = $xpath->query('//div[contains(@class, "ai-summary")]');

        if ($aiSummaryDivs->length > 0) {
            Minz_Log::debug(self::LOG_PREFIX . ': Entry already processed (found ai-summary div), skipping');
            return $entry;
        }

        // Get the URL from the entry
        $url = $entry->link();

        if (empty($url)) {
            Minz_Log::debug(self::LOG_PREFIX . ': No URL found, skipping');
            return $entry;
        }

        try {
            Minz_Log::debug(self::LOG_PREFIX . ': Fetching content for URL: ' . $url);
            // Fetch full content using Chrome with WebSocket
            $content = $this->fetchContentWithChromeWebSocket($url);

            if (!empty($content)) {
                Minz_Log::debug(self::LOG_PREFIX . ': Content fetched successfully, length: ' . strlen($content));

                // Generate tags and summary using Ollama
                Minz_Log::debug(self::LOG_PREFIX . ': Sending content to Ollama');
                $ollamaResponse = $this->generateOllamaSummary($content);

                if (!empty($ollamaResponse)) {
                    Minz_Log::debug(self::LOG_PREFIX . ': Ollama response received, length: ' . strlen($ollamaResponse));

                    // Update the entry with tags and summary
                    Minz_Log::debug(self::LOG_PREFIX . ': Updating entry with summary and tags');
                    $this->updateEntryWithSummary($entry, $ollamaResponse);
                } else {
                    Minz_Log::debug(self::LOG_PREFIX . ': Empty response from Ollama');
                }
            } else {
                Minz_Log::debug(self::LOG_PREFIX . ': No content fetched from URL');
            }

            // Mark as processed to avoid reprocessing
            $tags = $entry->tags();
            if (!in_array('ai-processed', $tags)) {
                $tags[] = 'ai-processed';
                $entry->_tags($tags);
                Minz_Log::debug(self::LOG_PREFIX . ': Marked entry as processed');
            }

        } catch (Exception $e) {
            Minz_Log::error(self::LOG_PREFIX . ' error: ' . $e->getMessage());
            Minz_Log::error(self::LOG_PREFIX . ' stack trace: ' . $e->getTraceAsString());

            // Re-throw the exception so the caller can handle it
            throw $e;
        }

        Minz_Log::debug(self::LOG_PREFIX . ': Finished processing entry ' . json_encode($entry->toArray()));

        return $entry;
    }

    /**
     * Fetch content from a URL using Chrome DevTools Protocol via WebSocket
     *
     * @param string $url The URL to fetch content from
     * @return string The content of the page
     */
    private function fetchContentWithChromeWebSocket(string $url): string
    {
        Minz_Log::debug(self::LOG_PREFIX . ': Connecting to Chrome DevTools via WebSocket');

        // Chrome DevTools WebSocket endpoint (typically on port 9222)
        $devtoolsHost = $this->config['chrome_host'] ?? 'localhost';
        $devtoolsPort = $this->config['chrome_port'] ?? 9222;

        // Create a new target/page if none found
        Minz_Log::debug(self::LOG_PREFIX . ': No available targets found, creating a new one');

        $ch = curl_init("http://{$devtoolsHost}:{$devtoolsPort}/json/new");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT"); // Using PUT method instead of GET
        $createResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        Minz_Log::debug(self::LOG_PREFIX . ': Create new target response: ' . $createResponse);
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

        Minz_Log::debug(self::LOG_PREFIX . ': WebSocket URL: ' . $wsUrl);
        Minz_Log::debug(self::LOG_PREFIX . ': Target ID: ' . $targetId);

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
            Minz_Log::debug(self::LOG_PREFIX . ': Sending navigation command: ' . $navigateMessage);
            $client->send($navigateMessage);

            // Wait for navigation to complete
            $navigationComplete = false;
            while (!$navigationComplete) {
                $response = $client->receive();
                $data = json_decode($response, true);
                Minz_Log::debug(self::LOG_PREFIX . ': Received response: ' . $response);

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
            Minz_Log::debug(self::LOG_PREFIX . ': Sending article evaluation command: ' . $articleEvalMessage);
            $client->send($articleEvalMessage);

            // Get the evaluation result
            $content = '';
            $evalComplete = false;
            while (!$evalComplete) {
                $response = $client->receive();
                $data = json_decode($response, true);
                Minz_Log::debug(self::LOG_PREFIX . ': Received evaluation response: ' . $response);

                if (isset($data['id']) && $data['id'] === 2) {
                    if (isset($data['result']['result']['value'])) {
                        $content = $data['result']['result']['value'];
                    }
                    $evalComplete = true;
                }
            }

            return $content;
        } finally {
            Minz_Log::debug(self::LOG_PREFIX . ': Starting cleanup process');

            try {
                // Close the WebSocket connection
                if (isset($client)) {
                    Minz_Log::debug(self::LOG_PREFIX . ': Closing WebSocket connection');
                    $client->close();
                }

                // Close the Chrome tab if we have a target ID
                if (!empty($targetId)) {
                    Minz_Log::debug(self::LOG_PREFIX . ': Closing Chrome tab with ID: ' . $targetId);

                    $ch = curl_init("http://{$devtoolsHost}:{$devtoolsPort}/json/close/{$targetId}");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Add timeout to prevent hanging

                    $result = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                    if ($result === false) {
                        Minz_Log::error(self::LOG_PREFIX . ': Error closing Chrome tab: ' . curl_error($ch));
                    } else {
                        Minz_Log::debug(self::LOG_PREFIX . ': Chrome tab closed successfully (HTTP ' . $httpCode . ')');
                    }

                    curl_close($ch);
                } else {
                    Minz_Log::warning(self::LOG_PREFIX . ': No target ID available for cleanup');
                }
            } catch (Exception $e) {
                Minz_Log::error(self::LOG_PREFIX . ': Error during cleanup: ' . $e->getMessage());
            }
        }
    }

    private function generateOllamaSummary(string $content): string
    {
        Minz_Log::debug(self::LOG_PREFIX . ': Starting Ollama summary generation');

        // Validate content
        if (empty($content)) {
            Minz_Log::error(self::LOG_PREFIX . ': Empty content provided to generateOllamaSummary');
            throw new Exception("No content provided for Ollama to summarize");
        }

        Minz_Log::debug(self::LOG_PREFIX . ': Content length: ' . strlen($content) . ' bytes');

        $ollamaHost = FreshRSS_Context::$user_conf->freshrss_ollama_ollama_host ?? 'http://localhost:11434';
        $ollamaHost = rtrim($ollamaHost, '/');
        $ollamaModel = FreshRSS_Context::$user_conf->freshrss_ollama_ollama_model ?? 'llama3';

        // First, check if this is a valid article
        $validationPrompt = <<<EOT
Analyze the following content and determine if it is a valid article. A valid article should:
- Have substantial content (not just a few sentences)
- Not be a login page, consent screen, or error page
- Not be a list of links or navigation elements
- Not be a placeholder or redirect page
- Not be a captcha or security check page

If the content is NOT a valid article, respond with exactly: INVALID_CONTENT
If the content IS a valid article, respond with exactly: VALID_CONTENT

Content to analyze:
$content
EOT;

        $validationResponse = $this->callOllama($ollamaHost, $ollamaModel, $validationPrompt, false);

        if (trim($validationResponse) === 'INVALID_CONTENT') {
            Minz_Log::debug(self::LOG_PREFIX . ': Content validation failed - not a valid article');
            return 'PLACEHOLDER_PAGE';
        }

        if (trim($validationResponse) !== 'VALID_CONTENT') {
            Minz_Log::debug(self::LOG_PREFIX . ': Unexpected validation response: ' . $validationResponse);
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

    private function callOllama(string $ollamaHost, string $model, string $prompt, bool $stream = false): string
    {
        $apiEndpoint = "$ollamaHost/api/generate";
        Minz_Log::debug(self::LOG_PREFIX . ": Sending request to Ollama at $apiEndpoint");

        // Ensure prompt is properly encoded
        $prompt = mb_convert_encoding($prompt, 'UTF-8', 'auto');

        $data = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => $stream
        ];

        // Use JSON_UNESCAPED_UNICODE to properly handle Unicode characters
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Minz_Log::debug(self::LOG_PREFIX . ": Request payload: " . $jsonData);

        // Debug data structure before encoding
        Minz_Log::debug(self::LOG_PREFIX . ": Data structure before JSON encoding: " . print_r($data, true));

        // Check if JSON encoding succeeded
        if ($jsonData === false) {
            Minz_Log::error(self::LOG_PREFIX . ": JSON encoding failed: " . json_last_error_msg());
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
            Minz_Log::debug(self::LOG_PREFIX . ": Ollama response (HTTP $httpCode): " . substr($result, 0, 500) . "...");

            // Log verbose cURL output
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            Minz_Log::debug(self::LOG_PREFIX . ": cURL verbose output: " . $verboseLog);

            if ($result === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new Exception("Failed to connect to Ollama service: " . $error);
            }

            curl_close($ch);

            $response = json_decode($result, true);

            if (!isset($response['response'])) {
                Minz_Log::debug(self::LOG_PREFIX . ": Unexpected response format: " . json_encode($response));
                throw new Exception("Unexpected response format from Ollama");
            }

            Minz_Log::debug(self::LOG_PREFIX . ": Received Ollama response, length: " . strlen($response['response']));
            return $response['response'] ?? '';
        } catch (Exception $e) {
            Minz_Log::error(self::LOG_PREFIX . ": Ollama error: " . $e->getMessage());
            throw $e;
        }
    }

    private function updateEntryWithSummary(FreshRSS_Entry $entry, string $ollamaResponse): void
    {
        Minz_Log::debug(self::LOG_PREFIX . ': Updating entry with summary and tags');

        // Check if this is a placeholder page response
        if (trim($ollamaResponse) === 'PLACEHOLDER_PAGE') {
            Minz_Log::debug(self::LOG_PREFIX . ': Detected placeholder page, skipping summary and tags');

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
        Minz_Log::debug(self::LOG_PREFIX . ': Parsing Ollama response: ' . substr($ollamaResponse, 0, 100) . '...');

        if (preg_match('/SUMMARY:\s*(.*?)(?:\r?\n|$)/s', $ollamaResponse, $summaryMatch)) {
            $summary = trim($summaryMatch[1]);
            Minz_Log::debug(self::LOG_PREFIX . ': Extracted summary: ' . substr($summary, 0, 100) . '...');
        } else {
            Minz_Log::debug(self::LOG_PREFIX . ': No summary found in response');

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
            Minz_Log::debug(self::LOG_PREFIX . ': Original content length: ' . strlen($content));

            $updatedContent = $content . '<hr/><div class="ai-summary"><strong>Summary:</strong> ' . htmlspecialchars($summary) . '</div><hr>';
            $entry->_content($updatedContent);

            Minz_Log::debug(self::LOG_PREFIX . ': Updated content length: ' . strlen($updatedContent));
        }

        // Add tags if found
        if (preg_match('/TAGS:\s*(.*?)(?:\r?\n|$)/s', $ollamaResponse, $tagsMatch)) {
            $tagsList = trim($tagsMatch[1]);
            $tags = array_map('trim', explode(',', $tagsList));
            Minz_Log::debug(self::LOG_PREFIX . ': Extracted tags: ' . json_encode($tags));

            if (!empty($tags)) {
                $currentTags = $entry->tags();
                Minz_Log::debug(self::LOG_PREFIX . ': Current tags: ' . json_encode($currentTags));

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

                Minz_Log::debug(self::LOG_PREFIX . ': Added tags: ' . json_encode($addedTags));
                Minz_Log::debug(self::LOG_PREFIX . ': Final tags: ' . json_encode($uniqueTags));
            }
        }
    }
}
