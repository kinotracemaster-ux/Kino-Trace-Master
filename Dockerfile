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
RUN echo "upload_max_filesize = 128M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 128M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini

# Habilitar mod_rewrite de Apache y FIX para MPM conflict
RUN a2dismod mpm_event && a2enmod mpm_prefork && a2enmod rewrite

# Copiar archivos de la aplicación
COPY . /var/www/html/

# Crear directorios necesarios con permisos
RUN mkdir -p /var/www/html/clients \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/clients

# Permitir .htaccess
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Script de inicio que configura el puerto dinámicamente, arregla MPM y permisos
RUN echo '#!/bin/bash\n\
    rm -f /etc/apache2/mods-enabled/mpm_event.* 2>/dev/null\n\
    chown -R www-data:www-data /var/www/html/clients\n\
    chmod -R 777 /var/www/html/clients\n\
    sed -i "s/80/${PORT:-8080}/g" /etc/apache2/sites-available/000-default.conf\n\
    sed -i "s/Listen 80/Listen ${PORT:-8080}/g" /etc/apache2/ports.conf\n\
    apache2-foreground' > /start.sh && chmod +x /start.sh

EXPOSE 8080

CMD ["/bin/bash", "/start.sh"]
