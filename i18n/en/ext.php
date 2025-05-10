<?php

return [
    'ollama_summarizer' => [
        'chrome_host' => 'Chrome Host',
        'chrome_host_help' => 'Hostname where Chrome is running with remote debugging enabled',
        'chrome_port' => 'Chrome Port',
        'chrome_port_help' => 'Debug port for Chrome',
        'ollama_host' => 'Ollama Host',
        'ollama_host_help' => 'URL of the Ollama API endpoint',
        'ollama_model' => 'Ollama Model',
        'ollama_model_help' => 'The model name to use for summarization',
        'prompt_template' => 'Prompt Template',
        'prompt_template_help' => 'Template for the prompt sent to Ollama',
        'prompt_length_limit' => 'Prompt Length Limit',
        'prompt_length_limit_help' => 'Maximum length of the prompt in characters',
        'context_length' => 'Context Length',
        'context_length_help' => 'Context length for the model in tokens',
        'selected_feeds' => 'Selected Feeds',
        'selected_feeds_help' => 'Feeds that should be processed',
    ],
];
