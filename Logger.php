<?php

declare(strict_types=1);

/**
 * Helper function to escape newlines and carriage returns in strings for logging
 * @param string $string The string to escape
 * @return string The escaped string
 */
function escapeForLog(string $string): string
{
    return str_replace(["\n", "\r"], ['\\n', '\\r'], $string);
}

class Logger
{
    private string $prefix;

    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
    }

    public function debug(string $message): void
    {
        Minz_Log::debug($this->prefix . ' ' . escapeForLog($message));
    }

    public function error(string $message): void
    {
        Minz_Log::error($this->prefix . ': ' . escapeForLog($message));
    }

    public function warning(string $message): void
    {
        Minz_Log::warning($this->prefix . ': ' . escapeForLog($message));
    }

    public function notice(string $message): void
    {
        Minz_Log::notice($this->prefix . ': ' . escapeForLog($message));
    }
}
