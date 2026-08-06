####################################
# SAFCO FINTECH LMS - Single-stage Runtime
# vendor/ is pre-built on the host via `composer install`
####################################
FROM php:8.4-fpm-alpine

LABEL maintainer="SAFCO FINTECH LTD <dev@safcofintech.co.tz>"
LABEL org.opencontainers.image.title="SAFCO FINTECH LMS Backend"

# System deps + PHP extensions
RUN apk add --no-cache \
        bash \
        curl \
        git \
        icu-dev \
        libpng-dev \
        libzip-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
        supervisor \
        nginx \
        openssl-dev \
        autoconf \
        g++ \
        make \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mysqli \
        intl \
        bcmath \
        pcntl \
        gd \
        zip \
        opcache \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make linux-headers \
    && rm -rf /var/cache/apk/*

# Copy composer binary (for artisan/dev use inside the container)
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Copy application (vendor/ is already built on the host)
WORKDIR /var/www/html
COPY . .

# PHP config
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-safco.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Nginx + Supervisor
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf

# Ownership & permissions (777 for storage since host will mount over it)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache \
    && mkdir -p /run/nginx

EXPOSE 80 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -f http://127.0.0.1/api/v1/health || exit 1

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
