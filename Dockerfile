FROM eclipse-temurin:17-jdk AS watermark-build
WORKDIR /build
# Sunucuda görsel temizliği: aktarım uygulamasının güncel paketindeki temizleyici (sürüm adı sabit yazılmaz)
COPY downloads/ ./downloads/
COPY tools/server-watermark/WatermarkCli.java ./
RUN set -e; z=$(ls downloads/kitapekapp-*-guncelleme.zip | sort -V | tail -1); echo "temizleyici: $z"; \
    mkdir x && cd x && jar xf "../$z" && cd .. && cp "$(ls x/app/kit-app-*-small.jar | head -1)" cleaner.jar \
    && javac -encoding UTF-8 --release 17 -cp cleaner.jar WatermarkCli.java \
    && jar cf runner.jar WatermarkCli.class

FROM php:8.2-apache

# Install SQLite and PDO extensions
RUN mkdir -p /usr/share/man/man1 && apt-get update && apt-get install -y \
    libsqlite3-dev libpng-dev libjpeg62-turbo-dev libwebp-dev \
    default-jre-headless libslf4j-java libcommons-lang3-java \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_sqlite pdo_mysql gd exif \
    && a2enmod rewrite headers expires deflate \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Kapak resmi ve toplu kitap (kitApp) yüklemeleri için PHP boyut sınırları
RUN printf "upload_max_filesize=64M\npost_max_size=64M\nmemory_limit=256M\nmax_execution_time=180\n" > /usr/local/etc/php/conf.d/uploads.ini
# PHP hataları ziyaretçiye/uygulamaya gösterilmez, Render loglarına (stderr) yazılır (JSON yanıtlar bozulmasın)
RUN printf "display_errors=Off\ndisplay_startup_errors=Off\nlog_errors=On\nerror_log=/dev/stderr\nerror_reporting=E_ALL & ~E_DEPRECATED & ~E_STRICT\n" > /usr/local/etc/php/conf.d/errors.ini

# Fotoğraftan kitap tanıma (ISBN yardımcısı) için PHP paketleri: Anthropic SDK + Guzzle
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip git && rm -rf /var/lib/apt/lists/*

# Copy project files
COPY . /var/www/html/
WORKDIR /var/www/html
COPY --from=watermark-build /build/runner.jar /opt/watermark/runner.jar
COPY --from=watermark-build /build/cleaner.jar /opt/watermark/cleaner.jar
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# Render kalıcı diski (/var/data) çalışma anında bağlanır; Apache'nin (www-data) yazabilmesi için izinleri başlangıçta ver
CMD ["sh", "-c", "mkdir -p /var/data && chown -R www-data:www-data /var/data && exec apache2-foreground"]
