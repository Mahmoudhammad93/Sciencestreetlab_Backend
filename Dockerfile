# =========================
# 1. PHP base
# =========================
FROM php:8.3-fpm AS php

WORKDIR /var/www

RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        xml \
        intl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer


# =========================
# 2. PHP dependencies
# =========================
FROM php AS dependencies

WORKDIR /var/www

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts


# =========================
# 3. Vite build
# =========================
FROM node:20-alpine AS frontend

WORKDIR /var/www

COPY package.json package-lock.json* ./

RUN npm ci

COPY . .

RUN npm run build


# =========================
# 4. Final Laravel image
# =========================
FROM php AS production

WORKDIR /var/www

COPY --from=dependencies /var/www/vendor ./vendor

COPY . .

# Copy Vite build
COPY --from=frontend /var/www/public/build ./public/build

# Run Composer Laravel scripts now that the app + Vite manifest exist
RUN composer dump-autoload --optimize

RUN chown -R www-data:www-data \
    storage \
    bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]