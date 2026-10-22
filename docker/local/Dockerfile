FROM node:24-bookworm-slim AS assets
WORKDIR /app
RUN corepack enable
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY scripts/spotlight-noindex.mjs scripts/spotlight-noindex.mjs
RUN pnpm install --frozen-lockfile
COPY resources resources
COPY public public
COPY vite.config.ts tsconfig.json postcss.config.js ./
RUN pnpm run build

FROM php:8.5-cli-bookworm AS app
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl git unzip libicu-dev libzip-dev \
    && docker-php-ext-install -j2 intl zip pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY . .
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache
COPY --from=assets /app/public/build public/build
RUN printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/local-staging.ini
CMD ["sh", "docker/local/start.sh"]

FROM python:3.13-slim-bookworm AS transcoder
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/* \
    && pip install --no-cache-dir boto3==1.40.71
WORKDIR /worker
COPY docker/local/transcode.py .
CMD ["python", "-u", "transcode.py"]
