<?php

declare(strict_types=1);

require_once dirname(__FILE__) . '/constants.php';
require_once dirname(__FILE__) . '/Logger.php';
require_once dirname(__FILE__) . '/WebpageFetcher.php';
require_once dirname(__FILE__) . '/OllamaClient.php';

class EntryProcessor
{
    private Logger $logger;

    private WebpageFetcher $webpageFetcher;

    private OllamaClient $ollamaClient;

    public function __construct(
        Logger $logger,
        WebpageFetcher $webpageFetcher,
        OllamaClient $ollamaClient
    ) {
        $this->logger = $logger;
        $this->webpageFetcher = $webpageFetcher;
        $this->ollamaClient = $ollamaClient;
    }

    public function processEntry(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        $this->logger->debug('Processing entry: ' . json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($entry->hasAttribute('ai-processed')) {
            $this->logger->debug('Entry already processed, restoring tags from attributes');

            if ($entry->hasAttribute('ai-tags')) {
                $savedTags = $entry->attributeArray('ai-tags');
                if (!empty($savedTags)) {
                    $currentTags = $entry->tags();
                    $this->logger->debug('Current tags: ' . json_encode($currentTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                    foreach ($savedTags as $tag) {
                        if (!empty($tag) && !in_array($tag, $currentTags, true)) {
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

            // Fetch full content using Chrome with WebSocket
            $feed = $entry->feed();
            if ($feed === null) {
                throw new Exception('Feed is null for entry');
            }
            $content = $this->webpageFetcher->fetchContent($url, $feed->pathEntries() ?: 'article');
            $ollamaResponse = '';

            if (!empty($content)) {
                $this->logger->debug('Content fetched successfully, length: ' . strlen($content));

                // Generate tags and summary using Ollama
                $this->logger->debug('Sending content to Ollama');
                $userConf = FreshRSS_Context::$user_conf;
                if ($userConf === null) {
                    throw new Exception('User configuration is null');
                }
                $result = $this->ollamaClient->generateSummary($content);

                if (empty($result['summary']) || empty($result['tags'])) {
                    $this->logger->debug('Empty response from Ollama');
                } else {
                    $this->logger->debug('Ollama response received');

                    // Update the entry with tags and summary
                    $this->logger->debug('Updating entry with summary and tags');
                    $this->updateEntryWithSummary($entry, $result);
                }
            } else {
                $this->logger->debug('No content fetched from URL');
            }

            $entry->_attribute('ai-processed', true);

            $debugInfo = [
                'content' => $content,
                'ollamaResponse' => $result ?? null,
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

    private function normalizeTag(string $tag): string
    {
        // Convert to lowercase
        $tag = mb_strtolower($tag, 'UTF-8');

        // Remove any non-English characters and non-spaces
        $tag = preg_replace('/[^a-z\s]/', '', $tag) ?? '';

        // Remove extra spaces and trim
        $tag = preg_replace('/\s+/', ' ', $tag) ?? '';
        $tag = trim($tag);

        return $tag;
    }

    private function updateEntryWithSummary(FreshRSS_Entry $entry, array $result): void
    {
        $this->logger->debug('Updating entry with summary and tags');

        $summary = $result['summary'];
        $tags = $result['tags'];

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
    }
}
