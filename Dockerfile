FROM php:8.1-apache

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    libzip-dev \
    unzip \
    wget \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mysqli pdo pdo_mysql xml zip

# Ativar o módulo rewrite do Apache
RUN a2enmod rewrite

# Baixar e instalar o GLPI
RUN wget https://github.com/glpi-project/glpi/releases/download/10.0.14/glpi-10.0.14.tgz && \
    tar -xvzf glpi-10.0.14.tgz && \
    mv glpi/* /var/www/html/ && \
    rm -rf glpi glpi-10.0.14.tgz

# Ajustar permissões
RUN chown -R www-data:www-data /var/www/html

# Definir o diretório de trabalho
WORKDIR /var/www/html

# Expor a porta 80
EXPOSE 80
