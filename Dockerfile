# Use official PHP with Apache
FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install zip

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy web files (excluding files directory)
COPY web/ /var/www/html/
RUN rm -rf /var/www/html/files

# Create a mount point for the host files directory
RUN mkdir -p /var/www/html/files \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Create a default config if none exists
RUN if [ ! -f /var/www/html/config.php ]; then \
    echo '<?php' > /var/www/html/config.php && \
    echo '$config = [' >> /var/www/html/config.php && \
    echo '    "SEC_KEY" => "default_key_change_me",' >> /var/www/html/config.php && \
    echo '    "max_file_size" => 100 * 1024 * 1024,' >> /var/www/html/config.php && \
    echo '    "upload_dir" => __DIR__ . "/files"' >> /var/www/html/config.php && \
    echo '];' >> /var/www/html/config.php && \
    echo 'return $config;' >> /var/www/html/config.php; \
    fi

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Start Apache
CMD ["apache2-foreground"]
