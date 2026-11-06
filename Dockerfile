FROM php:8.2-apache

# Instalar extensiones necesarias para MariaDB y Web Push
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libgmp-dev libonig-dev unzip \
    && docker-php-ext-install mysqli curl gmp mbstring \
    && rm -rf /var/lib/apt/lists/*

# Activar mod_rewrite de Apache para rutas limpias en PHP
RUN a2enmod rewrite

# Instalar dependencias bloqueadas por Composer dentro de la imagen
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# Crear la carpeta de evidencias y asignar permisos seguros para Apache
RUN mkdir -p /var/www/html/uploads/evidences && \
    chown -R www-data:www-data /var/www/html/uploads && \
    chmod -R 775 /var/www/html/uploads

# Copiar el código fuente al contenedor
COPY . /var/www/html/

# Preparar el directorio persistente de evidencias para el usuario de Apache
RUN mkdir -p /var/www/html/assets/evidence \
    && chown -R www-data:www-data /var/www/html/assets/evidence \
    && find /var/www/html/assets/evidence -type d -exec chmod 2775 {} + \
    && find /var/www/html/assets/evidence -type f -exec chmod 664 {} +

EXPOSE 80