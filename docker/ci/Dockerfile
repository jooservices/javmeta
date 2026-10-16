# syntax=docker/dockerfile:1
# Lean quality-gate image: same PHP + extensions as the app image, plus pcov
# for coverage. No browser libraries, no Node. Used locally and in CI.
FROM php:8.5.11-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libicu-dev \
        libpq-dev \
        libssl-dev \
        pkg-config \
        unzip \
    && pecl install mongodb pcov \
    && docker-php-ext-enable mongodb pcov \
    && docker-php-ext-install intl pcntl pdo_pgsql pdo_mysql \
    && rm -rf /var/lib/apt/lists/* /tmp/pear \
    && mkdir -p /tmp/composer-cache \
    && chmod 1777 /tmp/composer-cache

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_CACHE_DIR=/tmp/composer-cache \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
