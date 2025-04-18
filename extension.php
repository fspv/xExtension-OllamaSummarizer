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
require_once dirname(__FILE__) . '/EntryProcessor.php';

class FreshrssOllamaExtension extends Minz_Extension
{
    public function init(): void
    {
        Minz_Log::debug(LOG_PREFIX . ': Initializing');
        $this->registerHook('entry_before_insert', array($this, 'processEntry'));
    }

    public function handleConfigureAction(): void
    {
        Minz_Log::debug(LOG_PREFIX . ': handleConfigureAction called');
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            Minz_Log::debug(LOG_PREFIX . ': Processing configuration form submission');

            // Get and log form values
            $chrome_host = Minz_Request::paramString('chrome_host', 'localhost');
            $chrome_port = Minz_Request::paramInt('chrome_port', 9222);
            $ollama_host = Minz_Request::paramString('ollama_host', 'http://localhost:11434');
            $ollama_model = Minz_Request::paramString('ollama_model', 'llama3');
            $num_tags = Minz_Request::paramInt('num_tags', 5);
            $summary_length = Minz_Request::paramInt('summary_length', 150);

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

            try {
                $saved = FreshRSS_Context::$user_conf->save();
                Minz_Log::debug(LOG_PREFIX . ': Configuration saved: ' . ($saved ? 'success' : 'failed'));

                if (!$saved) {
                    throw new Exception('Failed to save configuration');
                }

                Minz_Request::good(_t('feedback.conf.updated'));
            } catch (Exception $e) {
                Minz_Log::error(LOG_PREFIX . ': Error saving configuration: ' . $e->getMessage());
                Minz_Request::bad(_t('feedback.conf.error'));
            }
        } else {
            Minz_Log::debug(LOG_PREFIX . ': Displaying configuration form');
        }
    }

    public function processEntry(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        $processor = new EntryProcessor();
        return $processor->processEntry($entry);
    }
}
