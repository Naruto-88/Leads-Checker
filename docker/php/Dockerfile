FROM php:8.2-apache

# Install basic tools
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl unzip git \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions using docker-php-extension-installer (handles IMAP deps)
RUN curl -fsSL https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions -o /usr/local/bin/install-php-extensions \
    && chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_mysql imap

# Apache: set DocumentRoot to /var/www/html/public and enable mod_rewrite
RUN a2enmod rewrite
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri -e 's/DocumentRoot .*/DocumentRoot \/var\/www\/html\/public/g' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
