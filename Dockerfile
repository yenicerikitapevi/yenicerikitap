FROM php:8.2-apache

# Install SQLite and PDO extensions
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite pdo_mysql \
    && a2enmod rewrite

# Copy project files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# Render kalıcı diski (/var/data) çalışma anında bağlanır; Apache'nin (www-data) yazabilmesi için izinleri başlangıçta ver
CMD ["sh", "-c", "mkdir -p /var/data && chown -R www-data:www-data /var/data && exec apache2-foreground"]
