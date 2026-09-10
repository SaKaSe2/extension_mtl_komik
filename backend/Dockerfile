FROM php:8.2-apache

ENV DEBIAN_FRONTEND=noninteractive

# Install dependencies sistem yang diperlukan
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libpq-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    fonts-dejavu-core \
    fonts-liberation \
    tesseract-ocr \
    tesseract-ocr-ind \
    tesseract-ocr-eng \
    tesseract-ocr-jpn \
    tesseract-ocr-kor \
    tesseract-ocr-chi-sim \
    tesseract-ocr-chi-tra

# Install Node.js (untuk keperluan build vite / npm)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Bersihkan cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Ekstensi PHP yang dibutuhkan Laravel dan package-nya
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd

# Aktifkan modul rewrite apache agar route Laravel berfungsi
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set direktori kerja (Working/Root Directory)
WORKDIR /var/www/html

# Copy semua file project ke dalam container
COPY . /var/www/html

# Set Permission agar apache bisa menulis log/cache/upload
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# Atur DocumentRoot Apache ke folder /public dan izinkan .htaccess
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Ubah port Apache dari 80 ke 8080 (standar cloud platform seperti Zeabur)
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf && \
    sed -i 's/*:80/*:8080/' /etc/apache2/sites-available/000-default.conf

# Beritahu bahwa kita bisa skip input saat composer jalan
ENV COMPOSER_ALLOW_SUPERUSER=1

# Install dependency PHP bawaan composer (Tanpa dev)
RUN composer install --optimize-autoloader --no-dev

# Install module NPM dan Build Vitenya
RUN npm install
RUN npm run build

# Ganti ownership sekali lagi di storage dan cache jaga-jaga sesudah build
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Copy dan beri izin eksekusi pada startup script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Buka Port 8080 yang akan digunakan Zeabur untuk mendeteksi HTTP webnya
EXPOSE 8080

# Mulai server menggunakan startup script (Apache + Queue Worker)
CMD ["docker-entrypoint.sh"]
