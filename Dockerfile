# Use PHP 8.1 with Apache
FROM php:8.1-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    gnupg \
    ca-certificates \
    # Additional dependencies for PHP extensions
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    zlib1g-dev \
    libicu-dev \
    g++ \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-enable gd

# Install and enable core PHP extensions
RUN docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    pdo \
    bcmath \
    xml \
    ctype \
    fileinfo \
    mbstring \
    zip \
    && docker-php-ext-enable \
    pdo_mysql \
    pdo \
    bcmath \
    xml \
    ctype \
    fileinfo \
    mbstring \
    zip

# Verify GD installation
RUN php -r 'var_dump(gd_info());'

# Install Node.js and npm
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash - \
    && apt-get update && apt-get install -y nodejs \
    && npm install -g npm@latest

# Configure PHP
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini && \
    echo "max_execution_time = 3000" >> /usr/local/etc/php/conf.d/docker-php-custom.ini \
    && echo "memory_limit = 500M" >> /usr/local/etc/php/conf.d/docker-php-custom.ini \
    && echo "post_max_size = 200M" >> /usr/local/etc/php/conf.d/docker-php-custom.ini \
    && echo "upload_max_filesize = 200M" >> /usr/local/etc/php/conf.d/docker-php-custom.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first to leverage Docker cache
COPY composer.json composer.lock ./

# Install Composer dependencies
RUN composer install --no-scripts --no-autoloader --ignore-platform-reqs

# Copy package.json and package-lock.json
COPY package*.json ./
RUN npm install -g pnpm
RUN pnpm install

# Copy the rest of the application
COPY . .

# Generate optimized autoloader
# RUN composer dump-autoload --optimize

# Build frontend assets
# RUN npm run build

RUN chown -R www-data:www-data /var/www/html \
    && chown -R root:root /var/www/html/node_modules \
    && find /var/www/html -type f -not -path "/var/www/html/node_modules/*" -exec chmod 644 {} \; \
    && find /var/www/html -type d -not -path "/var/www/html/node_modules/*" -exec chmod 755 {} \; \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache


# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Apache configuration for Laravel
RUN echo '<Directory /var/www/html/public>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/laravel.conf \
    && a2enconf laravel

# Update Apache configuration to point to public directory
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]