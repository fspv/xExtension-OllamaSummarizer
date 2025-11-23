<?php

declare(strict_types=1);

require_once dirname(__FILE__) . '/LockManager.php';

/**
 * Null implementation of LockManager for testing
 * Always succeeds without actually locking anything.
 */
class NullLockManager implements LockManager
{
    private bool $locked = false;

    /**
     * Always succeeds in acquiring the "lock".
     */
    public function acquireLock(string $lockIdentifier = ''): bool
    {
        $this->locked = true;

        return true;
    }

    /**
     * Releases the "lock".
     */
    public function releaseLock(): void
    {
        $this->locked = false;
    }

    /**
     * Returns whether the "lock" is held.
     */
    public function isLocked(): bool
    {
        return $this->locked;
    }
}
