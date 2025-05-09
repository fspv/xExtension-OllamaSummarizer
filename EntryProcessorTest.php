<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/vendor/autoload.php';

require_once dirname(__FILE__) . '/Logger.php';
require_once dirname(__FILE__) . '/WebpageFetcher.php';
require_once dirname(__FILE__) . '/OllamaClient.php';
require_once dirname(__FILE__) . '/TestWebpageFetcher.php';
require_once dirname(__FILE__) . '/TestOllamaClient.php';
require_once dirname(__FILE__) . '/TestFreshRSSEntry.php';
require_once dirname(__FILE__) . '/EntryProcessor.php';
require_once dirname(__FILE__) . '/EntryInterface.php';

class EntryProcessorTest extends TestCase
{
    private EntryProcessor $processor;

    private TestOllamaClient $ollamaClient;

    private TestWebpageFetcher $webpageFetcher;

    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger('test');
        $this->ollamaClient = new TestOllamaClient($this->logger);
        $this->webpageFetcher = new TestWebpageFetcher($this->logger);
        $this->processor = new EntryProcessor(
            $this->logger,
            $this->webpageFetcher,
            $this->ollamaClient
        );
    }

    public function testProcessNewEntry(): void
    {
        // Create a test entry
        $entry = $this->createTestEntry('https://example.com/article', 'Test Article');

        // Set up test responses
        $this->webpageFetcher->setResponse('This is the full article content');
        $this->ollamaClient->setResponse([
            'summary' => 'This is a summary of the article',
            'tags' => ['test', 'article'],
        ]);

        // Process the entry
        $processedEntry = $this->processor->processEntry($entry);

        // Verify the results
        $this->assertTrue($processedEntry->hasAttribute('ai-processed'));
        $this->assertEquals('This is a summary of the article', $processedEntry->attributeString('ai-summary'));
        $this->assertEquals(['test', 'article'], $processedEntry->attributeArray('ai-tags'));
        $this->assertContains('test', $processedEntry->tags());
        $this->assertContains('article', $processedEntry->tags());
    }

    public function testProcessAlreadyProcessedEntry(): void
    {
        // Create a test entry that's already been processed
        $entry = $this->createTestEntry('https://example.com/article', 'Test Article');
        $entry->_attribute('ai-processed', true);
        $entry->_attribute('ai-tags', ['existing-tag']);

        // Process the entry
        $processedEntry = $this->processor->processEntry($entry);

        // Verify the results
        $this->assertTrue($processedEntry->hasAttribute('ai-processed'));
        $this->assertEquals(['existing-tag'], $processedEntry->attributeArray('ai-tags'));
        $this->assertContains('existing-tag', $processedEntry->tags());
    }

    public function testProcessEntryWithoutUrl(): void
    {
        // Create a test entry without a URL
        $entry = $this->createTestEntry('', 'Test Article');

        // Process the entry
        $processedEntry = $this->processor->processEntry($entry);

        // Verify the results
        $this->assertFalse($processedEntry->hasAttribute('ai-processed'));
    }

    public function testProcessUpdatedEntry(): void
    {
        // Create a test entry that's been updated
        $entry = $this->createTestEntry('https://example.com/article', 'Test Article');
        $entry->_isUpdated(true);

        // Process the entry
        $processedEntry = $this->processor->processEntry($entry);

        // Verify the results
        $this->assertFalse($processedEntry->hasAttribute('ai-processed'));
    }

    public function testProcessEntryWithCustomFeedSelector(): void
    {
        // Create a test entry with a custom feed selector
        $entry = $this->createTestEntry('https://example.com/article', 'Test Article');
        $feed = new FreshRSS_Feed('https://example.com/feed');
        $feed->_pathEntries('.custom-article');
        $entry->_feed($feed);

        // Set up test responses
        $this->webpageFetcher->setResponse('This is the full article content');
        $this->ollamaClient->setResponse([
            'summary' => 'This is a summary of the article',
            'tags' => ['test', 'article'],
        ]);

        // Process the entry
        $processedEntry = $this->processor->processEntry($entry);

        // Verify the results
        $this->assertTrue($processedEntry->hasAttribute('ai-processed'));
        $this->assertEquals('This is a summary of the article', $processedEntry->attributeString('ai-summary'));
        $this->assertEquals(['test', 'article'], $processedEntry->attributeArray('ai-tags'));
    }

    private function createTestEntry(string $url, string $title): TestFreshRSSEntry
    {
        $entry = new TestFreshRSSEntry();
        $entry->_link($url);
        $entry->_title($title);
        $entry->_guid(uniqid('test_', true));
        $entry->_content('Test content');
        $entry->_tags([]);
        $entry->_feed(new FreshRSS_Feed('https://example.com/feed'));

        return $entry;
    }
}
