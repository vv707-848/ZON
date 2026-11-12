# The backend for local development (compose.yaml): PHP 8.5 with the extensions production has, and the same
# PHP settings (deploy/php/zon.ini). The code is mounted, not copied: an edit shows on the next request.
FROM php:8.5-cli

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath exif gd intl pcntl pdo_mysql redis zip \
 && apt-get update && apt-get install -y --no-install-recommends unzip curl \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY deploy/php/zon.ini /usr/local/etc/php/conf.d/99-zon.ini
COPY docker/backend-entrypoint.sh /usr/local/bin/zon-backend

WORKDIR /app/backend
ENTRYPOINT ["zon-backend"]
CMD ["web"]
