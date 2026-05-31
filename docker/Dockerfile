# StarDust local-development image.
#
# PHP 8.1 CLI (the documented version floor) + pdo_mysql + Composer.
# Dependencies are installed at run time by the `init` service into the
# bind-mounted working tree, so a fresh clone with no vendor/ still runs.
FROM php:8.1-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Composer from the official image — no installer script to verify.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

CMD ["php", "-v"]
