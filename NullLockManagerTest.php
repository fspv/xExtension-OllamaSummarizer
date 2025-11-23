<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/NullLockManager.php';

class NullLockManagerTest extends TestCase
{
    public function testAlwaysAcquiresLock(): void
    {
        $lockManager = new NullLockManager();

        $result = $lockManager->acquireLock('test-entry');

        $this->assertTrue($result);
        $this->assertTrue($lockManager->isLocked());
    }

    public function testReleaseLockWorks(): void
    {
        $lockManager = new NullLockManager();

        $lockManager->acquireLock('test-entry');
        $this->assertTrue($lockManager->isLocked());

        $lockManager->releaseLock();
        $this->assertFalse($lockManager->isLocked());
    }

    public function testMultipleInstancesCanAcquireSimultaneously(): void
    {
        $lockManager1 = new NullLockManager();
        $lockManager2 = new NullLockManager();

        // Both should be able to acquire "lock"
        $result1 = $lockManager1->acquireLock('entry-1');
        $result2 = $lockManager2->acquireLock('entry-2');

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertTrue($lockManager1->isLocked());
        $this->assertTrue($lockManager2->isLocked());
    }

    public function testIdempotentAcquire(): void
    {
        $lockManager = new NullLockManager();

        $result1 = $lockManager->acquireLock('test-entry');
        $result2 = $lockManager->acquireLock('test-entry');

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertTrue($lockManager->isLocked());
    }
}
