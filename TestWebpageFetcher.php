<?php

declare(strict_types=1);

require_once dirname(__FILE__) . '/WebpageFetcher.php';

class TestWebpageFetcher extends WebpageFetcher
{
    private ?string $response = null;

    public function __construct(Logger $logger)
    {
        parent::__construct($logger, 'localhost', 9222);
    }

    public function setResponse(string $response): void
    {
        $this->response = $response;
    }

    public function fetchContent(string $url, string $path = 'article'): string
    {
        if ($this->response === null) {
            throw new RuntimeException('Test response not set. Call setResponse() first.');
        }
        return $this->response;
    }
} 