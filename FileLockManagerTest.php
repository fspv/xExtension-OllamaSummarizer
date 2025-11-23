<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FileLockManager.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/constants.php';

class FileLockManagerTest extends TestCase
{
    private string $testLockFile;

    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testLockFile = '/tmp/test-ollama-summarizer-' . uniqid() . '.lock';
        $this->logger = new Logger('TEST');
    }

    protected function tearDown(): void
    {
        // Clean up test lock file if it exists
        if (file_exists($this->testLockFile)) {
            @unlink($this->testLockFile);
        }
        parent::tearDown();
    }

    public function testAcquireLockSucceedsWhenNotLocked(): void
    {
        $lockManager = new FileLockManager($this->logger, $this->testLockFile);

        $result = $lockManager->acquireLock('test-entry-1');

        $this->assertTrue($result);
        $this->assertTrue($lockManager->isLocked());
        $this->assertFileExists($this->testLockFile);

        $lockManager->releaseLock();
    }

    public function testAcquireLockFailsWhenAlreadyLocked(): void
    {
        $lockManager1 = new FileLockManager($this->logger, $this->testLockFile);
        $lockManager2 = new FileLockManager($this->logger, $this->testLockFile);

        // First lock should succeed
        $result1 = $lockManager1->acquireLock('test-entry-1');
        $this->assertTrue($result1);

        // Second lock should fail
        $result2 = $lockManager2->acquireLock('test-entry-2');
        $this->assertFalse($result2);
        $this->assertFalse($lockManager2->isLocked());

        // First lock should still be held
        $this->assertTrue($lockManager1->isLocked());

        $lockManager1->releaseLock();
    }

    public function testReleaseLockWorks(): void
    {
        $lockManager = new FileLockManager($this->logger, $this->testLockFile);

        $lockManager->acquireLock('test-entry');
        $this->assertTrue($lockManager->isLocked());

        $lockManager->releaseLock();
        $this->assertFalse($lockManager->isLocked());
    }

    public function testLockReleasedOnDestruct(): void
    {
        $lockManager1 = new FileLockManager($this->logger, $this->testLockFile);
        $lockManager1->acquireLock('test-entry');

        // Destroy the first lock manager (simulates process termination)
        unset($lockManager1);

        // New lock manager should be able to acquire the lock
        $lockManager2 = new FileLockManager($this->logger, $this->testLockFile);
        $result = $lockManager2->acquireLock('test-entry-2');

        $this->assertTrue($result);

        $lockManager2->releaseLock();
    }

    public function testMultipleAcquireCallsWithSameLockManagerReturnTrue(): void
    {
        $lockManager = new FileLockManager($this->logger, $this->testLockFile);

        $result1 = $lockManager->acquireLock('test-entry');
        $this->assertTrue($result1);

        // Calling acquire again on same instance should return true (idempotent)
        $result2 = $lockManager->acquireLock('test-entry');
        $this->assertTrue($result2);
        $this->assertTrue($lockManager->isLocked());

        $lockManager->releaseLock();
    }

    public function testLockFileContainsProcessInfo(): void
    {
        $lockManager = new FileLockManager($this->logger, $this->testLockFile);

        $lockManager->acquireLock('test-entry-123');

        $content = file_get_contents($this->testLockFile);
        $this->assertNotFalse($content);

        $info = json_decode($content, true);
        $this->assertIsArray($info);
        $this->assertEquals(getmypid(), $info['pid']);
        $this->assertEquals('test-entry-123', $info['identifier']);
        $this->assertArrayHasKey('timestamp', $info);
        $this->assertArrayHasKey('hostname', $info);

        $lockManager->releaseLock();
    }

    public function testConcurrentProcessSimulation(): void
    {
        $lockManager = new FileLockManager($this->logger, $this->testLockFile);

        // Acquire lock in "parent process"
        $lockManager->acquireLock('parent-entry');

        // Fork simulation - create new lock manager instance (simulates child process)
        $childLockManager = new FileLockManager($this->logger, $this->testLockFile);

        // Child should fail to acquire lock
        $childResult = $childLockManager->acquireLock('child-entry');
        $this->assertFalse($childResult);

        // Parent releases lock
        $lockManager->releaseLock();

        // Now child should be able to acquire
        $childResult2 = $childLockManager->acquireLock('child-entry');
        $this->assertTrue($childResult2);

        $childLockManager->releaseLock();
    }
}
