# Usar imagen oficial de PHP con Apache
FROM php:8.1-apache

# Habilitar módulo mysqli para conexiones a MySQL
RUN docker-php-ext-install mysqli

# Habilitar mod_rewrite para URLs amigables (por si acaso)
RUN a2enmod rewrite

# Configurar Apache
RUN echo 'ServerName localhost' >> /etc/apache2/apache2.conf

# Copiar todos los archivos al directorio web
COPY . /var/www/html/

# Establecer permisos correctos
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Exponer puerto 80 (Render mapeará automáticamente el puerto)
EXPOSE 80

# Render automáticamente inyecta la variable $PORT, pero Apache usa 80 internamente
# Render maneja el mapeo del puerto externo al 80 interno
CMD ["apache2-foreground"]

