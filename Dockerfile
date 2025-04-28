FROM php:8.1-apache

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    libzip-dev \
    libcurl4-openssl-dev \
    libicu-dev \
    libldap2-dev \
    libbz2-dev \
    unzip \
    wget \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    gd \
    mysqli \
    pdo \
    pdo_mysql \
    xml \
    zip \
    curl \
    intl \
    ldap \
    exif \
    bz2 \
    opcache

# Habilitar módulo rewrite do Apache
RUN a2enmod rewrite

# Baixar e configurar GLPI
RUN wget https://github.com/glpi-project/glpi/releases/download/10.0.14/glpi-10.0.14.tgz && \
    tar -xvzf glpi-10.0.14.tgz && \
    mkdir /var/www/html/public && \
    mv glpi/* /var/www/html/public/ && \
    rm -rf glpi glpi-10.0.14.tgz

# Corrigir permissões
RUN chown -R www-data:www-data /var/www/html

# Definir diretório de trabalho
WORKDIR /var/www/html/public
