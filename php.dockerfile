FROM php:8.5-cli-alpine

RUN apk add supervisor autoconf gcc libc-dev make openssl-dev

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/

RUN install-php-extensions pdo pdo_mysql mongodb-2.3.3
