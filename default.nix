{ pkgs ? import <nixpkgs> {} }:

pkgs.mkShell {
  buildInputs = with pkgs; [
    php84
    php84Packages.composer
    git
  ];

  shellHook = ''
    echo "Setting up FreshRSS Ollama Summarizer development environment..."

    # Clone FreshRSS repository if it doesn't exist
    if [ ! -d "vendor/freshrss" ]; then
      echo "Cloning FreshRSS repository..."
      git clone https://github.com/FreshRSS/FreshRSS.git vendor/freshrss
    fi

    # Install/update composer dependencies (idempotent - does nothing if already installed)
    echo "Running composer install..."
    composer install

    echo ""
    echo "Development environment ready!"
    echo "Available commands:"
    echo "  composer test       - Run all tests (cs-check, phpstan, psalm, phpunit)"
    echo "  composer cs         - Fix code style"
    echo "  composer phpstan    - Run PHPStan analysis"
    echo "  composer psalm      - Run Psalm analysis"
    echo "  phpunit             - Run unit tests"
    echo ""
  '';
}
