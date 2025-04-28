# Usar a imagem oficial do PHP com Apache
FROM php:8.1-apache

# Instalar dependências necessárias (extensões PHP e bibliotecas)
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    mariadb-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mysqli pdo pdo_mysql xml zip

# Habilitar módulo do Apache para reescrita de URLs
RUN a2enmod rewrite

# Copiar o código do GLPI para o servidor web
COPY . /var/www/html

# Definir o diretório de trabalho
WORKDIR /var/www/html

# Garantir permissões corretas para o GLPI
RUN chown -R www-data:www-data /var/www/html

# Expor a porta 80 para acesso ao servidor web
EXPOSE 80
