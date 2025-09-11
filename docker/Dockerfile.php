FROM php:8.2-fpm

# System libs for PHP extensions + tools
RUN apt-get update && apt-get install -y --no-install-recommends \
      libicu-dev libzip-dev zlib1g-dev libpng-dev \
      mariadb-client \
      poppler-utils \
      git unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions HotCRP needs
RUN docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) intl mysqli gd zip opcache

# Copy our PHP settings
COPY php.ini /usr/local/etc/php/conf.d/zz-hotcrp.ini

# Workdir is the app root
WORKDIR /var/www/html
