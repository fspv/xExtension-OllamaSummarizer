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

    public function __construct(Logger $logger, string $devtoolsHost = 'localhost', int $devtoolsPort = 9222)
    {
        $this->logger = $logger;
        $this->devtoolsHost = $devtoolsHost;
        $this->devtoolsPort = $devtoolsPort;
    }

    public function fetchContent(string $url, string $path): string
    {
        $this->logger->debug('Fetching ' . $url . ' with path selector ' . $path);
        $this->logger->debug('Connecting to Chrome DevTools via WebSocket');

        // Create a new target/page if none found
        $this->logger->debug('No available targets found, creating a new one');

        $ch = curl_init("http://{$this->devtoolsHost}:{$this->devtoolsPort}/json/new");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT'); // Using PUT method instead of GET
        $createResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        $this->logger->debug('Create new target response: ' . $createResponse);
        if (!$createResponse) {
            throw new Exception('Could not create new tab in Chrome: ' . $curlError);
        }

        $newTarget = json_decode((string) $createResponse, true);
        if ($newTarget === null) {
            throw new Exception('Failed to decode JSON response');
        }
        $wsUrl = '';
        $targetId = '';
        if (isset($newTarget['webSocketDebuggerUrl'])) {
            $wsUrl = $newTarget['webSocketDebuggerUrl'];
            $targetId = $newTarget['id'] ?? '';
        } else {
            throw new Exception('No WebSocket URL in new target response');
        }

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
                if ($data === null) {
                    continue;
                }
                if (isset($data['method']) && $data['method'] === 'Page.loadEventFired') {
                    $navigationComplete = true;
                }
            } while (!$navigationComplete);

            // Wait a bit for JavaScript to execute (adjust as needed)
            sleep(10);

            // First try to find and get content from article tag
            $articleEvalMessage = json_encode([
                'id' => 2,
                'method' => 'Runtime.evaluate',
                'params' => [
                    'expression' => 'document.querySelector("' . $path . '")?.innerText || document.body.innerText',
                    'returnByValue' => true,
                ],
            ]);
            if ($articleEvalMessage === false) {
                throw new Exception('Failed to encode article evaluation message');
            }
            $this->logger->debug('Sending article evaluation command: ' . $articleEvalMessage);
            $client->send($articleEvalMessage);

            // Get the evaluation result
            $content = '';
            $evalComplete = false;
            while (!$evalComplete) {
                $response = $client->receive();
                $data = json_decode($response, true);
                $this->logger->debug('Received evaluation response: ' . $response);

                if (isset($data['id']) && $data['id'] === 2) {
                    if (isset($data['result']['result']['value'])) {
                        $content = $data['result']['result']['value'];
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

                // Close the Chrome tab if we have a target ID
                if (!empty($targetId)) {
                    $this->logger->debug('Closing Chrome tab with ID: ' . $targetId);

                    $ch = curl_init("http://{$this->devtoolsHost}:{$this->devtoolsPort}/json/close/{$targetId}");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Add timeout to prevent hanging

                    $result = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                    if ($result === false) {
                        $this->logger->error('Error closing Chrome tab: ' . curl_error($ch));
                    } else {
                        $this->logger->debug('Chrome tab closed successfully (HTTP ' . $httpCode . ')');
                    }

                    curl_close($ch);
                } else {
                    $this->logger->warning('No target ID available for cleanup');
                }
            } catch (Exception $e) {
                $this->logger->error('Error during cleanup: ' . $e->getMessage());
            }
        }
    }
}
