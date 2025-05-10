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
        $this->assertEquals('http://localhost:11434', $config->getOllamaHost());
        $this->assertEquals('llama3', $config->getOllamaModel());
        $this->assertEquals([], $config->getModelOptions());
        $this->assertEquals(8192, $config->getPromptLengthLimit());
        $this->assertEquals(4096, $config->getContextLength());
        $this->assertStringContainsString('Based on the following article content', $config->getPromptTemplate());
        $this->assertEquals([], $config->getSelectedFeeds());
    }

    public function testFromUserConfiguration(): void
    {
        // Create a temporary config file
        $tempFile = tempnam(sys_get_temp_dir(), 'freshrss_test_');
        file_put_contents($tempFile, '<?php return [
            "freshrss_ollama_chrome_host" => "custom-host",
            "freshrss_ollama_chrome_port" => 9999,
            "freshrss_ollama_ollama_host" => "http://custom-ollama:11434",
            "freshrss_ollama_ollama_model" => "mistral",
            "freshrss_ollama_model_options" => ["temperature" => 0.7],
            "freshrss_ollama_prompt_length_limit" => 4000,
            "freshrss_ollama_context_length" => 2048,
            "freshrss_ollama_prompt_template" => "Custom template",
            "freshrss_ollama_selected_feeds" => [1, 2, 3],
        ];');

        // Initialize user configuration with our temp file
        $userConfig = FreshRSS_UserConfiguration::init($tempFile);

        // Create configuration from user config
        $config = Configuration::fromUserConfiguration($userConfig);

        // Cleanup the temp file
        @unlink($tempFile);

        $this->assertEquals('custom-host', $config->getChromeHost());
        $this->assertEquals(9999, $config->getChromePort());
        $this->assertEquals('http://custom-ollama:11434', $config->getOllamaHost());
        $this->assertEquals('mistral', $config->getOllamaModel());
        $this->assertEquals(['temperature' => 0.7], $config->getModelOptions());
        $this->assertEquals(4000, $config->getPromptLengthLimit());
        $this->assertEquals(2048, $config->getContextLength());
        $this->assertEquals('Custom template', $config->getPromptTemplate());
        $this->assertEquals([1, 2, 3], $config->getSelectedFeeds());
    }

    public function testFromUserConfigurationWithMissingValues(): void
    {
        // Create a temporary config file with missing values
        $tempFile = tempnam(sys_get_temp_dir(), 'freshrss_test_');
        file_put_contents($tempFile, '<?php return [
            // Only specify some values, others will use defaults
            "freshrss_ollama_chrome_host" => "custom-host",
            "freshrss_ollama_ollama_model" => "mistral",
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
        $this->assertEquals($defaults->getOllamaHost(), $config->getOllamaHost());
        $this->assertEquals($defaults->getModelOptions(), $config->getModelOptions());
        $this->assertEquals($defaults->getPromptLengthLimit(), $config->getPromptLengthLimit());
        $this->assertEquals($defaults->getContextLength(), $config->getContextLength());
        $this->assertEquals($defaults->getPromptTemplate(), $config->getPromptTemplate());
        $this->assertEquals($defaults->getSelectedFeeds(), $config->getSelectedFeeds());
    }

    public function testToArray(): void
    {
        $config = new Configuration(
            'test-host',
            1234,
            'http://test-ollama:11434',
            'test-model',
            ['test-option' => 'value'],
            5000,
            3000,
            'Test prompt template',
            [5, 6, 7]
        );

        $array = $config->toArray();

        $this->assertEquals('test-host', $array['freshrss_ollama_chrome_host']);
        $this->assertEquals(1234, $array['freshrss_ollama_chrome_port']);
        $this->assertEquals('http://test-ollama:11434', $array['freshrss_ollama_ollama_host']);
        $this->assertEquals('test-model', $array['freshrss_ollama_ollama_model']);
        $this->assertEquals(['test-option' => 'value'], $array['freshrss_ollama_model_options']);
        $this->assertEquals(5000, $array['freshrss_ollama_prompt_length_limit']);
        $this->assertEquals(3000, $array['freshrss_ollama_context_length']);
        $this->assertEquals('Test prompt template', $array['freshrss_ollama_prompt_template']);
        $this->assertEquals([5, 6, 7], $array['freshrss_ollama_selected_feeds']);
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

    public function testValidationInvalidOllamaHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Ollama host URL');

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

    public function testValidationEmptyOllamaModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ollama model name cannot be empty');

        new Configuration(
            'localhost',
            9222,
            'http://localhost:11434',
            '', // Empty model name
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
}
