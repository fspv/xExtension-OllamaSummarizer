<?php

declare(strict_types=1);

/**
 * Name: Ollama Summarizer
 * Author: Pavel Safronov
 * Description: Fetches article content using Chrome and uses Ollama to generate tags and summaries
 * Version: 0.1.1.
 */
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
require_once dirname(__FILE__) . '/SanitizeHTML.php';

class OllamaSummarizerExtension extends Minz_Extension
{
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

    private static function getConfiguration(): Configuration
    {
        $userConf = FreshRSS_Context::$user_conf;
        if ($userConf === null) {
            throw new Exception('User configuration is null');
        }

        return Configuration::fromUserConfiguration($userConf);
    }

    private static function getProcessor(Logger $logger): EntryProcessor
    {
        $config = self::getConfiguration();
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
            $config->getPromptTemplate(),
            $config->getOllamaTimeoutSeconds()
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
                    ollamaTimeoutSeconds: Minz_Request::paramInt('ollama_timeout_seconds'),
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

    public function processEntry(FreshRSS_Entry $entry, bool $force = false): FreshRSS_Entry
    {
        # Construct a unique identifier for the entry to identify processing of the same entry in the logs
        $entryId = $entry->guid();
        $entryIdHash = substr(hash('sha256', $entryId), 0, 8);
        $timestamp = round(microtime(true));
        $prefix = LOG_PREFIX . " [id:{$entryIdHash}] [start_timestamp:{$timestamp}]";
        $logger = new Logger($prefix);

        // Check if the feed is selected for processing
        $config = self::getConfiguration();
        if (!$force && !$config->isFeedSelected($entry->feedId())) {
            $logger->debug('Feed not selected for processing, skipping');

            return $entry;
        }

        $processor = self::getProcessor($logger);

        return $processor->processEntry($entry, $force);
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

        // Add article HTML under the details tag if it exists
        $html = $entry->attributeString('ollama-summarizer-html');
        if (!empty($html)) {
            $feed = $entry->feed();
            if ($feed === null) {
                throw new Exception('Feed is null for entry');
            }
            $content .= '<hr/><details><summary>Article HTML</summary>' . mySanitizeHTML($feed, $entry->link(), $html) . '</details>';
        }

        $entry->_content($content);

        return $entry;
    }
}
