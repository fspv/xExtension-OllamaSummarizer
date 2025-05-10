<?php

declare(strict_types=1);

class FreshExtension_FetchAndSummarizeWithOllama_Controller extends Minz_ActionController
{
    public function firstAction(): void
    {
        Minz_Log::debug('FetchAndSummarizeWithOllamaController actionIndex called with request: ' . json_encode(Minz_Request::params()));

        if (!Minz_Request::isPost()) {
            Minz_Request::bad(Minz_Translate::t('feedback.access.denied'));

            return;
        }

        $entryId = Minz_Request::paramString('id');
        if (empty($entryId)) {
            Minz_Request::bad(Minz_Translate::t('feedback.invalid_id'));

            return;
        }

        try {
            $entryDAO = FreshRSS_Factory::createEntryDao();
            $entry = $entryDAO->searchById($entryId);
            if ($entry === null) {
                Minz_Request::bad(Minz_Translate::t('feedback.entry_not_found'));

                return;
            }

            // Get the extension instance
            /** @psalm-suppress MissingFile */
            require_once dirname(__FILE__) . '/../extension.php';
            $extension = new FreshrssOllamaExtension(['name' => 'FreshrssOllamaExtension', 'entrypoint' => 'FreshrssOllamaExtension', 'path' => dirname(__FILE__) . '/../']);

            // Process the entry
            $processedEntry = $extension->processEntry($entry, true);

            // Save the processed entry
            $entryDAO->updateEntry($processedEntry->toArray());

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'summary' => $processedEntry->attributeString('ai-summary'),
                'tags' => $processedEntry->attributeArray('ai-tags'),
            ]);
            exit;
        } catch (Exception $e) {
            Minz_Log::error('FetchAndSummarizeWithOllamaController: Error processing entry: ' . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
            exit;
        }
    }
}
