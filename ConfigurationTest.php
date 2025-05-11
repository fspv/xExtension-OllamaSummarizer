<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/vendor/autoload.php';
require_once dirname(__FILE__) . '/Configuration.php';

class ConfigurationTest extends TestCase
{
    public function testCreateDefault(): void
    {
        $config = Configuration::createDefault();

        $this->assertEquals('localhost', $config->getChromeHost());
        $this->assertEquals(9222, $config->getChromePort());
        $this->assertEquals('http://localhost:11434', $config->getOllamaUrl());
        $this->assertEquals('llama3', $config->getOllamaModel());
        $this->assertEquals([], $config->getModelOptions());
        $this->assertEquals(8192, $config->getPromptLengthLimit());
        $this->assertEquals(4096, $config->getContextLength());
        $this->assertStringContainsString('Based on the following article content', $config->getPromptTemplate());
        $this->assertEquals([], $config->getSelectedFeeds());
        $this->assertEquals(3, $config->getMaxRetries());
        $this->assertEquals(2000, $config->getRetryDelayMilliseconds());
    }

    public function testFromUserConfiguration(): void
    {
        // Create a temporary config file
        $tempFile = tempnam(sys_get_temp_dir(), 'freshrss_test_');
        file_put_contents($tempFile, '<?php return [
            "ollama_summarizer_chrome_host" => "custom-host",
            "ollama_summarizer_chrome_port" => 9999,
            "ollama_summarizer_ollama_url" => "http://custom-ollama:11434",
            "ollama_summarizer_ollama_model" => "mistral",
            "ollama_summarizer_model_options" => ["temperature" => 0.7],
            "ollama_summarizer_prompt_length_limit" => 4000,
            "ollama_summarizer_context_length" => 2048,
            "ollama_summarizer_prompt_template" => "Custom template",
            "ollama_summarizer_selected_feeds" => [1, 2, 3],
            "ollama_summarizer_max_retries" => 5,
            "ollama_summarizer_retry_delay_milliseconds" => 10000,
        ];');

        $config = Configuration::fromUserConfiguration(FreshRSS_UserConfiguration::init($tempFile));
        $this->assertEquals('custom-host', $config->getChromeHost());
        $this->assertEquals(9999, $config->getChromePort());
        $this->assertEquals('http://custom-ollama:11434', $config->getOllamaUrl());
        $this->assertEquals('mistral', $config->getOllamaModel());
        $this->assertEquals(['temperature' => 0.7], $config->getModelOptions());
        $this->assertEquals(4000, $config->getPromptLengthLimit());
        $this->assertEquals(2048, $config->getContextLength());
        $this->assertEquals('Custom template', $config->getPromptTemplate());
        $this->assertEquals([1, 2, 3], $config->getSelectedFeeds());
        $this->assertEquals(5, $config->getMaxRetries());
        $this->assertEquals(10000, $config->getRetryDelayMilliseconds());
    }

    public function testFromUserConfigurationWithMissingValues(): void
    {
        // Create a temporary config file with missing values
        $tempFile = tempnam(sys_get_temp_dir(), 'freshrss_test_');
        file_put_contents($tempFile, '<?php return [
            // Only specify some values, others will use defaults
            "ollama_summarizer_chrome_host" => "custom-host",
            "ollama_summarizer_ollama_model" => "mistral",
        ];');

        // Initialize user configuration with our temp file
        $userConfig = FreshRSS_UserConfiguration::init($tempFile);

        // Create configuration from user config
        $config = Configuration::fromUserConfiguration($userConfig);

        // Cleanup the temp file
        @unlink($tempFile);

        // Check that specified values are used
        $this->assertEquals('custom-host', $config->getChromeHost());
        $this->assertEquals('mistral', $config->getOllamaModel());

        // Check that default values are used for missing values
        $defaults = Configuration::createDefault();
        $this->assertEquals($defaults->getChromePort(), $config->getChromePort());
        $this->assertEquals($defaults->getOllamaUrl(), $config->getOllamaUrl());
        $this->assertEquals($defaults->getModelOptions(), $config->getModelOptions());
        $this->assertEquals($defaults->getPromptLengthLimit(), $config->getPromptLengthLimit());
        $this->assertEquals($defaults->getContextLength(), $config->getContextLength());
        $this->assertEquals($defaults->getPromptTemplate(), $config->getPromptTemplate());
        $this->assertEquals($defaults->getSelectedFeeds(), $config->getSelectedFeeds());
    }

    public function testToArray(): void
    {
        $config = new Configuration(
            chromeHost: 'test-host',
            chromePort: 1234,
            ollamaUrl: 'http://test-ollama:11434',
            ollamaModel: 'test-model',
            modelOptions: ['test-option' => 'value'],
            promptLengthLimit: 4096,
            contextLength: 2048,
            promptTemplate: 'Test template',
            selectedFeeds: [1, 2, 3],
        );

        $array = $config->toArray();
        $this->assertEquals('test-host', $array['ollama_summarizer_chrome_host']);
        $this->assertEquals(1234, $array['ollama_summarizer_chrome_port']);
        $this->assertEquals('http://test-ollama:11434', $array['ollama_summarizer_ollama_url']);
        $this->assertEquals('test-model', $array['ollama_summarizer_ollama_model']);
        $this->assertEquals(['test-option' => 'value'], $array['ollama_summarizer_model_options']);
        $this->assertEquals(4096, $array['ollama_summarizer_prompt_length_limit']);
        $this->assertEquals(2048, $array['ollama_summarizer_context_length']);
        $this->assertEquals('Test template', $array['ollama_summarizer_prompt_template']);
        $this->assertEquals([1, 2, 3], $array['ollama_summarizer_selected_feeds']);
    }

    public function testIsFeedSelected(): void
    {
        $config = new Configuration(
            'localhost',
            9222,
            'http://localhost:11434',
            'llama3',
            [],
            8192,
            4096,
            'Test prompt',
            [1, 3, 5]
        );

        $this->assertTrue($config->isFeedSelected(1));
        $this->assertFalse($config->isFeedSelected(2));
        $this->assertTrue($config->isFeedSelected(3));
        $this->assertFalse($config->isFeedSelected(4));
        $this->assertTrue($config->isFeedSelected(5));
    }

    public function testValidationInvalidChromePort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Chrome port must be between 1 and 65535');

        new Configuration(
            'localhost',
            70000, // Invalid port
            'http://localhost:11434',
            'llama3',
            [],
            8192,
            4096,
            'Test prompt',
            []
        );
    }

    public function testValidationInvalidOllamaUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Ollama URL');

        new Configuration(
            'localhost',
            9222,
            'invalid-url', // Invalid URL
            'llama3',
            [],
            8192,
            4096,
            'Test prompt',
            []
        );
    }

    public function testValidationInvalidPromptLengthLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt length limit must be between 1024 and 32768');

        new Configuration(
            'localhost',
            9222,
            'http://localhost:11434',
            'llama3',
            [],
            500, // Too small
            4096,
            'Test prompt',
            []
        );
    }

    public function testValidationInvalidContextLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Context length must be between 1024 and 32768');

        new Configuration(
            'localhost',
            9222,
            'http://localhost:11434',
            'llama3',
            [],
            8192,
            40000, // Too large
            'Test prompt',
            []
        );
    }

    public function testValidationEmptyPromptTemplate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt template cannot be empty');

        new Configuration(
            'localhost',
            9222,
            'http://localhost:11434',
            'llama3',
            [],
            8192,
            4096,
            '', // Empty template
            []
        );
    }

    public function testValidationInvalidMaxRetries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max retries must be between 1 and 10');

        new Configuration(
            'localhost',
            9222,
            'http://localhost:11434',
            'llama3',
            [],
            8192,
            4096,
            'Test prompt',
            [],
            0 // Invalid max retries
        );
    }

    public function testValidationInvalidRetryDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry delay must be between 100 and 60000 milliseconds');

        new Configuration(
            'localhost',
            9222,
            'http://localhost:11434',
            'llama3',
            [],
            8192,
            4096,
            'Test prompt',
            [],
            3,
            0 // Invalid retry delay
        );
    }
}
