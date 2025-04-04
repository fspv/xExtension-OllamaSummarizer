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

1. Clone the repo into the `extensions` dir.
2. Modify your docker compose to add the websockets library via PHP composer
```yaml
  freshrss:
    build:
      context: .
      dockerfile_inline:
        FROM freshrss/freshrss:edge
        RUN apt-get update -y
        RUN apt-get upgrade -y
        RUN apt-get install -y composer
        RUN composer require textalk/websocket
```
3. Run chrome with remote debugging enabled:
```
  chrome:
    image: gcr.io/zenika-hub/alpine-chrome:123
    restart: unless-stopped
    container_name: chrome
    command:
      - --no-sandbox
      - --disable-gpu
      - --disable-dev-shm-usage
      - --remote-debugging-address=127.0.0.1
      - --remote-debugging-port=9222
      - --hide-scrollbars
      - --disable-extensions-except=/chrome/extensions/isdcac
      - --load-extension=/chrome/extensions/isdcac
      - --enable-logging
      - --verbose
      - --log-level=debug
      # - --v=3 # full debug
      - --enable-features=ConversionMeasurement,AttributionReportingCrossAppWeb
```

## Configuration

1. Enable the extension in FreshRSS Extensions Manager
2. Configure the extension settings:
   - **Chrome Host**: The hostname or IP where Chrome is running (default: localhost)
   - **Chrome Port**: The Chrome DevTools port (default: 9222)
   - **Ollama Host**: The URL of your Ollama server (default: http://localhost:11434)
   - **Ollama Model**: The LLM model to use (default: llama3)
   - **Number of Tags**: How many tags to generate per article (default: 5)
   - **Summary Length**: Target length for article summaries in words (default: 150)

## How It Works

1. When a new entry is fetched by FreshRSS, the extension processes it
2. The extension uses Chrome DevTools Protocol via WebSocket to fetch the full article content
3. The fetched content is sent to Ollama with a prompt to generate a summary and tags
4. The generated summary is appended to the article content with an `<hr/>` separator
5. The generated tags are added to the entry
6. The entry is marked with the tag `ollama-processed` to prevent reprocessing

## Troubleshooting

- Check FreshRSS logs for debug information
- Ensure Chrome is running with remote debugging enabled (`--remote-debugging-port=9222`)
- Verify Ollama is running and accessible from your FreshRSS instance
- Make sure the WebSocket PHP library is correctly installed

## Advanced Configuration

The extension supports the following configuration options which can be set via the UI:

- `chrome_host`: Hostname where Chrome is running
- `chrome_port`: Port for Chrome DevTools Protocol
- `ollama_host`: URL for Ollama API
- `ollama_model`: Model name to use for generation
- `num_tags`: Number of tags to generate
- `summary_length`: Target summary length in words

## License

[License information here]

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.
