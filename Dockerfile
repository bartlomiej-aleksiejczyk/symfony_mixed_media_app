FROM php:8.4-fpm-alpine

RUN apk --no-cache add \
    sqlite-dev \
    bash \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    libxml2-dev \
    git \
    libzip-dev \
    nginx \
    && docker-php-ext-configure zip \
    && docker-php-ext-install pdo pdo_sqlite zip gd

RUN curl -1sLf 'https://dl.cloudsmith.io/public/symfony/stable/setup.alpine.sh' | bash \
    && apk add symfony-cli

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/symfony

COPY . /var/www/symfony

RUN chown -R www-data:www-data /var/www/symfony

ENV APP_ENV=prod
ENV SYMFONY_ENV=prod
ENV APP_DEBUG=0

RUN composer install --no-dev --optimize-autoloader

RUN php bin/console asset-map:compile

RUN symfony console secrets:set APP_SECRET --random

EXPOSE 80 443

CMD ["sh", "-c", "php-fpm & nginx -g 'daemon off;'"]
