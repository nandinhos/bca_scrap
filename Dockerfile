FROM php:8.4-apache

RUN apt-get update && apt-get install -y \
    poppler-utils \
    libmariadb-dev \
    zip \
    unzip \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && a2enmod rewrite headers

COPY analise.php /var/www/html/
COPY *.php /var/www/html/
COPY *.html /var/www/html/
COPY .htaccess /var/www/html/

RUN mkdir -p /var/www/html/arcadia/busca_bca/boletim_bca

RUN chmod -R 755 /var/www/html && \
    chmod -R 775 /var/www/html/arcadia/busca_bca/boletim_bca

RUN useradd -m -s /bin/bash appuser && \
    chown -R appuser:www-data /var/www/html

EXPOSE 80

USER www-data

EXPOSE 80
