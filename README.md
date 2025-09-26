# File Uploader

A simple yet secure file hosting solution with both a web interface and CLI client for easy file uploads and sharing.

## Features

- **Web Interface**: Browse and download files through a retro-styled web interface
- **CLI Client**: Upload files and directories from the command line
- **Security**: Secret key authentication to prevent unauthorized uploads
- **Directory Support**: Upload entire directories recursively
- **Subdirectory Organization**: Upload files to specific subdirectories on the server
- **File Size Limits**: Configurable maximum file size (default: 100MB)
- **Path Traversal Protection**: Secure handling of file paths to prevent security vulnerabilities

## Project Structure

```
file_uploader/
├── cli/                    # Command-line client
│   ├── main.c             # Main CLI source code
│   └── Makefile           # Build configuration
├── web/                   # Web server files
│   ├── index.php          # File browser interface
│   ├── upload.php         # Upload endpoint
│   ├── download.php       # Download handler
│   ├── config.php         # Configuration file
│   ├── setup.sh           # Server setup script
│   └── .htaccess          # Apache configuration
├── files/                 # Host directory for uploaded files (created by Docker setup)
├── Dockerfile             # Docker image configuration
├── docker-compose.yml     # Docker Compose configuration
├── docker-setup.sh        # Docker setup script
├── .dockerignore          # Docker build context exclusions
└── env.example            # Environment variables template
```

## Quick Start

### Option 1: Docker Setup (Recommended)

The easiest way to get started is using Docker:

1. **Prerequisites**: Install [Docker](https://docs.docker.com/get-docker/) and [Docker Compose](https://docs.docker.com/compose/install/)

2. **Quick Setup**: Run the automated setup script:

   ```bash
   ./docker-setup.sh
   ```

   This will prompt you for a secret key and create all necessary configuration files.

3. **Start the Server**:

   ```bash
   docker-compose up -d
   ```

4. **Access the Web Interface**: Open http://localhost:8080 in your browser

5. **Build CLI Client** (optional):
   ```bash
   cd cli/
   make clean && SEC_KEY='your_secret_key' make
   ```

**Docker Commands**:

- `docker-compose up -d` - Start the server in background
- `docker-compose down` - Stop the server
- `docker-compose logs -f` - View server logs
- `docker-compose restart` - Restart the server

### Option 2: Manual Server Setup

1. Copy the `web/` directory to your web server
2. Run the setup script to configure your secret key:
   ```bash
   cd web/
   ./setup.sh
   ```
3. Enter a secure secret key when prompted
4. Ensure the web server has write permissions to the `files/` directory

### CLI Client Setup

1. Install dependencies (Ubuntu/Debian):

   ```bash
   sudo apt install build-essential libcurl4-openssl-dev
   ```

2. Build the CLI client:

   ```bash
   cd cli/
   make clean && SEC_KEY='your_secret_key' API_URL='http://yourserver.com/upload.php' make
   ```

   Or let the Makefile prompt you for the key (uses default API URL):

   ```bash
   make
   ```

   Or build with custom API URL and interactive key prompt:

   ```bash
   API_URL='http://yourserver.com/upload.php' make
   ```

3. Optionally install system-wide:
   ```bash
   sudo make install
   ```

### 3. Usage

#### Web Interface

Navigate to your server URL to browse and download files through the web interface.

#### CLI Client

Upload a single file:

```bash
./file_uploader myfile.txt
```

Upload a file to a subdirectory:

```bash
./file_uploader myfile.txt "documents/important"
```

Upload an entire directory:

```bash
./file_uploader /path/to/directory
```

Verbose output:

```bash
./file_uploader -v myfile.txt
```

Custom server URL (runtime override):

```bash
./file_uploader -u http://myserver.com/upload.php myfile.txt
```

**Note**: If you built the CLI with a custom `API_URL`, that will be the default server. The `-u` flag overrides both the compiled default and the build-time `API_URL`.

## Configuration

### Server Configuration

Edit `web/config.php` to customize:

- `SEC_KEY`: Secret key for authentication (set via setup script)
- `max_file_size`: Maximum upload size in bytes (default: 100MB)
- `upload_dir`: Directory where files are stored

### CLI Configuration

The CLI client requires the secret key to be compiled in during build time. The default server URL can be set at build time using the `API_URL` parameter (defaults to `http://192.168.12.130/upload.php`) or changed at runtime using the `-u` flag.

Build-time configuration:

- `SEC_KEY`: Required authentication key
- `API_URL`: Default server endpoint (optional)

## Security Features

- **Authentication**: All uploads require a matching secret key
- **Path Traversal Protection**: Prevents unauthorized access to system files
- **File Size Limits**: Configurable upload size restrictions
- **Directory Restrictions**: Uploads are confined to the designated upload directory
- **Input Sanitization**: Proper handling of user input to prevent injection attacks

## Requirements

### Docker Setup

- Docker 20.10 or higher
- Docker Compose 2.0 or higher

### Manual Server Setup

- PHP 7.4 or higher
- Apache/Nginx web server
- Write permissions for the upload directory

### CLI Client

- GCC compiler
- libcurl development libraries
- Linux/Unix environment

## Development

### Building from Source

```bash
cd cli/
make check-deps  # Check for required dependencies
make debug       # Build with debug symbols
make test        # Run basic tests
```

Build with custom configuration:

```bash
make SEC_KEY=mykey API_URL=http://myserver.com/upload.php
```

### Makefile Targets

- `all`: Build the file_uploader (default)
- `debug`: Build with debug symbols
- `install`: Install to `/usr/local/bin` (requires sudo)
- `clean`: Remove build artifacts
- `check-deps`: Check for required dependencies
- `test`: Run basic functionality tests
- `help`: Show available targets and build variables

### Build-time Variables

- `SEC_KEY`: Server authentication key (required)
- `API_URL`: Default API endpoint (optional, defaults to `http://192.168.12.130/upload.php`)

## Troubleshooting

### Docker Issues

**Container won't start:**

- Check Docker is running: `docker --version`
- View container logs: `docker-compose logs -f`
- Ensure port 8080 is not in use: `netstat -tulpn | grep 8080`

**Permission denied errors:**

- Ensure the `files` directory exists and is writable
- Check Docker volume mounts: `docker-compose config`
- Verify directory permissions: `ls -la files/`

**Secret key issues:**

- Verify the secret key in `.env` file matches the one used for CLI builds
- Check the config.php file in the container: `docker-compose exec file-uploader cat /var/www/html/config.php`

### Manual Setup Issues

**CLI client build fails:**

- Ensure libcurl development libraries are installed
- Check that GCC and make are available

**Upload fails with 401 error:**

- Verify the secret key matches between server and client
- Check that the key was properly set during compilation

**Permission denied errors:**

- Ensure web server has write access to the `files/` directory
- Check file permissions: `chmod 755 files/`

**File not found errors:**

- Verify the server URL is correct
- Check that upload.php is accessible
- Ensure Apache mod_rewrite is enabled (if using .htaccess)

## License

This project is open source. Feel free to modify and distribute as needed.

## Author

Created by [Lexi](https://www.lrr.sh)
