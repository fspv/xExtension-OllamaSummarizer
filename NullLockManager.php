<?php

declare(strict_types=1);

require_once dirname(__FILE__) . '/LockManager.php';

/**
 * Null implementation of LockManager for testing
 * Always succeeds without actually locking anything.
 */
final class NullLockManager implements LockManager
{
    private bool $locked = false;

    /**
     * Always succeeds in acquiring the "lock".
     */
    #[\Override]
    public function acquireLock(string $lockIdentifier = ''): bool
    {
        $this->locked = true;

        return true;
    }

    /**
     * Releases the "lock".
     */
    #[\Override]
    public function releaseLock(): void
    {
        $this->locked = false;
    }

    /**
     * Returns whether the "lock" is held.
     */
    #[\Override]
    public function isLocked(): bool
    {
        return $this->locked;
    }
}
