<?php

declare(strict_types=1);

require_once dirname(__FILE__) . '/WebpageFetcher.php';
require_once dirname(__FILE__) . '/Configuration.php';

final class TestWebpageFetcher extends WebpageFetcher
{
    private ?string $response = null;

    private int $attempts = 0;

    private int $failuresBeforeSuccess = 0;

    public function __construct(Logger $logger, string $devtoolsHost, int $devtoolsPort, int $maxRetries, int $retryDelayMilliseconds)
    {
        parent::__construct($logger, $devtoolsHost, $devtoolsPort, $maxRetries, $retryDelayMilliseconds);
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

    #[\Override]
    protected function createChromeTab(): array
    {
        $this->attempts++;
        if ($this->attempts <= $this->failuresBeforeSuccess) {
            throw new \WebSocket\TimeoutException('Client read timeout');
        }

        return ['wsUrl' => 'ws://test', 'targetId' => 'test-id'];
    }

    #[\Override]
    protected function attemptFetch(string $url, string $path): array
    {
        $this->attempts++;
        if ($this->attempts <= $this->failuresBeforeSuccess) {
            throw new \WebSocket\TimeoutException('Client read timeout');
        }
        if ($this->response === null) {
            throw new RuntimeException('Test response not set. Call setResponse() first.');
        }

        return ['text' => $this->response, 'html' => $this->response];
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }
}
