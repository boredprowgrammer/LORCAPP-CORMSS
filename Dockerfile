# Use official PHP with Apache as base image
FROM php:8.1-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libssl-dev \
    default-mysql-client \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    mysqli \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip

# Enable Apache modules
RUN a2enmod rewrite headers ssl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy Apache configuration for Render
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf

# Copy application files
COPY . /var/www/html/

# Create necessary directories
RUN mkdir -p /var/www/html/logs \
    /var/www/html/lorcapp/logs \
    /var/www/html/uploads \
    /var/www/html/temp \
    /var/www/html/cache

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/logs \
    && chmod -R 775 /var/www/html/lorcapp/logs \
    && chmod -R 775 /var/www/html/uploads \
    && chmod -R 775 /var/www/html/temp \
    && chmod -R 775 /var/www/html/cache

# Render.com uses PORT environment variable
# Configure Apache to listen on the PORT provided by Render
RUN echo "Listen \${PORT:-80}" > /etc/apache2/ports.conf

# Expose port (Render will map this dynamically)
EXPOSE ${PORT:-80}

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=40s --retries=3 \
    CMD curl -f http://localhost:${PORT:-80}/ || exit 1

# Start Apache with dynamic port from Render
CMD sed -i "s/80/${PORT:-80}/g" /etc/apache2/sites-available/000-default.conf && apache2-foreground
