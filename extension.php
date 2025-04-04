<?php

/**
 * Name: Ollama Summarizer
 * Author: Claude
 * Description: Fetches article content using Chrome and uses Ollama to generate tags and summaries
 * Version: 0.1.1
 */

// Extensions guide
// https://freshrss.github.io/FreshRSS/en/developers/03_Backend/05_Extensions.html
//
// TODO:
// - Tests (need to implement dependency injection of fetchers first to avoid mocking stuff)
// - Handle trailing slash in ollama URL
// - Consistent naming (primarily of config variables)
// - Examples of using with docker (chrome + ollama + freshrss)
// - Fix author
// - Set up builds
// - Handle situations when ollama is not available
// - Handle ollama auth
// - Very thorough logging of all the requests and responses in debug mode
// - i don't need cookies extension for chrome
// - set up vim lsp for php

class FreshrssOllamaExtension extends Minz_Extension
{
    public function init()
    {
        Minz_Log::debug('FreshrssOllamaExtension: Initializing');
        $this->registerHook('entry_before_insert', array($this, 'processEntry'));
    }

    public function handleConfigureAction()
    {
        Minz_Log::debug('FreshrssOllamaExtension: handleConfigureAction called');
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            Minz_Log::debug('FreshrssOllamaExtension: Processing configuration form submission');

            // Get and log form values
            $chrome_host = Minz_Request::param('chrome_host', 'localhost');
            $chrome_port = Minz_Request::param('chrome_port', 9222);
            $ollama_host = Minz_Request::param('ollama_host', 'http://localhost:11434');
            $ollama_model = Minz_Request::param('ollama_model', 'llama3');
            $num_tags = Minz_Request::param('num_tags', 5);
            $summary_length = Minz_Request::param('summary_length', 150);

            Minz_Log::debug("FreshrssOllamaExtension: Config values - Chrome: $chrome_host:$chrome_port, Ollama: $ollama_host, Model: $ollama_model");

            // Save configuration
            FreshRSS_Context::$user_conf->freshrss_ollama_chrome_host = $chrome_host;
            FreshRSS_Context::$user_conf->freshrss_ollama_chrome_port = $chrome_port;
            FreshRSS_Context::$user_conf->freshrss_ollama_ollama_host = $ollama_host;
            FreshRSS_Context::$user_conf->freshrss_ollama_ollama_model = $ollama_model;
            FreshRSS_Context::$user_conf->freshrss_ollama_num_tags = $num_tags;
            FreshRSS_Context::$user_conf->freshrss_ollama_summary_length = $summary_length;

            try {
                $saved = FreshRSS_Context::$user_conf->save();
                Minz_Log::debug('FreshrssOllamaExtension: Configuration saved: ' . ($saved ? 'success' : 'failed'));

                if (!$saved) {
                    throw new Exception('Failed to save configuration');
                }

                Minz_Request::good(_t('feedback.conf.updated'));
            } catch (Exception $e) {
                Minz_Log::error('FreshrssOllamaExtension: Error saving configuration: ' . $e->getMessage());
                Minz_Request::bad(_t('feedback.conf.error'));
            }
        } else {
            Minz_Log::debug('FreshrssOllamaExtension: Displaying configuration form');
        }
    }

    public function processEntry($entry)
    {
        // https://github.com/FreshRSS/FreshRSS/issues/2688#issuecomment-560268673
        // if ($entry->id() != null) {
        //     return $entry;
        // }

        Minz_Log::debug('FreshrssOllamaExtension: Processing entry: ' . $entry->title());

        // Get current tags as an array
        $tags = $entry->tags();
        Minz_Log::debug('FreshrssOllamaExtension: Current tags: ' . json_encode($tags));

        // Skip if already processed
        if (in_array('ollama-processed', $tags)) {
            Minz_Log::debug('FreshrssOllamaExtension: Entry already processed, skipping');
            return $entry;
        }

        // Get the URL from the entry
        $url = $entry->link();

        if (empty($url)) {
            Minz_Log::debug('FreshrssOllamaExtension: No URL found, skipping');
            return $entry;
        }

        try {
            Minz_Log::debug('FreshrssOllamaExtension: Fetching content for URL: ' . $url);
            // Fetch full content using Chrome
            $content = $this->fetchContentWithChrome($url);

            if (!empty($content)) {
                Minz_Log::debug('FreshrssOllamaExtension: Content fetched successfully, length: ' . strlen($content));

                // Generate tags and summary using Ollama
                Minz_Log::debug('FreshrssOllamaExtension: Sending content to Ollama');
                $ollamaResponse = $this->generateOllamaSummary($content);

                if (!empty($ollamaResponse)) {
                    Minz_Log::debug('FreshrssOllamaExtension: Ollama response received, length: ' . strlen($ollamaResponse));

                    // Update the entry with tags and summary
                    Minz_Log::debug('FreshrssOllamaExtension: Updating entry with summary and tags');
                    $this->updateEntryWithSummary($entry, $ollamaResponse);
                } else {
                    Minz_Log::debug('FreshrssOllamaExtension: Empty response from Ollama');
                }
            } else {
                Minz_Log::debug('FreshrssOllamaExtension: No content fetched from URL');
            }

            // Mark as processed to avoid reprocessing
            $tags = $entry->tags();
            $tags[] = 'ollama-processed';
            $entry->_tags($tags);
            Minz_Log::debug('FreshrssOllamaExtension: Marked entry as processed');

        } catch (Exception $e) {
            // Log error but don't stop processing
            Minz_Log::error('FreshrssOllamaExtension error: ' . $e->getMessage());
            Minz_Log::error('FreshrssOllamaExtension stack trace: ' . $e->getTraceAsString());
        }

        Minz_Log::debug('FreshrssOllamaExtension: Finished processing entry ' . json_encode($entry->toArray()));

        if (!($entry instanceof FreshRSS_Entry)) {
            Minz_Log::debug('FreshrssOllamaExtension: NOOOOOOOOOOOOOOOOOOOOOOOOO');
        }
        return $entry;
    }

    private function fetchContentWithChrome($url)
    {
        Minz_Log::debug('FreshrssOllamaExtension: Starting Chrome content fetch');

        $chromeHost = FreshRSS_Context::$user_conf->freshrss_ollama_chrome_host ?? 'localhost';
        $chromePort = FreshRSS_Context::$user_conf->freshrss_ollama_chrome_port ?? 9222;

        Minz_Log::debug("FreshrssOllamaExtension: Chrome settings - Host: $chromeHost, Port: $chromePort");

        try {
            // Step 1: Create a new tab with the target URL
            $createTabUrl = "http://$chromeHost:$chromePort/json/new?url=" . urlencode($url);
            Minz_Log::debug("FreshrssOllamaExtension: Creating new tab with URL: $createTabUrl");

            $ch = curl_init($createTabUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            $tabJson = curl_exec($ch);

            if ($tabJson === false) {
                $error = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                throw new Exception("Failed to create Chrome tab: $error (HTTP code: $httpCode). Make sure Chrome is running with --remote-debugging-port=$chromePort");
            }
            curl_close($ch);

            $tab = json_decode($tabJson, true);

            if (empty($tab) || !isset($tab['id'])) {
                throw new Exception("Invalid response when creating tab: " . substr($tabJson, 0, 100));
            }

            $tabId = $tab['id'];
            Minz_Log::debug("FreshrssOllamaExtension: Created tab with ID: $tabId");

            // Step 2: Wait for page to load
            Minz_Log::debug("FreshrssOllamaExtension: Waiting for page to load");
            sleep(5);

            // Step 3: Use evaluate JavaScript endpoint to get the page content
            // First, make sure the tab is active
            $activateUrl = "http://$chromeHost:$chromePort/json/activate/$tabId";
            $ch = curl_init($activateUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_exec($ch);
            curl_close($ch);

            // Use the DevTools Protocol to execute JavaScript in the page context
            $evalUrl = "http://$chromeHost:$chromePort/json";
            $evalData = [
                'id' => 1,
                'method' => 'Runtime.evaluate',
                'params' => [
                    'expression' => 'document.documentElement.innerText',
                    'returnByValue' => true
                ]
            ];

            $ch = curl_init($evalUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($evalData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $evalResult = curl_exec($ch);
            curl_close($ch);

            $content = "";

            if ($evalResult !== false) {
                $evalResponse = json_decode($evalResult, true);
                if (isset($evalResponse['result']['result']['value'])) {
                    $content = $evalResponse['result']['result']['value'];
                    Minz_Log::debug("FreshrssOllamaExtension: Successfully extracted content, length: " . strlen($content));
                } else {
                    Minz_Log::debug("FreshrssOllamaExtension: Failed to extract content via JavaScript evaluation");
                }
            }

            // Step 4: If JavaScript evaluation failed, fall back to a direct request
            if (empty($content)) {
                Minz_Log::debug("FreshrssOllamaExtension: Falling back to direct HTTP request");

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36');
                $htmlContent = curl_exec($ch);
                curl_close($ch);

                if ($htmlContent !== false) {
                    // Extract text content from HTML using basic PHP functions
                    $content = strip_tags($htmlContent);
                    $content = preg_replace('/\s+/', ' ', $content);
                    $content = trim($content);
                    Minz_Log::debug("FreshrssOllamaExtension: Content extracted from direct request, length: " . strlen($content));
                }
            }

            // Step 5: Close the tab we created
            $closeUrl = "http://$chromeHost:$chromePort/json/close/$tabId";
            Minz_Log::debug("FreshrssOllamaExtension: Closing tab: $closeUrl");

            $ch = curl_init($closeUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_exec($ch);
            curl_close($ch);

            return $content;
        } catch (Exception $e) {
            Minz_Log::error("FreshrssOllamaExtension: Chrome fetch error: " . $e->getMessage());
            Minz_Log::error("FreshrssOllamaExtension: Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    private function generateOllamaSummary($content)
    {
        Minz_Log::debug('FreshrssOllamaExtension: Starting Ollama summary generation');

        $ollamaHost = FreshRSS_Context::$user_conf->freshrss_ollama_ollama_host ?? 'http://localhost:11434';
        $ollamaModel = FreshRSS_Context::$user_conf->freshrss_ollama_ollama_model ?? 'llama3';
        $numTags = FreshRSS_Context::$user_conf->freshrss_ollama_num_tags ?? 5;
        $summaryLength = FreshRSS_Context::$user_conf->freshrss_ollama_summary_length ?? 150;

        Minz_Log::debug("FreshrssOllamaExtension: Ollama settings - Host: $ollamaHost, Model: $ollamaModel");

        // Truncate content if too long (most models have context limits)
        $maxContent = 4000;
        if (strlen($content) > $maxContent) {
            Minz_Log::debug("FreshrssOllamaExtension: Content too long, truncating from " . strlen($content) . " to $maxContent chars");
            $content = substr($content, 0, $maxContent);
        }

        $prompt = <<<EOT
Based on the following article content, please provide:
1. A concise summary (around $summaryLength words)
2. $numTags relevant tags (single words or short phrases)

Format your response exactly like this:
SUMMARY: [your summary here]
TAGS: [tag1], [tag2], [tag3], ...

Article content:
$content
EOT;

        $data = [
            'model' => $ollamaModel,
            'prompt' => $prompt,
            'stream' => false
        ];

        Minz_Log::debug("FreshrssOllamaExtension: Sending request to Ollama at $ollamaHost/api/generate");

        try {
            $ch = curl_init("$ollamaHost/api/generate");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            $result = curl_exec($ch);
            Minz_Log::debug("FreshrssOllamaExtension: Ollama response: " . $result);

            if ($result === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new Exception("Failed to connect to Ollama service: " . $error);
            }

            curl_close($ch);

            $response = json_decode($result, true);

            if (!isset($response['response'])) {
                Minz_Log::debug("FreshrssOllamaExtension: Unexpected response format: " . json_encode($response));
                throw new Exception("Unexpected response format from Ollama");
            }

            Minz_Log::debug("FreshrssOllamaExtension: Received Ollama response, length: " . strlen($response['response']));
            return $response['response'] ?? '';
        } catch (Exception $e) {
            Minz_Log::error("FreshrssOllamaExtension: Ollama error: " . $e->getMessage());
            throw $e;
        }
    }

    private function updateEntryWithSummary($entry, $ollamaResponse)
    {
        Minz_Log::debug('FreshrssOllamaExtension: Updating entry with summary and tags');

        // Extract summary and tags from Ollama response
        $summary = '';
        $tags = [];

        // Parse the response
        Minz_Log::debug('FreshrssOllamaExtension: Parsing Ollama response: ' . substr($ollamaResponse, 0, 100) . '...');

        if (preg_match('/SUMMARY:\s*(.*?)(?:\r?\n|$)/s', $ollamaResponse, $summaryMatch)) {
            $summary = trim($summaryMatch[1]);
            Minz_Log::debug('FreshrssOllamaExtension: Extracted summary: ' . substr($summary, 0, 100) . '...');
        } else {
            Minz_Log::debug('FreshrssOllamaExtension: No summary found in response');
        }

        if (preg_match('/TAGS:\s*(.*?)(?:\r?\n|$)/s', $ollamaResponse, $tagsMatch)) {
            $tagsList = trim($tagsMatch[1]);
            $tags = array_map('trim', explode(',', $tagsList));
            Minz_Log::debug('FreshrssOllamaExtension: Extracted tags: ' . json_encode($tags));
        } else {
            Minz_Log::debug('FreshrssOllamaExtension: No tags found in response');
        }

        // Update entry summary if found
        if (!empty($summary)) {
            // Prepend the summary to the content
            $content = $entry->content();
            Minz_Log::debug('FreshrssOllamaExtension: Original content length: ' . strlen($content));

            $updatedContent = $content . '<hr/><div class="ollama-summary"><strong>Summary:</strong> ' . htmlspecialchars($summary) . '</div><hr>';
            $entry->_content($updatedContent);

            Minz_Log::debug('FreshrssOllamaExtension: Updated content length: ' . strlen($updatedContent));
        }

        // Add tags if found
        if (!empty($tags)) {
            $currentTags = $entry->tags();
            Minz_Log::debug('FreshrssOllamaExtension: Current tags: ' . json_encode($currentTags));

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

            Minz_Log::debug('FreshrssOllamaExtension: Added tags: ' . json_encode($addedTags));
            Minz_Log::debug('FreshrssOllamaExtension: Final tags: ' . json_encode($uniqueTags));
        }
    }
}
