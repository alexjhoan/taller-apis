FROM php:8.2-apache

# Instalar extensiones necesarias para conectar PHP con MariaDB (mysqli / pdo_mysql)
RUN docker-php-ext-install mysqli

# Activar mod_rewrite de Apache para rutas limpias en PHP
RUN a2enmod rewrite

# Copiar el código PHP al directorio web de Apache
COPY . /var/www/html/

EXPOSE 80