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
        jq \
        shellcheck \
        unzip \
    && { for attempt in 1 2 3; do pecl install mongodb-2.5.5 pcov-1.0.12 && break; [ "$attempt" -lt 3 ] || exit 1; sleep 5; done; } \
    && docker-php-ext-enable mongodb pcov \
    && docker-php-ext-install intl pcntl pdo_pgsql pdo_mysql \
    && rm -rf /var/lib/apt/lists/* /tmp/pear \
    && mkdir -p /tmp/composer-cache \
    && chmod 1777 /tmp/composer-cache

RUN { for attempt in 1 2 3; do \
        curl --fail --location --show-error --retry 2 --retry-all-errors \
            "https://github.com/phpredis/phpredis/archive/df4fab2de7fc327c54c94a13af2b9542e4fbd720.tar.gz" \
            --output /tmp/phpredis-6.3.0.tar.gz \
            && break; \
        [ "$attempt" -lt 3 ] || exit 1; sleep 5; \
    done; } \
    && mkdir -p /tmp/phpredis \
    && tar -xzf /tmp/phpredis-6.3.0.tar.gz --strip-components=1 -C /tmp/phpredis \
    && cd /tmp/phpredis \
    && phpize \
    && ./configure \
    && make -j"$(nproc)" \
    && make install \
    && docker-php-ext-enable redis \
    && php -r 'exit(phpversion("redis") === "6.3.0" ? 0 : 1);' \
    && rm -rf /tmp/phpredis /tmp/phpredis-6.3.0.tar.gz

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_CACHE_DIR=/tmp/composer-cache \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
