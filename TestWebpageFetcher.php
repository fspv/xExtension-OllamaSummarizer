<?php

declare(strict_types=1);

require_once dirname(__FILE__) . '/WebpageFetcher.php';

class TestWebpageFetcher extends WebpageFetcher
{
    private ?string $response = null;

    private int $attempts = 0;

    private int $failuresBeforeSuccess = 0;

    public function __construct(Logger $logger)
    {
        parent::__construct($logger, 'localhost', 9222);
    }

    public function setResponse(string $response): void
    {
        $this->response = $response;
    }

    public function setFailuresBeforeSuccess(int $count): void
    {
        $this->failuresBeforeSuccess = $count;
        $this->attempts = 0;
    }

    protected function createChromeTab(): array
    {
        $this->attempts++;
        if ($this->attempts <= $this->failuresBeforeSuccess) {
            throw new \WebSocket\TimeoutException('Client read timeout');
        }

        return ['wsUrl' => 'ws://test', 'targetId' => 'test-id'];
    }

    protected function attemptFetch(string $url, string $path): string
    {
        $this->attempts++;
        if ($this->attempts <= $this->failuresBeforeSuccess) {
            throw new \WebSocket\TimeoutException('Client read timeout');
        }
        if ($this->response === null) {
            throw new RuntimeException('Test response not set. Call setResponse() first.');
        }

        return $this->response;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }
}
