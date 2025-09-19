#!/bin/bash

# File Uploader Server Setup Script
echo "=== File Uploader Server Setup ==="

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="$SCRIPT_DIR/config.php"

# Prompt for secret key
read -p "Enter a secret key for file uploads (this must match the key used when building the CLI client): " -s SECRET_KEY
echo ""

if [ -z "$SECRET_KEY" ]; then
    echo "Error: Secret key cannot be empty."
    exit 1
fi

# Create or update config file
cat > "$CONFIG_FILE" << EOF
<?php
// Configuration file for file uploader
// This key must match the one used when building the CLI client
\$config = [
    'SEC_KEY' => '$SECRET_KEY',
    'max_file_size' => 100 * 1024 * 1024,  // 100MB
    'upload_dir' => __DIR__ . '/files'
];

return \$config;
EOF

echo "✓ Configuration saved to $CONFIG_FILE"

# Create files directory if it doesn't exist
FILES_DIR="$SCRIPT_DIR/files"
if [ ! -d "$FILES_DIR" ]; then
    mkdir -p "$FILES_DIR"
    echo "✓ Created upload directory: $FILES_DIR"
fi

# Set appropriate permissions
chmod 755 "$FILES_DIR"
chmod 644 "$CONFIG_FILE"

echo ""
echo "Setup complete! Your server is now configured with the secret key."
echo ""
echo "Next steps:"
echo "1. Build the CLI client with: make clean && SEC_KEY='$SECRET_KEY' make"
echo "2. Or when prompted during build, enter the same secret key: $SECRET_KEY"
echo ""
echo "Note: Keep this secret key safe and don't share it!"