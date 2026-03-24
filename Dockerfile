FROM php:8.3-apache

# Enable Apache mod_rewrite (required for .htaccess routing)
RUN a2enmod rewrite

# Install system deps: zip/unzip for Composer, git as fallback download method
RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip \
    git \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PDO MySQL + zip extensions
RUN docker-php-ext-install pdo pdo_mysql zip

# Set DocumentRoot to /public so Apache serves from there
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides (needed for RewriteRule in public/.htaccess)
RUN sed -ri -e 's/AllowOverride None/AllowOverride All/g' \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy project files
COPY . .

# Install PHP dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Ensure uploads directory is writable
RUN chown -R www-data:www-data /var/www/html/storage
