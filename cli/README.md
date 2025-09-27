# Advanced File Uploader CLI

A comprehensive, feature-rich Python file uploader with interactive and command-line modes.

## Features

### 🚀 Core Features
- **Interactive and Command-line modes** - Use `-i` for interactive file browser
- **Real-time progress bars** - Visual feedback with upload statistics
- **Parallel uploads** - Configurable concurrency for faster transfers
- **Resume capability** - Automatically resume interrupted large file uploads

### 🔧 Advanced Configuration
- **Profile management** - Save and switch between different configurations
- **Environment variables** - Override settings via environment
- **Configuration files** - Persistent settings in `~/.config/file_uploader/`

### 🛡️ Security & Validation
- **File type validation** - MIME type and extension checking
- **Size limits** - Configurable file size restrictions
- **Pattern filtering** - Include/exclude files by patterns
- **Security key authentication** - Secure upload endpoints

### 📦 File Processing
- **Compression** - Automatic compression for eligible files
- **Encryption** - Optional file encryption before upload
- **Checksums** - SHA-256 verification for integrity

### 📊 Queue Management
- **Batch operations** - Queue files for later upload
- **Retry failed uploads** - Automatic retry with exponential backoff
- **Upload history** - Track completed and failed uploads

## Installation

No dependencies required! Uses only Python standard library.

```bash
# Make executable
chmod +x cli/file_uploader.py

# Or run directly
python cli/file_uploader.py --help
```

## Quick Start

### 1. Basic Upload
```bash
# Upload a single file
python cli/file_uploader.py --config-key "your_key" file.txt

# Upload a directory
python cli/file_uploader.py --config-key "your_key" -d /path/to/directory

# Upload with custom subdirectory
python cli/file_uploader.py --config-key "your_key" --subdir "uploads/images" file.jpg
```

### 2. Interactive Mode
```bash
python cli/file_uploader.py --config-key "your_key" -i
```

Interactive features:
- Browse directories with arrow keys
- Select multiple files with checkboxes
- Filter files by patterns
- Configure settings on-the-fly
- Manage upload queue

### 3. Advanced Options
```bash
# Parallel uploads with compression
python cli/file_uploader.py --config-key "your_key" --parallel 8 --compress file.txt

# Encrypt files before upload
python cli/file_uploader.py --config-key "your_key" --encrypt --encryption-key "secret" file.txt

# Filter files by pattern
python cli/file_uploader.py --config-key "your_key" --include "*.pdf,*.doc" --exclude "*.tmp" -d /docs

# Dry run to see what would be uploaded
python cli/file_uploader.py --config-key "your_key" --dry-run -d /path/to/files
```

## Configuration

### Configuration Files
The CLI reads configuration from:
1. `~/.config/file_uploader/config.ini` (main config)
2. `~/.config/file_uploader/profiles/{profile}.ini` (profile-specific)

### Environment Variables
```bash
export FILE_UPLOAD_URL="http://yourserver.com/upload.php"
export FILE_UPLOAD_KEY="your_security_key"
```

### Profile Management
```bash
# List available profiles
python cli/file_uploader.py --list-profiles

# Use a specific profile
python cli/file_uploader.py --profile production file.txt

# Save current settings as profile
python cli/file_uploader.py --save-profile staging --config-url "http://staging.com/upload.php" file.txt
```

## Examples

### Upload with Progress
```bash
python cli/file_uploader.py --config-key "your_key" -v file.txt
```

### Batch Upload with Queue
```bash
# Add files to queue (interactive mode)
python cli/file_uploader.py -i

# Or upload large directory in background
python cli/file_uploader.py --config-key "your_key" --parallel 4 -d /large/directory
```

### Resume Large File Upload
```bash
# Upload large file (automatically resumes if interrupted)
python cli/file_uploader.py --config-key "your_key" large_file.zip

# The CLI will automatically:
# - Save progress every chunk
# - Resume from last position if interrupted
# - Clean up resume state when complete
```

### Filter and Compress
```bash
# Upload only images, compress them, exclude thumbnails
python cli/file_uploader.py \
  --config-key "your_key" \
  --include "*.jpg,*.png,*.gif" \
  --exclude "*thumb*,*_small*" \
  --compress \
  --compression-level 9 \
  -d /photos
```

## Command Reference

### Basic Options
- `files` - Files or directories to upload
- `-i, --interactive` - Interactive mode
- `-d, --directory` - Upload directory contents
- `-r, --recursive` - Recursive directory upload

### Configuration
- `-p, --profile` - Configuration profile
- `--config-url` - Override upload URL
- `--config-key` - Override security key
- `--list-profiles` - List available profiles
- `--save-profile` - Save current settings as profile

### Upload Options
- `--subdir` - Target subdirectory on server
- `--parallel` - Number of parallel uploads (1-16)
- `--chunk-size` - Chunk size for uploads
- `--timeout` - Request timeout in seconds
- `--retry` - Number of retry attempts

### Processing
- `--compress` - Enable file compression
- `--encrypt` - Enable file encryption
- `--encryption-key` - Encryption key
- `--compression-level` - Compression level (1-9)

### Filtering
- `--include` - Include pattern (e.g., "*.txt,*.pdf")
- `--exclude` - Exclude pattern (e.g., "*.tmp,*.log")
- `--max-size` - Maximum file size in bytes
- `--max-depth` - Maximum directory depth

### Output
- `-v, --verbose` - Verbose output
- `--quiet` - Quiet mode (minimal output)
- `--log-file` - Log file path
- `--no-progress` - Disable progress bars
- `--dry-run` - Show what would be uploaded

## Configuration Examples

### Development Profile
```ini
[profile]
url = http://localhost/upload.php
key = dev_key_123
max_file_size = 52428800  # 50MB
max_concurrent = 2
log_level = DEBUG
```

### Production Profile
```ini
[profile]
url = https://files.example.com/upload.php
key = prod_key_secure
max_file_size = 104857600  # 100MB
max_concurrent = 8
verify_ssl = true
compression_enabled = true
encryption_enabled = true
```

## Troubleshooting

### Common Issues

1. **"Security key not configured"**
   - Set `--config-key` parameter
   - Or export `FILE_UPLOAD_KEY` environment variable
   - Or add `key = your_key` to config file

2. **"Permission denied"**
   - Check file/directory permissions
   - Ensure upload directory is writable
   - Run with appropriate user privileges

3. **"Connection timeout"**
   - Increase `--timeout` value
   - Check network connectivity
   - Verify upload URL is correct

4. **"File too large"**
   - Increase `max_file_size` in config
   - Use `--max-size` parameter
   - Enable compression to reduce size

### Debug Mode
```bash
python cli/file_uploader.py --config-key "your_key" -v --log-file upload.log file.txt
```

## Architecture

The CLI is built with a modular architecture:

- **ConfigManager** - Handles profiles and configuration
- **FileValidator** - Validates files and applies filters
- **UploadManager** - Manages uploads with resume capability
- **ResumeManager** - Handles resume state persistence
- **UploadQueue** - Manages batch operations
- **Logger** - Provides logging with different levels
- **ProgressBar** - Real-time progress visualization

## Contributing

This is a standalone Python script with no external dependencies. To extend:

1. Add new features to appropriate classes
2. Update configuration options in `DEFAULT_CONFIG`
3. Add command-line arguments in `main()`
4. Test with various file types and sizes

## License

Part of the file_uploader project.
