FROM php:8.3-fpm-alpine

RUN apk add --no-cache curl git ffmpeg mariadb-client

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

RUN mkdir -p /var/www/html/storage && ln -sfn /var/www/html/storage /storage && chmod -R 777 /var/www/html/storage

WORKDIR /var/www/backend

EXPOSE 9000
CMD ["php-fpm"]
