<?php

declare(strict_types=1);

require_once dirname(__FILE__) . '/constants.php';
require_once dirname(__FILE__) . '/Logger.php';
require_once dirname(__FILE__) . '/WebpageFetcher.php';
require_once dirname(__FILE__) . '/OllamaClient.php';
require_once dirname(__FILE__) . '/LockManager.php';

final class EntryProcessor
{
    private Logger $logger;

    private WebpageFetcher $webpageFetcher;

    private OllamaClient $ollamaClient;

    private LockManager $lockManager;

    public function __construct(
        Logger $logger,
        WebpageFetcher $webpageFetcher,
        OllamaClient $ollamaClient,
        LockManager $lockManager
    ) {
        $this->logger = $logger;
        $this->webpageFetcher = $webpageFetcher;
        $this->ollamaClient = $ollamaClient;
        $this->lockManager = $lockManager;
    }

    public function processEntry(FreshRSS_Entry $entry, bool $force = false): FreshRSS_Entry
    {
        $entryJson = json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->logger->debug('Processing entry: ' . ($entryJson !== false ? $entryJson : '[failed to encode]'));

        // Try to acquire the lock
        $entryId = $entry->guid();
        if (!$this->lockManager->acquireLock($entryId)) {
            $this->logger->warning('Another process is currently processing articles. Skipping entry: ' . $entryId);

            // Return the entry unchanged when we can't get the lock
            return $entry;
        }

        try {
            return $this->processEntryWithLock($entry, $force);
        } finally {
            // Always release the lock, even if an exception occurs
            $this->lockManager->releaseLock();
        }
    }

    private function processEntryWithLock(FreshRSS_Entry $entry, bool $force): FreshRSS_Entry
    {
        if (!$force && $entry->hasAttribute('ai-processed')) {
            $this->logger->debug('Entry already processed, restoring tags from attributes');

            if ($entry->hasAttribute('ai-tags')) {
                $savedTags = $entry->attributeArray('ai-tags');
                if ($savedTags !== [] && $savedTags !== null) {
                    $currentTags = $entry->tags();
                    $currentTagsJson = json_encode($currentTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $this->logger->debug('Current tags: ' . ($currentTagsJson !== false ? $currentTagsJson : '[failed to encode]'));

                    foreach ($savedTags as $tag) {
                        if (!empty($tag) && !in_array($tag, $currentTags, true)) {
                            $currentTags[] = $tag;
                        }
                    }

                    $entry->_tags($currentTags);
                    $restoredTagsJson = json_encode($currentTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $this->logger->debug('Restored tags: ' . ($restoredTagsJson !== false ? $restoredTagsJson : '[failed to encode]'));
                }
            }

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
            $response = $this->webpageFetcher->fetchContent($url, $feed->pathEntries() ?: 'article');
            $content = $response['text'];
            $html = $response['html'];

            $entry->_attribute('ollama-summarizer-html', $html);

            if (!empty($content)) {
                $this->logger->debug('Content fetched successfully, length: ' . strlen($content));

                // Generate tags and summary using Ollama
                $this->logger->debug('Sending content to Ollama');
                /** @psalm-suppress DeprecatedProperty */
                $userConf = FreshRSS_Context::$user_conf;
                if ($userConf === null) {
                    throw new Exception('User configuration is null');
                }
                $result = $this->ollamaClient->generateSummary($content);

                if ($result['summary'] === '' || $result['tags'] === []) {
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

            $finishedJson = json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->logger->debug('Finished processing entry ' . ($finishedJson !== false ? $finishedJson : '[failed to encode]'));

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
        $tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->logger->debug('Extracted tags: ' . ($tagsJson !== false ? $tagsJson : '[failed to encode]'));

        $entry->_attribute('ai-summary', $summary);
        $entry->_attribute('ai-tags', []);

        if ($tags !== [] && $tags !== null) {
            $currentTags = $entry->tags();
            $currentTagsJson = json_encode($currentTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->logger->debug('Current tags: ' . ($currentTagsJson !== false ? $currentTagsJson : '[failed to encode]'));

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

            $addedTagsJson = json_encode($addedTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $uniqueTagsJson = json_encode($uniqueTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->logger->debug('Added tags: ' . ($addedTagsJson !== false ? $addedTagsJson : '[failed to encode]'));
            $this->logger->debug('Final tags: ' . ($uniqueTagsJson !== false ? $uniqueTagsJson : '[failed to encode]'));
        }
    }
}
