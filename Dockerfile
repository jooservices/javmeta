# syntax=docker/dockerfile:1

FROM node:26.11.1-bookworm-slim AS node-runtime

FROM php:8.5.11-cli-bookworm

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        libasound2 \
        libatk-bridge2.0-0 \
        libatk1.0-0 \
        libcups2 \
        libcurl4-openssl-dev \
        libdbus-1-3 \
        libdrm2 \
        libgbm1 \
        libgtk-3-0 \
        libicu-dev \
        libnss3 \
        libpq-dev \
        libssl-dev \
        libx11-xcb1 \
        libxcomposite1 \
        libxdamage1 \
        libxfixes3 \
        libxkbcommon0 \
        libxrandr2 \
        pkg-config \
        unzip \
        xvfb \
        xauth \
        zip \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && docker-php-ext-install intl pcntl pdo_pgsql pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node-runtime /usr/local/ /usr/local/
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

COPY docker/crawlerx-fetch-ready.sh /usr/local/bin/crawlerx-fetch-ready
COPY docker/worker-queues.sh /usr/local/bin/worker-queues
COPY docker/ready-check.sh /usr/local/bin/ready-check
RUN chmod +x /usr/local/bin/crawlerx-fetch-ready /usr/local/bin/worker-queues /usr/local/bin/ready-check

ENV CRAWLERX_ROOT=/var/www/html/vendor/jooservices/crawlerx \
    CRAWLERX_NODE=node \
    CRAWLERX_PLAYWRIGHT_SCRIPT=/var/www/html/vendor/jooservices/crawlerx/scripts/playwright-fetch.mjs \
    CRAWLERX_PUPPETEER_SCRIPT=/var/www/html/vendor/jooservices/crawlerx/scripts/puppeteer-stealth-fetch.mjs \
    CRAWLERX_FLARESOLVERR_URL=http://flaresolverr:8191/v1 \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
