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
require_once dirname(__FILE__) . '/constants.php';
require_once dirname(__FILE__) . '/Logger.php';
require_once dirname(__FILE__) . '/WebpageFetcher.php';
require_once dirname(__FILE__) . '/OllamaClient.php';
require_once dirname(__FILE__) . '/EntryProcessor.php';

class FreshrssOllamaExtension extends Minz_Extension
{
    private ?EntryProcessor $processor = null;

    public function init(): void
    {
        Minz_Log::debug(LOG_PREFIX . ': Initializing');
        $this->registerHook('entry_before_insert', array($this, 'processEntry'));
        $this->registerHook('entry_before_display', array($this, 'modifyEntryDisplay'));
    }

    private function getProcessor(): EntryProcessor
    {
        if ($this->processor === null) {
            $logger = new Logger(LOG_PREFIX);
            $webpageFetcher = new WebpageFetcher(
                $logger,
                FreshRSS_Context::$user_conf->freshrss_ollama_chrome_host ?? 'localhost',
                FreshRSS_Context::$user_conf->freshrss_ollama_chrome_port ?? 9222
            );
            $ollamaClient = new OllamaClientImpl(
                $logger,
                FreshRSS_Context::$user_conf->freshrss_ollama_ollama_host ?? 'http://localhost:11434',
                FreshRSS_Context::$user_conf->freshrss_ollama_ollama_model ?? 'llama3',
                FreshRSS_Context::$user_conf->freshrss_ollama_model_options ?? [],
                FreshRSS_Context::$user_conf->freshrss_ollama_context_length ?? 8192
            );
            $this->processor = new EntryProcessor($logger, $webpageFetcher, $ollamaClient);
        }
        return $this->processor;
    }

    public function handleConfigureAction(): void
    {
        Minz_Log::debug(LOG_PREFIX . ': handleConfigureAction called');
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            Minz_Log::debug(LOG_PREFIX . ': Processing configuration form submission');

            // Get and log form values
            $chrome_host = Minz_Request::paramString('chrome_host');
            $chrome_port = Minz_Request::paramInt('chrome_port');
            $ollama_host = Minz_Request::paramString('ollama_host');
            $ollama_model = Minz_Request::paramString('ollama_model');
            $num_tags = Minz_Request::paramInt('num_tags');
            $summary_length = Minz_Request::paramInt('summary_length');
            $context_length = Minz_Request::paramInt('context_length');

            // Strip trailing slash from ollama_host
            $ollama_host = rtrim($ollama_host, '/');

            Minz_Log::debug(LOG_PREFIX . ": Config values - Chrome: $chrome_host:$chrome_port, Ollama: $ollama_host, Model: $ollama_model");

            // Save configuration
            FreshRSS_Context::$user_conf->freshrss_ollama_chrome_host = $chrome_host;
            FreshRSS_Context::$user_conf->freshrss_ollama_chrome_port = $chrome_port;
            FreshRSS_Context::$user_conf->freshrss_ollama_ollama_host = $ollama_host;
            FreshRSS_Context::$user_conf->freshrss_ollama_ollama_model = $ollama_model;
            FreshRSS_Context::$user_conf->freshrss_ollama_num_tags = $num_tags;
            FreshRSS_Context::$user_conf->freshrss_ollama_summary_length = $summary_length;
            FreshRSS_Context::$user_conf->freshrss_ollama_context_length = $context_length;

            try {
                $saved = FreshRSS_Context::$user_conf->save();
                Minz_Log::debug(LOG_PREFIX . ': Configuration saved: ' . ($saved ? 'success' : 'failed'));

                if (!$saved) {
                    throw new Exception('Failed to save configuration');
                }

                // Reset processor to use new configuration
                $this->processor = null;

                Minz_Request::good(_t('feedback.conf.updated'));
            } catch (Exception $e) {
                Minz_Log::error(LOG_PREFIX . ': Error saving configuration: ' . $e->getMessage());
                Minz_Request::bad(_t('feedback.conf.error'));
            }
        } else {
            Minz_Log::debug(LOG_PREFIX . ': Displaying configuration form');
        }
    }

    public function handleTestFetchAction(): void
    {
        if (!Minz_Request::isPost()) {
            Minz_Request::bad(_t('feedback.access.denied'));
            return;
        }

        $url = Minz_Request::paramString('url');
        if (empty($url)) {
            Minz_Request::bad(_t('feedback.invalid_url'));
            return;
        }

        try {
            $logger = new Logger(LOG_PREFIX);
            $fetcher = new WebpageFetcher(
                $logger,
                FreshRSS_Context::$user_conf->freshrss_ollama_chrome_host ?? 'localhost',
                FreshRSS_Context::$user_conf->freshrss_ollama_chrome_port ?? 9222
            );

            $content = $fetcher->fetchContent($url);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'content' => $content
            ]);
            exit;
        } catch (Exception $e) {
            Minz_Log::error(LOG_PREFIX . ': Error testing fetch: ' . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }

    public function processEntry(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        return $this->getProcessor()->processEntry($entry);
    }

    public function modifyEntryDisplay(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        if (!$entry->hasAttribute('ai-processed')) {
            return $entry;
        }

        $content = $entry->content();
        $summary = $entry->attributeString('ai-summary');
        $debugInfo = $entry->attributeString('ai-debug');

        if (!empty($summary)) {
            $content .= '<hr/><div class="ai-summary"><strong>Summary:</strong> ' . htmlspecialchars($summary) . '</div><hr>';
        }

        if (!empty($debugInfo)) {
            $debugArray = json_decode($debugInfo, true);
            if ($debugArray !== null) {
                $content .= '<details class="ai-debug"><summary>Debug Information: Original Content</summary><pre>' . htmlspecialchars($debugArray['content'] ?? '') . '</pre></details>';
                
                $ollamaResponse = $debugArray['ollamaResponse'] ?? '';
                if (is_array($ollamaResponse)) {
                    $ollamaResponse = json_encode($ollamaResponse, JSON_PRETTY_PRINT);
                }
                $content .= '<details class="ai-debug"><summary>Debug Information: Ollama Response</summary><pre>' . htmlspecialchars($ollamaResponse) . '</pre></details>';
            }
        }

        $entry->_content($content);
        return $entry;
    }
}
