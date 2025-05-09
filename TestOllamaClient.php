<?php

declare(strict_types=1);

require_once dirname(__FILE__) . '/OllamaClient.php';
require_once dirname(__FILE__) . '/Logger.php';

class TestOllamaClient implements OllamaClient
{
    private Logger $logger;

    private ?array $response = null;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function setResponse(array $response): void
    {
        $this->response = $response;
    }

    public function generateSummary(string $content, int $summaryLength = 150, int $numTags = 5): array
    {
        if ($this->response === null) {
            throw new RuntimeException('Test response not set. Call setResponse() first.');
        }

        return $this->response;
    }
}
