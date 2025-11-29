# FreshRSS Ollama Summarizer Extension

This extension integrates FreshRSS with Ollama to automatically generate summaries and tags for your RSS feed entries. All you need is a running chrome browser instance and ollama. You can run LLM inference on the CPU, small models like gemma3:1b are more than enough to generate quality summaries.

![20250511_13h10m02s_grim](https://github.com/user-attachments/assets/423ee763-aeeb-4eb9-be60-697865de3f00)


## Features

- Automatically processes new RSS entries
- Uses Chrome DevTools Protocol to fetch complete article content
- Generates concise summaries of article content and displays it for the feed entry in the UI
- Creates relevant tags based on article content and adds them to the feed entry

## Requirements

- FreshRSS instance with PHP Composer with WebSocket library installed
- Chrome/Chromium running with remote debugging enabled
- Ollama server running locally or accessible on your network

## Installation

See a minimal working example in the [docker-compose.yml](/docker-compose.yml).

You need to clone the extension in the `./freshrss/extensions` dir and spawn chrome and ollama services.

## How It Works

1. When a new entry is fetched by FreshRSS, the extension processes it
2. The extension uses Chrome DevTools Protocol via WebSocket to fetch the full article content
3. The fetched content is sent to Ollama with a prompt to generate a summary and tags
4. The generated summary is appended to the article content with an `<hr/>` separator when the article is displayed
5. The generated tags are added to the entry

## Troubleshooting

- Ensure Chrome is running with remote debugging enabled (`--remote-debugging-port=9222`)
- Verify Ollama is running and accessible from your FreshRSS instance
- Make sure the WebSocket PHP library is correctly installed
- Check FreshRSS logs for debug information, each log for a specific entry is marked with the hash of id, so you can grep processing of the same entry in the logs

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

Before submitting a Pull Request make sure to run all the tests and lints like this:

```sh
sudo docker build -t test .
```

Additionally, run the application with a provided minimal docker compose file with `docker compose up` and try to check if the extension is actually working.

Make sure to test:

1. Create a new user (you can disable auth, so you don't need to enter the password)
2. Enable the extension
3. Set model to `gemma3:1b-it-qat`
4. Check that chrome and ollama show up as connected
5. Try to press the summarize button on any entry
6. Try to enable the default feed, delete all articles and then reload articles
7. Check that all the config options are translated.

## Development

### Setting Up Development Environment

This project uses Nix for reproducible development environments. The easiest way to get started:

```sh
nix-shell
```

This will automatically:
- Install PHP 8.4, Composer, and Git
- Clone the FreshRSS repository to `vendor/freshrss` (required for development)
- Install all Composer dependencies

### Running Tests and Linters

The project includes comprehensive testing and code quality checks:

```sh
# Run all tests and linters (recommended before committing)
composer test

# Individual commands:
composer cs-check              # Check code style (PHP CS Fixer)
composer cs                    # Fix code style automatically
composer phpstan               # Run PHPStan static analysis (level 8)
composer psalm -- --show-info=true  # Run Psalm static analysis
phpunit                        # Run unit tests
```

All tests must pass before submitting a Pull Request. The test suite includes:
- **PHP CS Fixer**: Enforces PSR-12 coding standards
- **PHPStan**: Static analysis at level 8
- **Psalm**: Additional static analysis with strict type checking
- **PHPUnit**: 36 unit tests with 129+ assertions

### Code Quality Standards

- All PHP code must be strictly typed (`declare(strict_types=1)`)
- PHPStan must pass at level 8 with no errors
- Psalm must pass with no errors
- All code must follow PSR-12 coding standards
- Unit tests are required for new functionality

### IDE Setup

For LSP/IDE support, the development environment provides:
- PHP 8.4 type checking
- FreshRSS classes available in `vendor/freshrss`
- Composer autoloading configured

Extensions guide: https://freshrss.github.io/FreshRSS/en/developers/03_Backend/05_Extensions.html
