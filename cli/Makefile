# File Uploader Makefile
CC = gcc
CFLAGS = -Wall -Wextra -std=c99 -O2
LDFLAGS = -lcurl
TARGET = /tmp/file_uploader
SOURCE = main.c

# Default API URL (can be overridden with API_URL=your_url)
ifndef API_URL
API_URL = http://localhost/upload.php
endif

# Get secret key from user
ifndef SEC_KEY
SEC_KEY := $(shell read -p "Enter server secret key: " -s key; echo $$key)
endif

# Default target
all: get-key $(TARGET)

# Prompt for secret key
get-key:
ifndef SEC_KEY
	$(eval SEC_KEY := $(shell bash -c 'read -p "Enter server secret key: " -s key; echo $$key'))
endif

# Build the main executable
$(TARGET): $(SOURCE)
	$(CC) $(CFLAGS) -DSEC_KEY=\"$(SEC_KEY)\" -DDEFAULT_API_URL=\"$(API_URL)\" -o $(TARGET) $(SOURCE) $(LDFLAGS)

# Install target (optional)
install: $(TARGET)
	sudo cp $(TARGET) /usr/local/bin/file_uploader
	sudo chmod +x /usr/local/bin/file_uploader

# Clean build artifacts
clean:
	rm -f $(TARGET)

# Check for dependencies
check-deps:
	@echo "Checking for required dependencies..."
	@pkg-config --exists libcurl && echo "✓ libcurl found" || echo "✗ libcurl not found - install libcurl4-openssl-dev (Ubuntu/Debian) or libcurl-devel (RHEL/CentOS)"

# Debug build
debug: CFLAGS += -g -DDEBUG
debug: get-key $(TARGET)

# Run tests (if you add them later)
test: $(TARGET)
	@echo "Running basic tests..."
	./$(TARGET) --help
	./$(TARGET) --version

.PHONY: all clean install check-deps debug test get-key

# Help target
help:
	@echo "Available targets:"
	@echo "  all        - Build the file_uploader (default)"
	@echo "  debug      - Build with debug symbols"
	@echo "  install    - Install to /usr/local/bin (requires sudo)"
	@echo "  clean      - Remove build artifacts"
	@echo "  check-deps - Check for required dependencies"
	@echo "  test       - Run basic functionality tests"
	@echo "  help       - Show this help message"
	@echo ""
	@echo "Build-time variables:"
	@echo "  SEC_KEY    - Server authentication key (required)"
	@echo "  API_URL    - Default API endpoint (default: http://192.168.12.130/upload.php)"
	@echo ""
	@echo "Example usage:"
	@echo "  make SEC_KEY=mykey API_URL=http://myserver.com/upload.php"