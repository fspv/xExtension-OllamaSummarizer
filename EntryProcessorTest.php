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

        // Set up mock user configuration
        $userConf = new class () {
            public function attributeInt(string $key): ?int
            {
                return match ($key) {
                    'freshrss_ollama_summary_length' => 150,
                    'freshrss_ollama_num_tags' => 5,
                    default => null
                };
            }
        };
        $userConf = FreshRSS_UserConfiguration::init(dirname(__FILE__) . '/config-user.default.php');
        FreshRSS_Context::$user_conf = $userConf;

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

    public function testProcessEntryWithEmptyOllamaResponse(): void
    {
        // Create a test entry
        $entry = $this->createTestEntry('https://example.com/article', 'Test Article');

        // Set up test responses
        $this->webpageFetcher->setResponse('This is the full article content');
        $this->ollamaClient->setResponse([
            'summary' => '',
            'tags' => [],
        ]);

        // Process the entry
        $processedEntry = $this->processor->processEntry($entry);

        // Verify the results
        $this->assertTrue($processedEntry->hasAttribute('ai-processed'));
        $this->assertFalse($processedEntry->hasAttribute('ai-summary'));
        $this->assertEquals([], $processedEntry->attributeArray('ai-tags'));
    }

    public function testProcessEntryWithNullFeed(): void
    {
        // Create a test entry with null feed
        $entry = $this->createTestEntry('https://example.com/article', 'Test Article');
        $entry->_feed(null);

        // Expect an exception
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Feed is null for entry');

        // Process the entry
        $this->processor->processEntry($entry);
    }

    public function testProcessEntryWithWebpageFetcherError(): void
    {
        // Create a test entry
        $entry = $this->createTestEntry('https://example.com/article', 'Test Article');

        // Set up test responses
        $this->webpageFetcher->setResponse('');
        $this->ollamaClient->setResponse([
            'summary' => 'This is a summary of the article',
            'tags' => ['test', 'article'],
        ]);

        // Process the entry
        $processedEntry = $this->processor->processEntry($entry);

        // Verify the results
        $this->assertTrue($processedEntry->hasAttribute('ai-processed'));
        $this->assertFalse($processedEntry->hasAttribute('ai-summary'));
        $this->assertEquals([], $processedEntry->attributeArray('ai-tags'));
    }

    public function testProcessEntryWithDebugInformation(): void
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

        // Verify debug information
        $this->assertTrue($processedEntry->hasAttribute('ai-debug'));
        $debugInfo = json_decode($processedEntry->attributeString('ai-debug'), true);
        $this->assertIsArray($debugInfo);
        $this->assertArrayHasKey('content', $debugInfo);
        $this->assertArrayHasKey('ollamaResponse', $debugInfo);
    }

    public function testTagNormalization(): void
    {
        // Create a test entry
        $entry = $this->createTestEntry('https://example.com/article', 'Test Article');

        // Set up test responses
        $this->webpageFetcher->setResponse('This is the full article content');
        $this->ollamaClient->setResponse([
            'summary' => 'This is a summary of the article',
            'tags' => ['Test Tag', 'ANOTHER TAG', 'tag with spaces', 'tag-with-special-chars!@#'],
        ]);

        // Process the entry
        $processedEntry = $this->processor->processEntry($entry);

        // Verify tag normalization
        $this->assertTrue($processedEntry->hasAttribute('ai-processed'));
        $this->assertEquals(['test tag', 'another tag', 'tag with spaces', 'tagwithspecialchars'], $processedEntry->attributeArray('ai-tags'));
    }

    public function testProcessEntryWithWebSocketTimeout(): void
    {
        // Create a test entry
        $entry = $this->createTestEntry('https://example.com/article', 'Test Article');

        // Configure the webpage fetcher to fail twice before succeeding
        $this->webpageFetcher->setFailuresBeforeSuccess(2);
        $this->webpageFetcher->setResponse('This is the full article content after retries');
        $this->ollamaClient->setResponse([
            'summary' => 'This is a summary of the article',
            'tags' => ['test', 'article'],
        ]);

        // Process the entry
        $processedEntry = $this->processor->processEntry($entry);

        // Verify the entry was processed successfully after retries
        $this->assertTrue($processedEntry->hasAttribute('ai-processed'));
        $this->assertEquals('This is a summary of the article', $processedEntry->attributeString('ai-summary'));
        $this->assertEquals(['test', 'article'], $processedEntry->attributeArray('ai-tags'));

        // Verify that we made exactly 3 attempts (2 failures + 1 success)
        $this->assertEquals(3, $this->webpageFetcher->getAttempts());
    }

    public function testProcessEntryWithMaxRetriesExceeded(): void
    {
        // Create a test entry
        $entry = $this->createTestEntry('https://example.com/article', 'Test Article');

        // Configure the webpage fetcher to fail more times than MAX_RETRIES
        $this->webpageFetcher->setFailuresBeforeSuccess(4); // More than MAX_RETRIES (3)
        $this->webpageFetcher->setResponse('This content should not be used');

        // Expect an exception after all retries are exhausted
        $this->expectException(\WebSocket\TimeoutException::class);
        $this->expectExceptionMessage('Client read timeout');

        // Process the entry
        $this->processor->processEntry($entry);
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
