FROM mlocati/php-extension-installer:2.4 AS php-extension-installer

FROM php:8.4-fpm

COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/bin/install-php-extensions

RUN apt-get update && apt-get install -y \
    nginx \
    libzip-dev \
    zip \
    libssl-dev \
    libicu-dev \
    libpq-dev \
    default-mysql-client \
    postgresql-client \
    && docker-php-ext-install \
      zip \
      intl \
      bcmath \
      sockets \
      pdo_mysql \
      pdo_pgsql \
      pgsql

# amqp: Symfony Messenger AMQP transport (RabbitMQ, ENABLE_RABBITMQ=1) from web
# requests — the php-cli image already has it; same installer as php-cli.
RUN install-php-extensions amqp

ENV COMPOSER_ALLOW_SUPERUSER 1

RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer

WORKDIR /var/www

# Copy nginx config
COPY docker/php-fpm/nginx.conf /etc/nginx/sites-available/default
RUN ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default && \
    rm -f /etc/nginx/sites-enabled/default.conf

# Copy PHP-FPM config
COPY docker/php-fpm/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-custom.conf

# Copy entrypoint script
COPY docker/php-fpm/docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["docker-entrypoint.sh"]
