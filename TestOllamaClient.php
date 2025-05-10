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

    /**
     * @param array $response The test response array containing 'summary' and 'tags' keys
     */
    public function setResponse(array $response): void
    {
        $this->response = $response;
    }

    public function generateSummary(string $content): array
    {
        if ($this->response === null) {
            throw new RuntimeException('Test response not set. Call setResponse() first.');
        }

        $this->logger->debug('Generating summary with test response');

        if (!isset($this->response['summary']) || !isset($this->response['tags'])) {
            throw new RuntimeException('Test response must contain "summary" and "tags" keys');
        }

        return [
            'summary' => $this->response['summary'],
            'tags' => $this->response['tags'],
        ];
    }
}
