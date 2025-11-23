<?php

declare(strict_types=1);

require_once dirname(__FILE__) . '/Logger.php';

$autoload_path_freshrss = dirname(__FILE__) . '/../../vendor/autoload.php';
$autoload_path_local = dirname(__FILE__) . '/vendor/autoload.php';
if (file_exists($autoload_path_freshrss)) {
    require_once $autoload_path_freshrss;
} else {
    require_once $autoload_path_local;
}

class WebpageFetcher
{
    private Logger $logger;

    private string $devtoolsHost;

    private int $devtoolsPort;

    private int $maxRetries;

    private int $retryDelayMilliseconds;

    public function __construct(Logger $logger, string $devtoolsHost, int $devtoolsPort, int $maxRetries, int $retryDelayMilliseconds)
    {
        $this->logger = $logger;
        $this->devtoolsHost = $devtoolsHost;
        $this->devtoolsPort = $devtoolsPort;
        $this->maxRetries = $maxRetries;
        $this->retryDelayMilliseconds = $retryDelayMilliseconds;
    }

    /**
     * Fetches both inner text and HTML content from a webpage element.
     *
     * @param string $url  The URL to fetch content from
     * @param string $path The CSS selector path to find the element
     *
     * @throws Exception If fetching fails after all retry attempts
     *
     * @return array{text: string, html: string} Array containing both inner text and HTML content
     */
    public function fetchContent(string $url, string $path): array
    {
        $attempt = 1;
        /** @var Exception */
        $lastException = null;

        while ($attempt <= $this->maxRetries) {
            try {
                $this->logger->debug("Fetch attempt {$attempt} of " . $this->maxRetries . " for URL: {$url}");

                return $this->attemptFetch($url, $path);
            } catch (Exception $e) {
                $lastException = $e;
                $this->logger->warning("Fetch attempt {$attempt} failed: " . $e->getMessage());

                if ($attempt < $this->maxRetries) {
                    $this->logger->debug('Waiting ' . $this->retryDelayMilliseconds . ' milliseconds before next attempt');
                    usleep($this->retryDelayMilliseconds * 1000);
                }
                $attempt++;
            }
        }

        $this->logger->error("All fetch attempts failed for URL: {$url}");

        throw $lastException;
    }

    /**
     * Attempts to fetch content from a webpage.
     *
     * @param string $url  The URL to fetch content from
     * @param string $path The CSS selector path to find the element
     *
     * @throws Exception If fetching fails
     *
     * @return array{text: string, html: string} Array containing both inner text and HTML content
     */
    protected function attemptFetch(string $url, string $path): array
    {
        $this->logger->debug('Fetching ' . $url . ' with path selector ' . $path);
        $this->logger->debug('Connecting to Chrome DevTools via WebSocket');

        // Create a new target/page
        $this->logger->debug('Creating a new Chrome tab');
        $tabInfo = $this->createChromeTab();
        $wsUrl = $tabInfo['wsUrl'];
        $targetId = $tabInfo['targetId'];

        $this->logger->debug('WebSocket URL: ' . $wsUrl);
        $this->logger->debug('Target ID: ' . $targetId);

        // Connect to the WebSocket
        $options = [
           'timeout' => 60,
           'context' => stream_context_create([
               'socket' => [
                   'tcp_nodelay' => true,
               ],
           ]),
        ];
        $client = new WebSocket\Client($wsUrl, $options);

        try {
            // Enable Page domain
            $enableMessage = json_encode([
                'id' => 0,
                'method' => 'Page.enable',
            ]);
            if ($enableMessage === false) {
                throw new Exception('Failed to encode Page.enable message');
            }
            $this->logger->debug('Sending Page.enable command: ' . $enableMessage);
            $client->send($enableMessage);

            // Navigate to the URL
            $navigateMessage = json_encode([
                'id' => 1,
                'method' => 'Page.navigate',
                'params' => ['url' => $url],
            ]);
            if ($navigateMessage === false) {
                throw new Exception('Failed to encode navigation message');
            }
            $this->logger->debug('Sending navigation command: ' . $navigateMessage);
            $client->send($navigateMessage);

            // Wait for navigation to complete
            $navigationComplete = false;
            do {
                $response = $client->receive();
                $data = json_decode($response, true);
                $this->logger->debug('Received WebSocket message: ' . $response);
                if ($data === null) {
                    continue;
                }
                if (isset($data['method']) && $data['method'] === 'Page.loadEventFired') {
                    $navigationComplete = true;
                }
            } while (!$navigationComplete);

            // Wait a bit for JavaScript to execute (adjust as needed)
            sleep(10);

            // Get both innerText and outerHTML
            $evalMessage = json_encode([
                'id' => 2,
                'method' => 'Runtime.evaluate',
                'params' => [
                    'expression' => '(() => {
                        const element = document.querySelector("' . $path . '") || document.body;
                        return {
                            text: element.innerText,
                            html: element.outerHTML
                        };
                    })()',
                    'returnByValue' => true,
                ],
            ]);
            if ($evalMessage === false) {
                throw new Exception('Failed to encode evaluation message');
            }
            $this->logger->debug('Sending evaluation command: ' . $evalMessage);
            $client->send($evalMessage);

            // Get the evaluation result
            $content = ['text' => '', 'html' => ''];
            $evalComplete = false;
            while (!$evalComplete) {
                $response = $client->receive();
                $data = json_decode($response, true);
                $this->logger->debug('Received evaluation response: ' . $response);

                if (isset($data['id']) && $data['id'] === 2) {
                    if (isset($data['result']['result']['value'])) {
                        $value = $data['result']['result']['value'];
                        $content['text'] = $value['text'] ?? '';
                        $content['html'] = $value['html'] ?? '';
                    }
                    $evalComplete = true;
                }
            }

            return $content;
        } finally {
            $this->logger->debug('Starting cleanup process');

            try {
                // Close the WebSocket connection
                $this->logger->debug('Closing WebSocket connection');
                $client->close();

                // Close the Chrome tab
                $this->closeChromeTab($targetId);
            } catch (Exception $e) {
                $this->logger->error('Error during cleanup: ' . $e->getMessage());
            }
        }
    }

    /**
     * Creates a new Chrome tab and returns its WebSocket URL and target ID.
     *
     * @throws Exception If tab creation fails or response is invalid
     *
     * @return array{wsUrl: string, targetId: string}
     */
    protected function createChromeTab(): array
    {
        $ch = curl_init("http://{$this->devtoolsHost}:{$this->devtoolsPort}/json/new");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        $createResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!$createResponse) {
            throw new Exception('Could not create new tab in Chrome: ' . $curlError);
        }

        $newTarget = json_decode((string) $createResponse, true);
        if ($newTarget === null) {
            throw new Exception('Failed to decode JSON response');
        }

        if (!isset($newTarget['webSocketDebuggerUrl'])) {
            throw new Exception('No WebSocket URL in new target response');
        }

        return [
            'wsUrl' => $newTarget['webSocketDebuggerUrl'],
            'targetId' => $newTarget['id'] ?? '',
        ];
    }

    protected function closeChromeTab(string $targetId): void
    {
        if (empty($targetId)) {
            return;
        }

        $ch = curl_init("http://{$this->devtoolsHost}:{$this->devtoolsPort}/json/close/{$targetId}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($result === false) {
            $this->logger->error('Error closing Chrome tab: ' . curl_error($ch));
        } else {
            $this->logger->debug('Chrome tab closed successfully (HTTP ' . $httpCode . ')');
        }

        curl_close($ch);
    }
}
