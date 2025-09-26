# File Uploader

<div align="center">

![Version](https://img.shields.io/badge/version-1.2.0-blue.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4.svg)
![Docker](https://img.shields.io/badge/Docker-Ready-2496ED.svg)

**A secure, feature-rich file hosting solution with both web interface and CLI client**

[Features](#features) • [Quick Start](#quick-start) • [Documentation](#documentation) • [Examples](#examples) • [Contributing](#contributing)

</div>

---

## Table of Contents

- [Features](#features)
- [Quick Start](#quick-start)
- [Documentation](#documentation)
- [Examples](#examples)
- [Configuration](#configuration)
- [Security](#security)
- [Docker](#docker)
- [Development](#development)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## Features

### **Modern Web Interface**

- **Retro-styled UI** with responsive design
- **Drag & drop** file uploads with progress tracking
- **Multiple view modes**: Table, Grid, and List views
- **Advanced search** and filtering capabilities
- **Real-time file preview** for images, videos, PDFs, and code files
- **Bulk operations** for downloading and managing multiple files
- **Auto-refresh** functionality for live updates

### **Powerful CLI Client**

- **Cross-platform** command-line interface
- **Parallel uploads** with configurable concurrency
- **Recursive directory** uploads
- **Subdirectory organization** support
- **Progress tracking** and detailed statistics
- **Comprehensive error handling** and logging
- **Config file support** for easy setup

### **Enterprise-Grade Security**

- **Secret key authentication** for all uploads
- **Path traversal protection** to prevent unauthorized access
- **File type validation** with MIME type checking
- **Content scanning** for malicious files
- **SHA-256 hash verification** for file integrity
- **Duplicate detection** with hash comparison
- **Input sanitization** and XSS protection

### **Performance & Reliability**

- **Configurable file size limits** (default: 100MB)
- **Concurrent upload processing** for better performance
- **Comprehensive logging** for monitoring and debugging
- **Health checks** and status monitoring
- **Error recovery** and retry mechanisms
- **Memory-efficient** file handling

---

## Quick Start

### **Docker Setup (Recommended)**

Get up and running in under 2 minutes:

```bash
# 1. Clone the repository
git clone https://github.com/0x800a6/file_uploader.git
cd file_uploader

# 2. Run the automated setup
./docker-setup.sh

# 3. Start the server
docker-compose up -d

# 4. Access the web interface
open http://localhost:9000
```

**That's it!** Your file uploader is now running.

### **Manual Setup**

For those who prefer manual installation:

```bash
# 1. Copy web files to your server
cp -r web/ /var/www/html/file-uploader/

# 2. Run setup script
cd /var/www/html/file-uploader/
./setup.sh

# 3. Set permissions
chmod 755 files/
chown -R www-data:www-data files/
```

### **CLI Client Setup**

Build the command-line client:

```bash
# Install dependencies (Ubuntu/Debian)
sudo apt install build-essential libcurl4-openssl-dev

# Build with your secret key
cd cli/
make SEC_KEY='your_secret_key' API_URL='http://yourserver.com/upload.php'
```

---

## Documentation

### **Project Structure**

```
file_uploader/
├── cli/                      # Command-line client
│   ├── file_uploader.sh      # Main CLI script
│   └── Makefile              # Build configuration
├── web/                      # Web server files
│   ├── index.php             # File browser interface
│   ├── upload.php            # Upload endpoint
│   ├── download.php          # Download handler
│   ├── delete.php            # File deletion handler
│   ├── config.php            # Configuration file
│   ├── setup.sh              # Server setup script
│   └── .htaccess             # Apache configuration
├── files/                    # Upload directory (auto-created)
├── Dockerfile                # Docker image configuration
├── docker-compose.yml        # Docker Compose configuration
├── docker-setup.sh           # Automated Docker setup
└── README.md                 # This file
```

### **Web Interface Features**

#### **File Management**

- **Browse files** with intuitive navigation
- **Upload files** via drag & drop or file picker
- **Download files** individually or in bulk
- **Delete files** with confirmation prompts
- **Create subdirectories** for organization

#### **Advanced Features**

- **Search functionality** with real-time filtering
- **Sort by** name, size, date, or type
- **Multiple view modes** for different preferences
- **Pagination** for large file collections
- **File preview** for supported formats
- **SHA-256 verification** for file integrity

#### **Security Features**

- **Secret key authentication** for all operations
- **File type validation** with comprehensive checks
- **Content scanning** for potential threats
- **Path traversal protection** for secure access
- **Input sanitization** to prevent injection attacks

### **CLI Client Usage**

#### **Basic Commands**

```bash
# Upload a single file
./file_uploader document.pdf

# Upload to a subdirectory
./file_uploader document.pdf "documents/important"

# Upload an entire directory
./file_uploader /path/to/project

# Verbose output with progress
./file_uploader -v large_file.zip

# Custom server URL
./file_uploader -u https://myserver.com/upload.php file.txt

# Parallel uploads (faster for multiple files)
./file_uploader -j 8 /path/to/many/files
```

#### **Advanced Options**

```bash
# Show help
./file_uploader --help

# Show version
./file_uploader --version

# Configure concurrency
./file_uploader -j 4 /path/to/files  # 4 parallel uploads

# Use config file
echo "API_URL=http://myserver.com/upload.php" > ~/.config/uploader.txt
echo "SEC_KEY=my_secret_key" >> ~/.config/uploader.txt
./file_uploader file.txt  # Uses config automatically
```

---

## Examples

### **Uploading Files**

#### **Web Interface**

1. Open your browser to the file uploader URL
2. Click the **[UPLOAD]** button
3. Enter your security key
4. Drag & drop files or click to browse
5. Optionally specify a subdirectory
6. Click **[START UPLOAD]**

#### **CLI Examples**

```bash
# Upload a single file
./file_uploader resume.pdf

# Upload multiple files to organized folders
./file_uploader project.zip "backups/$(date +%Y-%m-%d)"
./file_uploader photos/ "media/vacation-2024"

# Upload with progress tracking
./file_uploader -v large_dataset.tar.gz

# Batch upload with parallel processing
./file_uploader -j 6 /home/user/documents/
```

### **Downloading Files**

#### **Web Interface**

- **Single file**: Click the file name or **[DL]** button
- **Multiple files**: Select checkboxes and click **[DOWNLOAD]**
- **All files**: Click **[DOWNLOAD ALL]**
- **Direct link**: Right-click and copy link

#### **CLI Examples**

```bash
# Download via curl (if you have the direct URL)
curl -O "http://yourserver.com/files/document.pdf"

# Download with authentication
curl -H "Authorization: Bearer your_key" \
     -O "http://yourserver.com/files/document.pdf"
```

### **Organization Examples**

```bash
# Organize by project
./file_uploader project1/ "projects/2024/project1"
./file_uploader project2/ "projects/2024/project2"

# Organize by date
./file_uploader backup.tar.gz "backups/$(date +%Y-%m-%d)"

# Organize by type
./file_uploader documents/ "files/documents"
./file_uploader images/ "files/images"
./file_uploader code/ "files/source-code"
```

---

## Configuration

### **Server Configuration**

Edit `web/config.php` to customize your setup:

```php
<?php
$config = [
    'SEC_KEY' => 'your_secure_secret_key_here',
    'max_file_size' => 100 * 1024 * 1024,  // 100MB
    'upload_dir' => __DIR__ . '/files',
    'allowed_extensions' => [
        'txt', 'md', 'pdf', 'doc', 'docx',
        'jpg', 'jpeg', 'png', 'gif', 'svg',
        'mp3', 'wav', 'mp4', 'avi', 'mkv',
        'zip', 'rar', '7z', 'tar', 'gz',
        'js', 'css', 'html', 'php', 'py'
    ]
];
return $config;
```

### **CLI Configuration**

#### **Environment Variables**

```bash
export UPLOADER_API_URL="http://yourserver.com/upload.php"
export UPLOADER_SEC_KEY="your_secret_key"
```

#### **Config File** (`~/.config/uploader.txt`)

```
API_URL=http://yourserver.com/upload.php
SEC_KEY=your_secret_key
MAX_CONCURRENT=4
VERBOSE=false
```

#### **Build-time Configuration**

```bash
# Build with embedded configuration
make SEC_KEY='my_key' API_URL='http://server.com/upload.php'
```

### **Docker Configuration**

#### **Environment Variables**

```bash
# .env file
SEC_KEY=your_secure_secret_key
MAX_FILE_SIZE=104857600
UPLOAD_DIR=/var/www/html/files
```

#### **Docker Compose Customization**

```yaml
version: "3.8"
services:
  file-uploader:
    build: .
    ports:
      - "8080:80" # Custom port
    volumes:
      - ./files:/var/www/html/files
      - ./config:/var/www/html/config
    environment:
      - SEC_KEY=${SEC_KEY}
      - MAX_FILE_SIZE=200MB
```

---

## Security

### **Authentication**

- **Secret key required** for all upload operations
- **Key validation** on both client and server
- **Secure key storage** recommendations

### **File Security**

- **MIME type validation** to prevent executable uploads
- **File extension checking** against allowed types
- **Content scanning** for malicious patterns
- **SHA-256 verification** for file integrity
- **Path traversal protection** to prevent directory escapes

### **Server Security**

- **Input sanitization** for all user inputs
- **XSS protection** in web interface
- **CSRF protection** for form submissions
- **Rate limiting** to prevent abuse
- **Comprehensive logging** for audit trails

### **Security Best Practices**

1. **Use strong secret keys** (32+ characters, random)
2. **Regularly rotate** your secret keys
3. **Monitor logs** for suspicious activity
4. **Keep software updated** to latest versions
5. **Use HTTPS** in production environments
6. **Restrict file types** to only what you need
7. **Set appropriate file size limits**
8. **Regular backups** of your configuration

---

## Docker

### **Quick Docker Setup**

```bash
# Automated setup (recommended)
./docker-setup.sh

# Manual setup
docker-compose up -d
```

### **Docker Commands**

```bash
# Start the server
docker-compose up -d

# Stop the server
docker-compose down

# View logs
docker-compose logs -f

# Restart the server
docker-compose restart

# Update and rebuild
docker-compose down
docker-compose build --no-cache
docker-compose up -d

# Access container shell
docker-compose exec file-uploader bash
```

### **Docker Health Monitoring**

```bash
# Check container status
docker-compose ps

# View health check logs
docker inspect file-uploader-web | grep -A 10 Health

# Monitor resource usage
docker stats file-uploader-web
```

### **Docker Updates**

```bash
# Pull latest changes
git pull origin main

# Rebuild and restart
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

---

## Development

### **Building from Source**

#### **CLI Client**

```bash
cd cli/

# Check dependencies
make check-deps

# Build release version
make SEC_KEY='your_key' API_URL='http://server.com/upload.php'

# Build debug version
make debug SEC_KEY='your_key'

# Run tests
make test

# Install system-wide
sudo make install
```

#### **Web Interface**

```bash
# No build required - PHP runs directly
# Just ensure proper permissions
chmod 755 web/
chmod 644 web/*.php
chmod 755 web/files/
```

### **Testing**

```bash
# Test CLI client
cd cli/
make test

# Test web interface
curl -f http://localhost:9000/

# Test upload endpoint
curl -X POST -F "file=@test.txt" -F "key=your_key" \
     http://localhost:9000/upload.php
```

### **Development Features**

- **Comprehensive logging** for debugging
- **Verbose output** options
- **Error handling** with detailed messages
- **Progress tracking** for long operations
- **Configuration validation** on startup

### **Debugging**

#### **CLI Debugging**

```bash
# Enable verbose output
./file_uploader -v file.txt

# Check configuration
./file_uploader --help

# Test connection
curl -v http://yourserver.com/upload.php
```

#### **Web Debugging**

```bash
# Check PHP logs
tail -f /var/log/apache2/error.log

# Check application logs
tail -f web/logs/upload.log
tail -f web/logs/delete.log

# Test upload endpoint
curl -X POST -F "file=@test.txt" -F "key=test" \
     http://localhost/upload.php
```

---

## Troubleshooting

### **Docker Issues**

#### **Container Won't Start**

```bash
# Check Docker status
docker --version
docker-compose --version

# Check port availability
netstat -tulpn | grep 9000

# View container logs
docker-compose logs -f

# Check container status
docker-compose ps
```

#### **Permission Issues**

```bash
# Fix file permissions
sudo chown -R $USER:$USER files/
chmod 755 files/

# Check Docker volume mounts
docker-compose config
```

#### **Secret Key Issues**

```bash
# Verify environment variables
docker-compose exec file-uploader env | grep SEC_KEY

# Check config file
docker-compose exec file-uploader cat /var/www/html/config.php
```

### **Manual Setup Issues**

#### **CLI Build Failures**

```bash
# Install missing dependencies
sudo apt update
sudo apt install build-essential libcurl4-openssl-dev

# Check GCC version
gcc --version

# Clean and rebuild
make clean
make SEC_KEY='your_key'
```

#### **Upload Failures**

```bash
# Check server connectivity
curl -v http://yourserver.com/upload.php

# Verify secret key
grep SEC_KEY web/config.php

# Check file permissions
ls -la web/files/
```

#### **Web Interface Issues**

```bash
# Check PHP version
php --version

# Verify Apache/Nginx configuration
apache2ctl configtest

# Check error logs
tail -f /var/log/apache2/error.log
```

### **Common Solutions**

#### **401 Unauthorized**

- Verify secret key matches between client and server
- Check that key was properly set during compilation
- Ensure no extra spaces or characters in the key

#### **File Upload Fails**

- Check file size limits (default: 100MB)
- Verify file type is allowed
- Ensure sufficient disk space
- Check web server write permissions

#### **Permission Denied**

- Ensure web server has write access to files directory
- Check file permissions: `chmod 755 files/`
- Verify ownership: `chown -R www-data:www-data files/`

#### **Connection Refused**

- Verify server is running: `docker-compose ps`
- Check port configuration in docker-compose.yml
- Ensure firewall allows the port
- Test local connectivity: `curl http://localhost:9000`

### **Getting Help**

1. **Check the logs** first - they often contain the solution
2. **Verify your configuration** matches the examples
3. **Test with a simple file** to isolate the issue
4. **Check system requirements** and dependencies
5. **Search existing issues** on GitHub
6. **Create a new issue** with detailed information

---

## License

This project is open source and available under the [MIT License](LICENSE).

---

## Author

Created with ❤️ by [Lexi](https://www.lrr.sh)

- **Website**: [lrr.sh](https://www.lrr.sh)
- **GitHub**: [@0x800a6](https://github.com/0x800a6)
- **Contact**: Available through GitHub

---

<div align="center">

**Star this repository if you find it useful!**

[Report Bug](https://github.com/0x800a6/file_uploader/issues) • [Request Feature](https://github.com/0x800a6/file_uploader/issues) • [Contribute](https://github.com/0x800a6/file_uploader/pulls)

</div>
