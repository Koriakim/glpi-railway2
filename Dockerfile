FROM php:8.2-apache

# Instala dependências necessárias
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    mariadb-client \
    unzip \
    libicu-dev \
    libldap2-dev \
    libsasl2-dev \
    libbz2-dev \
    zlib1g-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mysqli pdo pdo_mysql xml zip intl exif ldap bz2 \
    && docker-php-ext-enable opcache

# Copia os arquivos do GLPI
COPY . /var/www/html

# Corrige permissões
RUN chown -R www-data:www-data /var/www/html

# Configura Apache para a porta 8080
RUN sed -i 's/80/8080/g' /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf

# Expõe a porta 8080
EXPOSE 8080

# Inicializa o Apache
CMD ["apache2-foreground"]
