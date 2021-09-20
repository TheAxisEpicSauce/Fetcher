FROM php:7.4-cli-alpine

RUN apk add supervisor autoconf gcc libc-dev make openssl-dev

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/

RUN docker-php-ext-install pdo pdo_mysql

RUN pecl install mongodb \
    && echo "extension=mongodb.so" > $PHP_INI_DIR/conf.d/mongo.ini

