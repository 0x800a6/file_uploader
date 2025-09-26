#!/bin/bash

set -euo pipefail

# Configuration
SERVICE_NAME="file-uploader"
WORKING_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_NAME="$(basename "$0")"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1" >&2
}

# Help function
show_help() {
    cat << EOF
Usage: $SCRIPT_NAME [OPTIONS]

Create a systemd service for the file-uploader Docker Compose application.

OPTIONS:
    -h, --help          Show this help message
    -r, --remove        Remove the existing service
    -s, --status        Show service status
    -e, --enable        Enable and start the service
    -d, --disable       Stop and disable the service

EXAMPLES:
    $SCRIPT_NAME                 # Create the service
    $SCRIPT_NAME --enable        # Create, enable, and start the service
    $SCRIPT_NAME --remove        # Remove the service
    $SCRIPT_NAME --status        # Check service status

EOF
}

# Check if running as root or with sudo
check_permissions() {
    if [[ $EUID -ne 0 ]]; then
        log_error "This script must be run as root or with sudo"
        log_info "Usage: sudo $SCRIPT_NAME"
        exit 1
    fi
}

# Check if required tools are available
check_dependencies() {
    local missing_deps=()
    
    if ! command -v systemctl &> /dev/null; then
        missing_deps+=("systemctl")
    fi
    
    if ! command -v docker &> /dev/null; then
        missing_deps+=("docker")
    fi
    
    if [[ ${#missing_deps[@]} -gt 0 ]]; then
        log_error "Missing required dependencies: ${missing_deps[*]}"
        log_info "Please install the missing dependencies and try again"
        exit 1
    fi
}

# Find docker-compose command
find_docker_compose_path() {
    local path
    
    # Try docker-compose first (standalone)
    if command -v docker-compose &> /dev/null; then
        path=$(which docker-compose)
    # Try docker compose (plugin)
    elif docker compose version &> /dev/null; then
        cmd=$(which docker)
        path="$cmd compose"
    else
        log_error "Neither 'docker-compose' nor 'docker compose' found"
        log_info "Please install Docker Compose and try again"
        exit 1
    fi
    
    echo "$path"
}

# Check if docker-compose.yml exists
check_docker_compose_file() {
    if [[ ! -f "$WORKING_DIR/docker-compose.yml" ]]; then
        log_error "docker-compose.yml not found in $WORKING_DIR"
        log_info "Please run this script from the project root directory"
        exit 1
    fi
}

# Check if service exists
service_exists() {
    systemctl list-unit-files | grep -q "^$SERVICE_NAME.service"
}

# Check if service is active
service_is_active() {
    systemctl is-active --quiet "$SERVICE_NAME.service" 2>/dev/null
}

# Create the systemd service
create_service() {
    local docker_compose_cmd
    docker_compose_cmd=$(find_docker_compose_path)
    
    log_info "Creating systemd service for $SERVICE_NAME"
    log_info "Working directory: $WORKING_DIR"
    log_info "Docker Compose command: $docker_compose_cmd"
    
    # Create service file content
    cat > "/etc/systemd/system/$SERVICE_NAME.service" << EOF
[Unit]
Description=$SERVICE_NAME (Docker Compose)
Requires=docker.service
After=docker.service

[Service]
Type=exec
WorkingDirectory=$WORKING_DIR
ExecStart=/usr/bin/docker compose up
ExecStop=/usr/bin/docker compose down
Restart=always
TimeoutStartSec=0

[Install]
WantedBy=multi-user.target
EOF
    
    log_success "Service file created at /etc/systemd/system/$SERVICE_NAME.service"
}

# Enable and start the service
enable_service() {
    log_info "Reloading systemd daemon..."
    systemctl daemon-reload
    
    log_info "Enabling $SERVICE_NAME service..."
    systemctl enable "$SERVICE_NAME.service"
    
    log_info "Starting $SERVICE_NAME service..."
    if systemctl start "$SERVICE_NAME.service"; then
        log_success "Service started successfully"
        show_service_status
    else
        log_error "Failed to start service"
        log_info "Check service status with: systemctl status $SERVICE_NAME"
        exit 1
    fi
}

# Remove the service
remove_service() {
    if ! service_exists; then
        log_warning "Service $SERVICE_NAME does not exist"
        return 0
    fi
    
    log_info "Stopping $SERVICE_NAME service..."
    systemctl stop "$SERVICE_NAME.service" 2>/dev/null || true
    
    log_info "Disabling $SERVICE_NAME service..."
    systemctl disable "$SERVICE_NAME.service" 2>/dev/null || true
    
    log_info "Removing service file..."
    rm -f "/etc/systemd/system/$SERVICE_NAME.service"
    
    log_info "Reloading systemd daemon..."
    systemctl daemon-reload
    
    log_success "Service removed successfully"
}

# Show service status
show_service_status() {
    log_info "Service status:"
    systemctl status "$SERVICE_NAME.service" --no-pager -l || true
    
    echo
    log_info "Service logs (last 20 lines):"
    journalctl -u "$SERVICE_NAME.service" --no-pager -n 20 || true
}

# Disable the service
disable_service() {
    if ! service_exists; then
        log_warning "Service $SERVICE_NAME does not exist"
        return 0
    fi
    
    log_info "Stopping $SERVICE_NAME service..."
    systemctl stop "$SERVICE_NAME.service"
    
    log_info "Disabling $SERVICE_NAME service..."
    systemctl disable "$SERVICE_NAME.service"
    
    log_success "Service disabled successfully"
}

# Main script logic
main() {
    local action="create"
    local auto_enable=false
    
    # Parse command line arguments
    while [[ $# -gt 0 ]]; do
        case $1 in
            -h|--help)
                show_help
                exit 0
                ;;
            -r|--remove)
                action="remove"
                shift
                ;;
            -s|--status)
                action="status"
                shift
                ;;
            -e|--enable)
                action="create"
                auto_enable=true
                shift
                ;;
            -d|--disable)
                action="disable"
                shift
                ;;
            *)
                log_error "Unknown option: $1"
                show_help
                exit 1
                ;;
        esac
    done
    
    # Check permissions and dependencies
    check_permissions
    check_dependencies
    
    # Execute the requested action
    case $action in
        "create")
            if service_exists; then
                log_warning "Service $SERVICE_NAME already exists"
                if service_is_active; then
                    log_info "Service is currently active"
                else
                    log_info "Service exists but is not active"
                fi
                
                if [[ "$auto_enable" == true ]]; then
                    log_info "Enabling and starting existing service..."
                    enable_service
                else
                    log_info "Use --enable to start the service or --remove to recreate it"
                fi
            else
                check_docker_compose_file
                create_service
                
                if [[ "$auto_enable" == true ]]; then
                    enable_service
                else
                    log_info "Service created successfully"
                    log_info "To enable and start the service, run: sudo $SCRIPT_NAME --enable"
                    log_info "To check service status, run: sudo $SCRIPT_NAME --status"
                fi
            fi
            ;;
        "remove")
            remove_service
            ;;
        "status")
            if service_exists; then
                show_service_status
            else
                log_warning "Service $SERVICE_NAME does not exist"
            fi
            ;;
        "disable")
            disable_service
            ;;
    esac
}

# Run main function with all arguments
main "$@"