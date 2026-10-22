FROM php:8.2-apache

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    poppler-utils \
    tesseract-ocr \
    tesseract-ocr-spa \
    libsqlite3-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Habilitar módulos de PHP (incluido zip para ZipArchive)
RUN docker-php-ext-install pdo pdo_sqlite zip

# Configurar límites de PHP para subida de archivos grandes
RUN echo "upload_max_filesize = 1024M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 1100M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_input_time = 600" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 1024M" >> /usr/local/etc/php/conf.d/uploads.ini

# Habilitar mod_rewrite de Apache y FIX para MPM conflict
RUN a2dismod mpm_event && a2enmod mpm_prefork && a2enmod rewrite

# Limitar workers de Apache para no saturar Railway
RUN echo "<IfModule mpm_prefork_module>\n\
    StartServers 3\n\
    MinSpareServers 2\n\
    MaxSpareServers 5\n\
    MaxRequestWorkers 25\n\
    MaxConnectionsPerChild 200\n\
    </IfModule>" >> /etc/apache2/apache2.conf

# Copiar archivos de la aplicación
COPY . /var/www/html/

# Crear directorios necesarios con permisos
RUN mkdir -p /var/www/html/clients \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/clients

# Permitir .htaccess
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Script de inicio que configura el puerto dinámicamente, arregla MPM y permisos.
# Los sed deben ser idempotentes: si Railway reinicia el MISMO contenedor el script
# vuelve a correr sobre archivos ya modificados. El sed anterior ("s/80/$PORT/g")
# convertía "Listen 8080" en "Listen 808080" y Apache no arrancaba más
# (AH00526: Invalid address or port en ports.conf).
RUN echo '#!/bin/bash\n\
    rm -f /etc/apache2/mods-enabled/mpm_event.* 2>/dev/null\n\
    chown -R www-data:www-data /var/www/html/clients\n\
    chmod -R 777 /var/www/html/clients\n\
    sed -i -E "s/<VirtualHost [*]:[0-9]+>/<VirtualHost *:${PORT:-8080}>/" /etc/apache2/sites-available/000-default.conf\n\
    sed -i -E "s/^Listen [0-9]+$/Listen ${PORT:-8080}/" /etc/apache2/ports.conf\n\
    apache2-foreground' > /start.sh && chmod +x /start.sh

EXPOSE 8080

CMD ["/bin/bash", "/start.sh"]
