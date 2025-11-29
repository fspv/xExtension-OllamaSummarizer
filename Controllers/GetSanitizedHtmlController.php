<?php

declare(strict_types=1);

/**
 * Controller for fetching sanitized HTML on demand via AJAX.
 * This prevents expensive sanitization from running on every page load.
 *
 * @psalm-suppress UnusedClass
 */
final class FreshExtension_GetSanitizedHtml_Controller extends Minz_ActionController
{
    #[\Override]
    public function firstAction(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        try {
            // Check if this is an AJAX request
            if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
                $this->sendJsonError('This endpoint only accepts AJAX requests', 400);

                return;
            }

            // Get the entry ID from the request
            $entryId = $_POST['entry_id'] ?? null;
            if ($entryId === null || $entryId === '' || !is_string($entryId)) {
                $this->sendJsonError('Entry ID is required', 400);

                return;
            }

            // Fetch the entry from the database
            $entryDAO = FreshRSS_Factory::createEntryDao();
            $entry = $entryDAO->searchById($entryId);

            if (!$entry) {
                $this->sendJsonError('Entry not found', 404);

                return;
            }

            // Check if the entry has HTML content
            if (!$entry->hasAttribute('ollama-summarizer-html')) {
                $this->sendJsonResponse([
                    'success' => true,
                    'html' => '',
                    'message' => 'No HTML content available for this entry',
                ]);

                return;
            }

            $html = $entry->attributeString('ollama-summarizer-html');
            if ($html === '' || $html === null) {
                $this->sendJsonResponse([
                    'success' => true,
                    'html' => '',
                    'message' => 'HTML content is empty',
                ]);

                return;
            }

            // Get the feed for sanitization context
            $feed = $entry->feed();
            if ($feed === null) {
                $this->sendJsonError('Feed not found for entry', 404);

                return;
            }

            // Sanitize the HTML using the same function as the display
            require_once __DIR__ . '/../SanitizeHTML.php';
            $sanitizedHtml = mySanitizeHTML($feed, $entry->link(), $html);

            // Return the sanitized HTML
            $this->sendJsonResponse([
                'success' => true,
                'html' => $sanitizedHtml,
                'message' => 'HTML loaded successfully',
            ]);
        } catch (Exception $e) {
            Minz_Log::error('[OllamaSummarizer] Error loading HTML: ' . $e->getMessage());
            $this->sendJsonError('Failed to load HTML content', 500);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendJsonResponse(array $data): void
    {
        echo json_encode($data);
        exit();
    }

    /**
     * Send a JSON error response.
     */
    private function sendJsonError(string $message, int $statusCode): void
    {
        http_response_code($statusCode);
        echo json_encode([
            'success' => false,
            'error' => $message,
        ]);
        exit();
    }
}
