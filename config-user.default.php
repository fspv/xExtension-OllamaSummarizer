<?php

/**
 * @var array{
 *     freshrss_ollama_chrome_host: string,
 *     freshrss_ollama_chrome_port: int,
 *     freshrss_ollama_ollama_host: string,
 *     freshrss_ollama_ollama_model: string,
 *     freshrss_ollama_model_options: array<string, mixed>,
 *     freshrss_ollama_prompt_length_limit: int,
 *     freshrss_ollama_context_length: int,
 *     freshrss_ollama_prompt_template: string
 * }
 */
return [
    'freshrss_ollama_chrome_host' => 'localhost',
    'freshrss_ollama_chrome_port' => 9222,
    'freshrss_ollama_ollama_host' => 'http://localhost:11434',
    'freshrss_ollama_ollama_model' => 'llama3',
    'freshrss_ollama_model_options' => [],
    'freshrss_ollama_prompt_length_limit' => 8192,
    'freshrss_ollama_context_length' => 4096,
    'freshrss_ollama_prompt_template' => <<<EOT
Based on the following article content, please provide:
1. A concise summary (around 150 words)
2. 5 relevant tags (single words or short phrases)

Article content:
EOT,
];
