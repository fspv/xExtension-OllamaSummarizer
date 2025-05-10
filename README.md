# FreshRSS Ollama Extension

This extension integrates FreshRSS with Ollama to automatically generate summaries and tags for your RSS feed entries.

## Features

- Automatically processes new RSS entries
- Generates concise summaries of article content
- Creates relevant tags based on article content
- Uses Chrome DevTools Protocol to fetch complete article content
- Configurable parameters for Ollama host, model, summary length, and number of tags

## Requirements

- FreshRSS instance
- Chrome/Chromium running with remote debugging enabled
- Ollama server running locally or accessible on your network
- PHP Composer with WebSocket library installed

## Installation

See a minimal working example in the [docker-compose.yml](/docker-compose.yml).

You need to clone the extension in the `./freshrss/extensions` dir and spawn chrome and ollama services.

## How It Works

1. When a new entry is fetched by FreshRSS, the extension processes it
2. The extension uses Chrome DevTools Protocol via WebSocket to fetch the full article content
3. The fetched content is sent to Ollama with a prompt to generate a summary and tags
4. The generated summary is appended to the article content with an `<hr/>` separator
5. The generated tags are added to the entry
6. The entry is marked with the tag `ai-processed` to prevent reprocessing

## Troubleshooting

- Check FreshRSS logs for debug information
- Ensure Chrome is running with remote debugging enabled (`--remote-debugging-port=9222`)
- Verify Ollama is running and accessible from your FreshRSS instance
- Make sure the WebSocket PHP library is correctly installed

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

Before submitting a Pull Request make sure to run all the tests and lints like this:

```sh
sudo docker build -t test .
```

Additionally, run the application with a provided minimal docker compose file with `docker compose up` and try to check if the extension is actually working.

Make sure to test:

1. Create a new user
2. Enable the extension
3. Set model to `gemma3:1b-it-qat`
3. Check that chrome and ollama show up as connected
4. Try to press the summarize button on any entry
5. Try to enable the default feed and reload articles