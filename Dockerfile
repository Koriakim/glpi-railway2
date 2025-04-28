FROM php:8.1-apache

RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libxml2-dev \
    mariadb-client \
    unzip wget \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mysqli pdo pdo_mysql xml zip

RUN a2enmod rewrite

# Baixar e instalar GLPI automaticamente
RUN wget https://github.com/glpi-project/glpi/releases/download/10.0.14/glpi-10.0.14.tgz && \
    tar -xvzf glpi-10.0.14.tgz && \
    mv glpi/* /var/www/html/ && \
    rm -rf glpi glpi-10.0.14.tgz

WORKDIR /var/www/html

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
