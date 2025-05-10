FROM php:8.3-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files
COPY composer.json composer.lock* ./

RUN mkdir -p vendor/
RUN git clone https://github.com/FreshRSS/FreshRSS.git vendor/freshrss

# Install dependencies
RUN composer install --no-interaction --no-scripts

# Copy the rest of the application
COPY *.php .
COPY Controllers/ .
COPY composer.json .
COPY composer.lock .
COPY phpunit.xml .
COPY phpstan.neon .
COPY psalm.xml .
COPY .php-cs-fixer.dist.php .
COPY config-user.default.php .
COPY psalm-baseline.xml .
COPY psalm.xml .
# Create a script to run all checks
RUN echo '#!/bin/bash\n\
composer run-script test\n\
' > /usr/local/bin/run-checks && \
    chmod +x /usr/local/bin/run-checks

RUN /usr/local/bin/run-checks