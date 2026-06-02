FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    rabbitmq-c-dev \
    autoconf \
    g++ \
    make \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        zip \
        opcache \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && apk del autoconf g++ make

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
