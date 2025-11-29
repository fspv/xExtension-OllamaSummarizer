<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/TestFreshRSSEntry.php';
require_once dirname(__FILE__) . '/Controllers/GetSanitizedHtmlController.php';
require_once dirname(__FILE__) . '/SanitizeHTML.php';

/**
 * Mock implementation of GetSanitizedHtmlController for testing.
 * This allows us to test the controller logic without dealing with exit() and static factories.
 */
final class TestableGetSanitizedHtmlController
{
    /** @var TestGetSanitizedHtmlEntryDAO|FreshRSS_EntryDAO|null */
    private $entryDAO = null;

    private bool $shouldThrowException = false;

    /**
     * @param TestGetSanitizedHtmlEntryDAO|FreshRSS_EntryDAO $dao
     */
    public function setEntryDAO($dao): void
    {
        $this->entryDAO = $dao;
    }

    public function setThrowException(bool $throw): void
    {
        $this->shouldThrowException = $throw;
    }

    /**
     * Testable version of the controller's firstAction method.
     * Returns response instead of echoing and exiting.
     *
     * @return array{success: true, html: string, message: string, statusCode: int}|array{success: false, error: string, statusCode: int}
     */
    public function firstAction(): array
    {
        try {
            if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
                return [
                    'success' => false,
                    'error' => 'This endpoint only accepts AJAX requests',
                    'statusCode' => 400,
                ];
            }

            $entryId = $_POST['entry_id'] ?? null;
            if ($entryId === null || $entryId === '' || !is_string($entryId)) {
                return [
                    'success' => false,
                    'error' => 'Entry ID is required',
                    'statusCode' => 400,
                ];
            }

            if ($this->shouldThrowException) {
                throw new Exception('Database error');
            }

            $entryDAO = $this->entryDAO ?? FreshRSS_Factory::createEntryDao();
            $entry = $entryDAO->searchById($entryId);

            if (!$entry) {
                return [
                    'success' => false,
                    'error' => 'Entry not found',
                    'statusCode' => 404,
                ];
            }

            if (!$entry->hasAttribute('ollama-summarizer-html')) {
                return [
                    'success' => true,
                    'html' => '',
                    'message' => 'No HTML content available for this entry',
                    'statusCode' => 200,
                ];
            }

            $html = $entry->attributeString('ollama-summarizer-html');
            if ($html === '' || $html === null) {
                return [
                    'success' => true,
                    'html' => '',
                    'message' => 'HTML content is empty',
                    'statusCode' => 200,
                ];
            }

            $feed = $entry->feed();
            if ($feed === null) {
                return [
                    'success' => false,
                    'error' => 'Feed not found for entry',
                    'statusCode' => 404,
                ];
            }

            require_once __DIR__ . '/SanitizeHTML.php';
            $sanitizedHtml = mySanitizeHTML($feed, $entry->link(), $html);

            return [
                'success' => true,
                'html' => $sanitizedHtml,
                'message' => 'HTML loaded successfully',
                'statusCode' => 200,
            ];
        } catch (Exception $e) {
            Minz_Log::error('[OllamaSummarizer] Error loading HTML: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => 'Failed to load HTML content: ' . $e->getMessage(),
                'statusCode' => 500,
            ];
        } catch (Error $e) {
            return [
                'success' => false,
                'error' => 'Failed to load HTML content (Error): ' . $e->getMessage(),
                'statusCode' => 500,
            ];
        }
    }
}

/**
 * Test double for FreshRSS_EntryDAO.
 * Does not extend the real class to avoid database connections.
 */
final class TestGetSanitizedHtmlEntryDAO
{
    /** @var FreshRSS_Entry|null */
    private ?FreshRSS_Entry $entryToReturn = null;

    public function setEntryToReturn(?FreshRSS_Entry $entry): void
    {
        $this->entryToReturn = $entry;
    }

    /**
     * @psalm-suppress PossiblyUnusedParam
     * @psalm-suppress UnusedParam
     */
    public function searchById(string $id): ?FreshRSS_Entry
    {
        return $this->entryToReturn;
    }
}

/**
 * Tests for the GetSanitizedHtmlController.
 *
 * @psalm-suppress UnusedClass
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress PossiblyUndefinedArrayOffset
 */
final class GetSanitizedHtmlControllerTest extends TestCase
{
    private TestableGetSanitizedHtmlController $controller;

    private TestGetSanitizedHtmlEntryDAO $entryDAO;

    /** @var array<array-key, mixed> */
    private array $originalServer;

    /** @var array<array-key, mixed> */
    private array $originalPost;

    #[\Override]
    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        $this->originalPost = $_POST;

        // Initialize FreshRSS system configuration
        if (!defined('DATA_PATH')) {
            $pid = getmypid();
            define('DATA_PATH', sys_get_temp_dir() . '/freshrss_test_' . ($pid !== false ? $pid : 'default'));
        }
        if (!defined('FRESHRSS_PATH')) {
            define('FRESHRSS_PATH', dirname(__FILE__) . '/vendor/freshrss');
        }

        // Create minimal config directory
        if (!is_dir(DATA_PATH)) {
            mkdir(DATA_PATH, 0777, true);
        }

        // Initialize system configuration with minimal settings
        try {
            FreshRSS_Context::initSystem();
        } catch (Exception $e) {
            // Ignore if already initialized
        }

        $this->controller = new TestableGetSanitizedHtmlController();
        $this->entryDAO = new TestGetSanitizedHtmlEntryDAO();
        $this->controller->setEntryDAO($this->entryDAO);

        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    }

    #[\Override]
    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_POST = $this->originalPost;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createTestEntry(
        string $id = '123',
        ?array $attributes = null,
        ?FreshRSS_Feed $feed = null
    ): TestFreshRSSEntry {
        $entry = new TestFreshRSSEntry();
        $entry->_guid($id);
        $entry->_link('https://example.com/article');

        if ($attributes !== null) {
            foreach ($attributes as $key => $value) {
                if ($key !== '') {
                    $entry->_attribute($key, $value);
                }
            }
        }

        if ($feed !== null) {
            $entry->_feed($feed);
        }

        return $entry;
    }

    public function testRejectsNonAjaxRequest(): void
    {
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $_POST['entry_id'] = '123';

        $response = $this->controller->firstAction();

        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame('This endpoint only accepts AJAX requests', $response['error']);
        $this->assertSame(400, $response['statusCode']);
    }

    public function testRejectsMissingEntryId(): void
    {
        $response = $this->controller->firstAction();

        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame('Entry ID is required', $response['error']);
        $this->assertSame(400, $response['statusCode']);
    }

    public function testRejectsEmptyEntryId(): void
    {
        $_POST['entry_id'] = '';

        $response = $this->controller->firstAction();

        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame('Entry ID is required', $response['error']);
        $this->assertSame(400, $response['statusCode']);
    }

    public function testReturnsErrorWhenEntryNotFound(): void
    {
        $_POST['entry_id'] = '123';
        $this->entryDAO->setEntryToReturn(null);

        $response = $this->controller->firstAction();

        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame('Entry not found', $response['error']);
        $this->assertSame(404, $response['statusCode']);
    }

    public function testReturnsEmptyHtmlWhenAttributeNotPresent(): void
    {
        $_POST['entry_id'] = '123';
        $entry = $this->createTestEntry('123');
        $this->entryDAO->setEntryToReturn($entry);

        $response = $this->controller->firstAction();

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('html', $response);
        $this->assertSame('', $response['html']);
        $this->assertArrayHasKey('message', $response);
        $this->assertSame('No HTML content available for this entry', $response['message']);
        $this->assertSame(200, $response['statusCode']);
    }

    public function testReturnsEmptyHtmlWhenHtmlIsEmpty(): void
    {
        $_POST['entry_id'] = '123';
        $entry = $this->createTestEntry('123', ['ollama-summarizer-html' => '']);
        $this->entryDAO->setEntryToReturn($entry);

        $response = $this->controller->firstAction();

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('html', $response);
        $this->assertSame('', $response['html']);
        $this->assertArrayHasKey('message', $response);
        $this->assertSame('HTML content is empty', $response['message']);
        $this->assertSame(200, $response['statusCode']);
    }

    public function testReturnsEmptyHtmlWhenHtmlIsNull(): void
    {
        $_POST['entry_id'] = '123';
        $entry = $this->createTestEntry('123', ['ollama-summarizer-html' => null]);
        $this->entryDAO->setEntryToReturn($entry);

        $response = $this->controller->firstAction();

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('html', $response);
        $this->assertSame('', $response['html']);
        // Note: isset() returns false for null values, so this returns "No HTML content available"
        $this->assertArrayHasKey('message', $response);
        $this->assertSame('No HTML content available for this entry', $response['message']);
        $this->assertSame(200, $response['statusCode']);
    }

    public function testReturnsErrorWhenFeedNotFound(): void
    {
        $_POST['entry_id'] = '123';
        $entry = $this->createTestEntry('123', ['ollama-summarizer-html' => '<p>Test</p>'], null);
        $this->entryDAO->setEntryToReturn($entry);

        $response = $this->controller->firstAction();

        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame('Feed not found for entry', $response['error']);
        $this->assertSame(404, $response['statusCode']);
    }

    public function testReturnsSanitizedHtmlSuccessfully(): void
    {
        $_POST['entry_id'] = '123';
        $feed = new FreshRSS_Feed('https://example.com/feed', false);
        $feed->_name('Test Feed');
        $entry = $this->createTestEntry('123', ['ollama-summarizer-html' => '<p>Test content</p>'], $feed);
        $this->entryDAO->setEntryToReturn($entry);

        $response = $this->controller->firstAction();

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('html', $response);
        $this->assertNotEmpty($response['html']);
        $this->assertArrayHasKey('message', $response);
        $this->assertSame('HTML loaded successfully', $response['message']);
        $this->assertSame(200, $response['statusCode']);
    }

    public function testHandlesExceptionDuringProcessing(): void
    {
        $_POST['entry_id'] = '123';
        $this->controller->setThrowException(true);

        $response = $this->controller->firstAction();

        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('error', $response);
        $this->assertStringContainsString('Failed to load HTML content', $response['error']);
        $this->assertStringContainsString('Database error', $response['error']);
        $this->assertSame(500, $response['statusCode']);
    }

    public function testSanitizesHtmlWithComplexContent(): void
    {
        $_POST['entry_id'] = '123';
        $feed = new FreshRSS_Feed('https://example.com/feed', false);
        $feed->_name('Test Feed');
        $complexHtml = '<div><p>Test paragraph</p><script>alert("xss")</script><img src="test.jpg"></div>';
        $entry = $this->createTestEntry('123', ['ollama-summarizer-html' => $complexHtml], $feed);
        $this->entryDAO->setEntryToReturn($entry);

        $response = $this->controller->firstAction();

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('html', $response);
        $this->assertStringNotContainsString('<script>', $response['html']);
        $this->assertStringNotContainsString('alert', $response['html']);
    }

    public function testValidatesAjaxHeaderCaseInsensitive(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
        $_POST['entry_id'] = '123';
        $entry = $this->createTestEntry('123');
        $this->entryDAO->setEntryToReturn($entry);

        $response = $this->controller->firstAction();

        $this->assertTrue($response['success']);
        $this->assertSame(200, $response['statusCode']);
    }

    public function testSanitizesRelativeImageUrls(): void
    {
        $_POST['entry_id'] = '123';
        $feed = new FreshRSS_Feed('https://example.com/feed', false);
        $feed->_name('Test Feed');
        $htmlWithRelativeUrl = '<img src="image.jpg" alt="Test">';
        $entry = $this->createTestEntry('123', ['ollama-summarizer-html' => $htmlWithRelativeUrl], $feed);
        $this->entryDAO->setEntryToReturn($entry);

        $response = $this->controller->firstAction();

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('html', $response);
        $this->assertStringContainsString('example.com', $response['html']);
    }

    public function testHandlesHtmlWithSpecialCharacters(): void
    {
        $_POST['entry_id'] = '123';
        $feed = new FreshRSS_Feed('https://example.com/feed', false);
        $feed->_name('Test Feed');
        $htmlWithSpecialChars = '<p>Test &amp; Special &lt;chars&gt;</p>';
        $entry = $this->createTestEntry('123', ['ollama-summarizer-html' => $htmlWithSpecialChars], $feed);
        $this->entryDAO->setEntryToReturn($entry);

        $response = $this->controller->firstAction();

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('html', $response);
        $this->assertNotEmpty($response['html']);
    }

    public function testHandlesMalformedHtml(): void
    {
        $_POST['entry_id'] = '123';
        $feed = new FreshRSS_Feed('https://example.com/feed', false);
        $feed->_name('Test Feed');
        $malformedHtml = '<p>Unclosed paragraph<div>Nested wrong</p></div>';
        $entry = $this->createTestEntry('123', ['ollama-summarizer-html' => $malformedHtml], $feed);
        $this->entryDAO->setEntryToReturn($entry);

        $response = $this->controller->firstAction();

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('html', $response);
    }
}
