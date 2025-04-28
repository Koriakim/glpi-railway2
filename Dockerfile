FROM php:8.2-apache

# Instalar dependências necessárias
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
    libssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mysqli pdo pdo_mysql xml zip intl exif ldap bz2 \
    && docker-php-ext-enable opcache

# Copiar o GLPI para o Apache
COPY . /var/www/html

# Corrigir permissões
RUN chown -R www-data:www-data /var/www/html

# Habilitar configurações de sessão seguras no PHP
RUN echo "session.cookie_httponly = 1" >> /usr/local/etc/php/php.ini

# Configurar Apache para rodar na porta 8080
RUN sed -i 's/80/8080/g' /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf

# Expor a porta
EXPOSE 8080

# Iniciar Apache
CMD ["apache2-foreground"]
