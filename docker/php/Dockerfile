FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    curl \
    aria2 \
    git \
    ffmpeg \
    mariadb-client \
    python3 \
    py3-pip \
    yt-dlp \
    ca-certificates \
    tzdata \
    && update-ca-certificates

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    opcache

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN mkdir -p /var/www/html/storage /mnt/media_library \
    && ln -sfn /var/www/html/storage /storage \
    && chmod -R 777 /var/www/html/storage \
    && chmod 775 /mnt/media_library

WORKDIR /var/www/backend

EXPOSE 9000
CMD ["php-fpm"]