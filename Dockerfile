FROM php:8.3-cli-alpine

RUN apk add --no-cache tini curl-dev sqlite-dev linux-headers \
    && docker-php-ext-install pcntl sqlite3 sockets

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist

COPY src/ src/
COPY *.php ./
COPY devices.example.json ./

EXPOSE 9100

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php", "launcher.php", "--port=9100"]
