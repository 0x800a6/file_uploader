#!/bin/bash

# Ensure proper permissions for the files directory
if [ -d "/var/www/html/files" ]; then
    chown -R www-data:www-data /var/www/html/files
    chmod -R 775 /var/www/html/files
    echo "Set permissions for /var/www/html/files"
fi

# Start Apache
exec apache2-foreground
