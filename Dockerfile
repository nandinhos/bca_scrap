FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    poppler-utils \
    libmariadb-dev \
    zip \
    unzip \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite

COPY analise.php /var/www/html/
COPY *.php /var/www/html/
COPY *.html /var/www/html/

RUN mkdir -p /var/www/html/arcadia/busca_bca/boletim_bca

RUN chmod -R 777 /var/www/html/arcadia

EXPOSE 80
