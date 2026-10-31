FROM php:8.2-apache

# Install SQLite and PDO extensions
RUN apt-get update && apt-get install -y \
    libsqlite3-dev libpng-dev libjpeg62-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_sqlite pdo_mysql gd exif \
    && a2enmod rewrite headers expires deflate \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Kapak resmi ve toplu kitap (kitApp) yüklemeleri için PHP boyut sınırları
RUN printf "upload_max_filesize=64M\npost_max_size=64M\nmemory_limit=256M\nmax_execution_time=180\n" > /usr/local/etc/php/conf.d/uploads.ini

# Fotoğraftan kitap tanıma (ISBN yardımcısı) için PHP paketleri: Anthropic SDK + Guzzle
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip git && rm -rf /var/lib/apt/lists/*

# Copy project files
COPY . /var/www/html/
WORKDIR /var/www/html
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# Render kalıcı diski (/var/data) çalışma anında bağlanır; Apache'nin (www-data) yazabilmesi için izinleri başlangıçta ver
CMD ["sh", "-c", "mkdir -p /var/data && chown -R www-data:www-data /var/data && exec apache2-foreground"]
