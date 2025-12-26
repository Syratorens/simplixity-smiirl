FROM php:8.2.30-fpm-alpine3.22

# Installer les extensions PHP nécessaires
RUN apk add --no-cache \
    curl \
    && docker-php-ext-install opcache

# Configurer le répertoire de travail
WORKDIR /var/www/html

# Copier les fichiers de l'application
COPY . /var/www/html

# Exposer le port
EXPOSE 8000

# Commande pour démarrer le serveur PHP built-in
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/var/www/html"]
