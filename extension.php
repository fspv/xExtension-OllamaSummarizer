<?php

declare(strict_types=1);

/**
 * Name: Ollama Summarizer
 * Author: Pavel Safronov
 * Description: Fetches article content using Chrome and uses Ollama to generate tags and summaries
 * Version: 0.1.1.
 */

// Extensions guide
// https://freshrss.github.io/FreshRSS/en/developers/03_Backend/05_Extensions.html
//
// TODO:
// - Set up builds
// - Handle ollama auth
// - move retry settings to config
//
// nix-shell -p php83Packages.php-cs-fixer --pure --command 'php-cs-fixer fix extension.php'
// nix-shell -p php83Packages.composer --pure --run 'composer install'
// git clone https://github.com/FreshRSS/FreshRSS.git vendor/freshrss

$relativeAutoloadFile = dirname(__FILE__) . '/../../vendor/autoload.php';
$topLevelAutoloadFile = dirname(__FILE__) . '/vendor/autoload.php';

if (file_exists($relativeAutoloadFile)) {
    require_once $relativeAutoloadFile;
} elseif (file_exists($topLevelAutoloadFile)) {
    require_once $topLevelAutoloadFile;
}

require_once dirname(__FILE__) . '/constants.php';
require_once dirname(__FILE__) . '/Logger.php';
require_once dirname(__FILE__) . '/WebpageFetcher.php';
require_once dirname(__FILE__) . '/OllamaClient.php';
require_once dirname(__FILE__) . '/EntryProcessor.php';
require_once dirname(__FILE__) . '/Configuration.php';

class OllamaSummarizerExtension extends Minz_Extension
{
    private ?EntryProcessor $processor = null;

    private ?Configuration $configuration = null;

    public function init(): void
    {
        Minz_Log::debug(LOG_PREFIX . ': Initializing');
        $this->registerHook('entry_before_insert', [$this, 'processEntry']);
        $this->registerHook('entry_before_display', [$this, 'modifyEntryDisplay']);
        $this->registerController('FetchAndSummarizeWithOllama');

        /** @phpstan-ignore-next-line */
        $scriptUrl = $this->getFileUrl('summarize.js', 'js');
        Minz_View::appendScript($scriptUrl, async: false);
    }

    private function getConfiguration(): Configuration
    {
        if ($this->configuration === null) {
            $userConf = FreshRSS_Context::$user_conf;
            if ($userConf === null) {
                throw new Exception('User configuration is null');
            }
            $this->configuration = Configuration::fromUserConfiguration($userConf);
        }

        return $this->configuration;
    }

    private function getProcessor(Logger $logger): EntryProcessor
    {
        $config = $this->getConfiguration();
        $webpageFetcher = new WebpageFetcher(
            $logger,
            $config->getChromeHost(),
            $config->getChromePort(),
            $config->getMaxRetries(),
            $config->getRetryDelayMilliseconds()
        );
        $ollamaClient = new OllamaClientImpl(
            $logger,
            $config->getOllamaUrl(),
            $config->getOllamaModel(),
            $config->getModelOptions(),
            $config->getContextLength(),
            $config->getPromptTemplate()
        );

        return new EntryProcessor($logger, $webpageFetcher, $ollamaClient);
    }

    public function handleConfigureAction(): void
    {
        Minz_Log::debug(LOG_PREFIX . ': handleConfigureAction called');
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            Minz_Log::debug(LOG_PREFIX . ': Processing configuration form submission');

            try {
                // Convert selected feeds to integers
                $selectedFeeds = array_map('intval', Minz_Request::paramArray('selected_feeds', false));
                $modelOptions = Minz_Request::paramArray('model_options', false);
                $modelOptionsValidated = [];
                foreach ($modelOptions as $key => $value) {
                    if (!is_string($key) || $key === '') {
                        throw new InvalidArgumentException('Model options must have string keys');
                    }
                    $modelOptionsValidated[$key] = $value;
                }

                $config = new Configuration(
                    chromeHost: Minz_Request::paramString('chrome_host'),
                    chromePort: Minz_Request::paramInt('chrome_port'),
                    ollamaUrl: rtrim(Minz_Request::paramString('ollama_url'), '/'),
                    ollamaModel: Minz_Request::paramString('ollama_model'),
                    modelOptions: $modelOptionsValidated,
                    promptLengthLimit: Minz_Request::paramInt('prompt_length_limit'),
                    contextLength: Minz_Request::paramInt('context_length'),
                    promptTemplate: Minz_Request::paramString('prompt_template'),
                    selectedFeeds: $selectedFeeds,
                    maxRetries: Minz_Request::paramInt('max_retries'),
                    retryDelayMilliseconds: Minz_Request::paramInt('retry_delay_milliseconds'),
                );

                $userConf = FreshRSS_Context::$user_conf;
                if ($userConf === null) {
                    throw new Exception('User configuration is null');
                }

                foreach ($config->toArray() as $key => $value) {
                    if (!is_string($key) || $key === '') {
                        continue;
                    }
                    $userConf->_attribute($key, $value);
                }

                $saved = $userConf->save();
                Minz_Log::debug(LOG_PREFIX . ': Configuration saved: ' . ($saved ? 'success' : 'failed'));

                if (!$saved) {
                    throw new Exception('Failed to save configuration');
                }

                // Reset processor and configuration to use new values
                $this->processor = null;
                $this->configuration = null;

                Minz_Request::good(Minz_Translate::t('feedback.conf.updated'));
            } catch (InvalidArgumentException $e) {
                Minz_Log::error(LOG_PREFIX . ': Invalid configuration: ' . $e->getMessage());
                Minz_Request::bad(Minz_Translate::t('feedback.conf.error') . ': ' . $e->getMessage());
            } catch (Exception $e) {
                Minz_Log::error(LOG_PREFIX . ': Error saving configuration: ' . $e->getMessage());
                Minz_Request::bad(Minz_Translate::t('feedback.conf.error'));
            }
        } else {
            Minz_Log::debug(LOG_PREFIX . ': Displaying configuration form');
        }
    }

    public function handleTestFetchAction(): void
    {
        if (!Minz_Request::isPost()) {
            Minz_Request::bad(Minz_Translate::t('feedback.access.denied'));

            return;
        }

        $url = Minz_Request::paramString('url');
        if (empty($url)) {
            Minz_Request::bad(Minz_Translate::t('feedback.invalid_url'));

            return;
        }

        try {
            $logger = new Logger(LOG_PREFIX);
            $config = $this->getConfiguration();
            $fetcher = new WebpageFetcher(
                $logger,
                $config->getChromeHost(),
                $config->getChromePort(),
                $config->getMaxRetries(),
                $config->getRetryDelayMilliseconds()
            );

            $content = $fetcher->fetchContent($url, 'article');

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'content' => $content,
            ]);
            exit;
        } catch (Exception $e) {
            Minz_Log::error(LOG_PREFIX . ': Error testing fetch: ' . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
            exit;
        }
    }

    public function processEntry(FreshRSS_Entry $entry, bool $force = false): FreshRSS_Entry
    {
        # Construct a unique identifier for the entry to identify processing of the same entry in the logs
        $entryId = $entry->guid();
        $entryIdHash = substr(hash('sha256', $entryId), 0, 8);
        $timestamp = round(microtime(true));
        $prefix = LOG_PREFIX . " [id:{$entryIdHash}] [start_timestamp:{$timestamp}]";
        $logger = new Logger($prefix);

        // Check if the feed is selected for processing
        $config = $this->getConfiguration();
        if (!$force && !$config->isFeedSelected($entry->feedId())) {
            $logger->debug('Feed not selected for processing, skipping');

            return $entry;
        }

        if ($this->processor === null) {
            $this->processor = $this->getProcessor($logger);
        }

        return $this->processor->processEntry($entry, $force);
    }

    public function modifyEntryDisplay(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        $content = $entry->content();

        // Add the summarize button if the entry hasn't been processed yet
        $text = 'Summarize with AI';
        if ($entry->hasAttribute('ai-processed')) {
            $text = 'Regenerate AI summary';
        }
        $buttonHtml = sprintf(
            '<button class="btn btn-primary summarize-btn" data-entry-id="%s">%s</button>',
            htmlspecialchars($entry->id()),
            $text
        );
        $content = $buttonHtml . '<br/><br/>' . $content;

        // Add summary and debug info if they exist
        $summary = $entry->attributeString('ai-summary');

        if (!empty($summary)) {
            $content .= '<hr/><div class="ai-summary"><strong>AI Generated Summary:</strong> ' . htmlspecialchars($summary) . '</div>';
        }

        $entry->_content($content);

        return $entry;
    }
}
