<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EntryProcessor.php';
require_once __DIR__ . '/NullLockManager.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/constants.php';

class EntryProcessorLockTest extends TestCase
{
    public function testProcessEntryAcquiresAndReleasesLock(): void
    {
        // Create mocks
        $logger = new Logger('TEST');

        // Create a mock lock manager that tracks calls
        $lockManager = $this->createMock(LockManager::class);

        // Create mock dependencies
        $webpageFetcher = $this->createMock(WebpageFetcher::class);
        $ollamaClient = $this->createMock(OllamaClient::class);

        // Create mock entry
        $entry = $this->createMock(FreshRSS_Entry::class);
        $entry->expects($this->once())
            ->method('guid')
            ->willReturn('test-guid-123');

        $entry->expects($this->exactly(2))
            ->method('hasAttribute')
            ->willReturnCallback(function ($attribute) {
                if ($attribute === 'ai-processed') {
                    return true; // Entry already processed
                }
                if ($attribute === 'ai-tags') {
                    return false; // No tags to restore
                }

                return false;
            });

        // Set up lock expectations
        $lockManager->expects($this->once())
            ->method('acquireLock')
            ->with('test-guid-123')
            ->willReturn(true);

        $lockManager->expects($this->once())
            ->method('releaseLock');

        // Create processor
        $processor = new EntryProcessor($logger, $webpageFetcher, $ollamaClient, $lockManager);

        // Process entry
        $result = $processor->processEntry($entry, false);

        $this->assertSame($entry, $result);
    }

    public function testProcessEntryReturnsUnchangedWhenLockNotAcquired(): void
    {
        // Create mocks
        $logger = new Logger('TEST');

        // Create a mock lock manager that fails to acquire lock
        $lockManager = $this->createMock(LockManager::class);

        // Create mock dependencies
        $webpageFetcher = $this->createMock(WebpageFetcher::class);
        $ollamaClient = $this->createMock(OllamaClient::class);

        // Create mock entry
        $entry = $this->createMock(FreshRSS_Entry::class);
        $entry->expects($this->once())
            ->method('guid')
            ->willReturn('test-guid-456');

        // Entry methods should not be called when lock fails
        $entry->expects($this->never())
            ->method('hasAttribute');

        // Set up lock to fail
        $lockManager->expects($this->once())
            ->method('acquireLock')
            ->with('test-guid-456')
            ->willReturn(false);

        // Release should not be called when lock acquisition fails
        $lockManager->expects($this->never())
            ->method('releaseLock');

        // Create processor
        $processor = new EntryProcessor($logger, $webpageFetcher, $ollamaClient, $lockManager);

        // Process entry
        $result = $processor->processEntry($entry, false);

        // Should return unchanged entry
        $this->assertSame($entry, $result);
    }

    public function testProcessEntryReleasesLockOnException(): void
    {
        // Create mocks
        $logger = new Logger('TEST');

        // Create a mock lock manager
        $lockManager = $this->createMock(LockManager::class);

        // Create mock dependencies
        $webpageFetcher = $this->createMock(WebpageFetcher::class);
        $ollamaClient = $this->createMock(OllamaClient::class);

        // Create mock entry that throws exception
        $entry = $this->createMock(FreshRSS_Entry::class);
        $entry->expects($this->once())
            ->method('guid')
            ->willReturn('test-guid-789');

        $entry->expects($this->once())
            ->method('hasAttribute')
            ->with('ai-processed')
            ->willReturn(false);

        $entry->expects($this->once())
            ->method('link')
            ->willThrowException(new Exception('Test exception'));

        // Set up lock expectations
        $lockManager->expects($this->once())
            ->method('acquireLock')
            ->with('test-guid-789')
            ->willReturn(true);

        // Lock should still be released even when exception occurs
        $lockManager->expects($this->once())
            ->method('releaseLock');

        // Create processor
        $processor = new EntryProcessor($logger, $webpageFetcher, $ollamaClient, $lockManager);

        // Process entry should throw exception
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Test exception');

        $processor->processEntry($entry, false);
    }
}
