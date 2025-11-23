<?php

declare(strict_types=1);

require_once dirname(__FILE__) . '/LockManager.php';
require_once dirname(__FILE__) . '/Logger.php';
require_once dirname(__FILE__) . '/constants.php';

/**
 * File-based lock manager implementation using PHP's flock()
 * Provides exclusive locking to prevent concurrent article processing.
 */
class FileLockManager implements LockManager
{
    private Logger $logger;

    private string $lockFilePath;

    /** @var resource|null */
    private $lockHandle = null;

    private bool $hasLock = false;

    /**
     * @param Logger $logger       Logger instance for debugging
     * @param string $lockFilePath Path to the lock file (default: /tmp/ollama-summarizer.lock)
     */
    public function __construct(Logger $logger, string $lockFilePath = '/tmp/ollama-summarizer.lock')
    {
        $this->logger = $logger;
        $this->lockFilePath = $lockFilePath;
    }

    /**
     * Attempts to acquire an exclusive lock for processing
     * Uses non-blocking mode (LOCK_NB) to fail immediately if lock is held.
     *
     * @param string $lockIdentifier Optional identifier for logging purposes
     *
     * @return bool True if lock was acquired, false if another process holds the lock
     */
    public function acquireLock(string $lockIdentifier = ''): bool
    {
        // If we already have a lock, return true
        if ($this->hasLock) {
            $this->logger->debug('Lock already held for: ' . $lockIdentifier);

            return true;
        }

        $identifier = !empty($lockIdentifier) ? " [{$lockIdentifier}]" : '';
        $this->logger->debug("Attempting to acquire lock{$identifier} on: {$this->lockFilePath}");

        // Open the lock file (create if doesn't exist)
        $handle = @fopen($this->lockFilePath, 'c');
        if ($handle === false) {
            $this->logger->error("Failed to open lock file: {$this->lockFilePath}");
            $this->lockHandle = null;

            return false;
        }
        $this->lockHandle = $handle;

        // Try to acquire exclusive lock with non-blocking mode
        if (flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            $this->hasLock = true;

            // Write process info to lock file for debugging
            ftruncate($this->lockHandle, 0);
            fwrite($this->lockHandle, json_encode([
                'pid' => getmypid(),
                'timestamp' => time(),
                'identifier' => $lockIdentifier,
                'hostname' => gethostname(),
            ]) . "\n");
            fflush($this->lockHandle);

            $this->logger->debug("Lock acquired successfully{$identifier}");

            return true;
        } else {
            // Another process holds the lock - try to read lock info for logging
            $lockInfo = $this->readLockInfo();
            $this->logger->debug("Lock is held by another process{$identifier}. Lock info: " . $lockInfo);

            // Clean up the file handle since we didn't get the lock
            $handle = $this->lockHandle;
            $this->lockHandle = null;
            fclose($handle);

            return false;
        }
    }

    /**
     * Releases the currently held lock.
     */
    public function releaseLock(): void
    {
        if (!$this->hasLock || $this->lockHandle === null) {
            $this->logger->debug('No lock to release');

            return;
        }

        $this->logger->debug("Releasing lock on: {$this->lockFilePath}");

        // Release the lock
        flock($this->lockHandle, LOCK_UN);

        // Close the file handle
        $handle = $this->lockHandle;
        $this->lockHandle = null;
        fclose($handle);

        // Clean up the lock file (optional - could leave it for debugging)
        @unlink($this->lockFilePath);

        // Reset state
        $this->lockHandle = null;
        $this->hasLock = false;

        $this->logger->debug('Lock released successfully');
    }

    /**
     * Checks if a lock is currently held by this instance.
     */
    public function isLocked(): bool
    {
        return $this->hasLock;
    }

    /**
     * Destructor ensures lock is released if object is destroyed.
     */
    public function __destruct()
    {
        if ($this->hasLock) {
            $this->logger->debug('Releasing lock in destructor');
            $this->releaseLock();
        }
    }

    /**
     * Attempts to read lock information from the lock file
     * Used for debugging when lock acquisition fails.
     */
    private function readLockInfo(): string
    {
        if (!file_exists($this->lockFilePath)) {
            return 'Lock file does not exist';
        }

        $content = @file_get_contents($this->lockFilePath);
        if ($content === false) {
            return 'Unable to read lock file';
        }

        $info = @json_decode(trim($content), true);
        if ($info === null) {
            return 'Invalid lock file content';
        }

        $age = isset($info['timestamp']) ? (time() - $info['timestamp']) : 'unknown';

        return sprintf(
            'PID: %s, Age: %s seconds, Identifier: %s, Host: %s',
            $info['pid'] ?? 'unknown',
            $age,
            $info['identifier'] ?? 'none',
            $info['hostname'] ?? 'unknown'
        );
    }
}
