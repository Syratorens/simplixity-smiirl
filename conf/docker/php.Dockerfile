FROM php:8.2.30-fpm-alpine3.22

# Installer les extensions PHP nécessaires
RUN apk add --no-cache \
    curl \
    && docker-php-ext-install opcache

# Configurer le répertoire de travail
WORKDIR /var/www/html

CMD ["php-fpm"]
