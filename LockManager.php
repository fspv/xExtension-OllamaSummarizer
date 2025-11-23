<?php

declare(strict_types=1);

/**
 * Interface for managing locks to prevent concurrent processing.
 */
interface LockManager
{
    /**
     * Attempts to acquire an exclusive lock for processing.
     *
     * @param string $lockIdentifier Optional identifier for the lock (e.g., entry ID)
     *
     * @return bool True if lock was acquired, false if another process holds the lock
     */
    public function acquireLock(string $lockIdentifier = ''): bool;

    /**
     * Releases the currently held lock.
     */
    public function releaseLock(): void;

    /**
     * Checks if a lock is currently held by this instance.
     *
     * @return bool True if this instance holds a lock
     */
    public function isLocked(): bool;
}
