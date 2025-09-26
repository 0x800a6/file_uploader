#!/bin/bash

# File Uploader Docker Setup Script
echo "=== File Uploader Docker Setup ==="

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "Error: Docker is not installed. Please install Docker first."
    exit 1
fi

# Check if Docker Compose is installed
if ! command -v docker-compose &> /dev/null && ! docker compose version &> /dev/null; then
    echo "Error: Docker Compose is not installed. Please install Docker Compose first."
    exit 1
fi

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Prompt for secret key
read -p "Enter a secret key for file uploads: " -s SECRET_KEY
echo ""

if [ -z "$SECRET_KEY" ]; then
    echo "Error: Secret key cannot be empty."
    exit 1
fi

# Create .env file
cat > "$SCRIPT_DIR/.env" << EOF
SEC_KEY=$SECRET_KEY
EOF

echo "✓ Environment file created with secret key"

# Create files directory if it doesn't exist (in project root, not web/)
FILES_DIR="$SCRIPT_DIR/files"
if [ ! -d "$FILES_DIR" ]; then
    mkdir -p "$FILES_DIR"
    echo "✓ Created upload directory: $FILES_DIR"
fi

# Create config.php if it doesn't exist
CONFIG_FILE="$SCRIPT_DIR/web/config.php"
if [ ! -f "$CONFIG_FILE" ]; then
    cat > "$CONFIG_FILE" << EOF
<?php
// Configuration file for file uploader
\$config = [
    'SEC_KEY' => '$SECRET_KEY',
    'max_file_size' => 100 * 1024 * 1024,  // 100MB
    'upload_dir' => __DIR__ . '/files'
];

return \$config;
EOF
    echo "✓ Created configuration file: $CONFIG_FILE"
fi

# Set appropriate permissions
chmod 755 "$FILES_DIR"
chmod 644 "$CONFIG_FILE"

echo ""
echo "Setup complete! Your Docker environment is now configured."
echo ""
echo "Next steps:"
echo "1. Start the server: docker-compose up -d"
echo "2. Access the web interface: http://localhost:8080"
echo "3. Build the CLI client with: make clean && SEC_KEY='$SECRET_KEY' make"
echo ""
echo "Useful commands:"
echo "  docker-compose up -d          # Start the server"
echo "  docker-compose down           # Stop the server"
echo "  docker-compose logs -f        # View logs"
echo "  docker-compose restart        # Restart the server"
echo ""
echo "Note: Keep your secret key safe and don't share it!"
