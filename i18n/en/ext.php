<?php

return [
    'ollama_summarizer' => [
        'chrome_host' => 'Chrome Host',
        'chrome_host_help' => 'Hostname where Chrome is running with remote debugging enabled',
        'chrome_port' => 'Chrome Port',
        'chrome_port_help' => 'Debug port for Chrome',
        'ollama_url' => 'Ollama URL',
        'ollama_url_help' => 'URL of the Ollama API endpoint',
        'ollama_model' => 'Ollama Model',
        'ollama_model_help' => 'The model name to use for summarization',
        'ollama_not_connected' => 'Ollama not connected, please update the Ollama URL and reload the page',
        'ollama_model_not_selected' => 'Not selected',
        'prompt_template' => 'Ollama Prompt Template',
        'prompt_template_help' => 'Template for the prompt sent to Ollama',
        'prompt_length_limit' => 'Ollama Prompt Length Limit',
        'prompt_length_limit_help' => 'Maximum length of the prompt in characters',
        'context_length' => 'Ollama Context Length',
        'context_length_help' => 'Context length for the model in tokens',
        'selected_feeds' => 'Selected Feeds',
        'selected_feeds_help' => 'Feeds that should be processed',
        'max_retries' => 'Article Fetch Max Retries',
        'max_retries_help' => 'Maximum number of retry attempts for failed article fetch operations',
        'retry_delay_milliseconds' => 'Article Fetch Retry Delay',
        'retry_delay_milliseconds_help' => 'Delay between retry attempts in milliseconds',
    ],
];
