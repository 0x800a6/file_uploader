#!/bin/bash

# File Uploader
# A secure, parallel file uploader with comprehensive error handling
# 
# Features:
# - Config file support (~/.config/uploader.txt)
# - Environment variable support
# - Parallel uploads with configurable concurrency
# - Comprehensive error handling and logging
# - File validation and security checks
# - Progress tracking and statistics

set -euo pipefail

# Version and configuration
readonly VERSION="1.2.0"
readonly SCRIPT_NAME="$(basename "$0")"
readonly MAX_FILE_SIZE=104857600  # 100MB in bytes
readonly MAX_CONCURRENT_UPLOADS=4
readonly UPLOAD_QUEUE_SIZE=100

# Global variables
API_URL=""
SEC_KEY=""
VERBOSE=0
CONCURRENT_UPLOADS=$MAX_CONCURRENT_UPLOADS
FILES_UPLOADED=0
FILES_FAILED=0
FILES_TOTAL=0
START_TIME=0

# Colors for output
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly BLUE='\033[0;34m'
readonly NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $*" >&2
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $*" >&2
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $*" >&2
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $*" >&2
}

log_verbose() {
    if [[ $VERBOSE -eq 1 ]]; then
        echo -e "${BLUE}[VERBOSE]${NC} $*" >&2
    fi
}

# Print usage information
print_usage() {
    cat << EOF
Usage: $SCRIPT_NAME [OPTIONS] <file_or_dir> [subdir]

Options:
  -h, --help          Show this help message
  -v, --verbose       Enable verbose output
  -u, --url URL       Set custom API URL
  -j, --jobs N        Number of concurrent uploads (1-$MAX_CONCURRENT_UPLOADS, default: $MAX_CONCURRENT_UPLOADS)
  --version           Show version information

Arguments:
  file_or_dir         File or directory to upload
  subdir              Optional subdirectory on server

Configuration:
  The script reads configuration from:
  1. Environment variables: FILE_UPLOAD_URL, FILE_UPLOAD_KEY
  2. Config file: ~/.config/uploader.txt (format: url=..., key=...)

Examples:
  $SCRIPT_NAME file.txt
  $SCRIPT_NAME file.txt my_folder
  $SCRIPT_NAME myDir/
  $SCRIPT_NAME myDir/ some/path
  $SCRIPT_NAME -j 8 -v /path/to/directory
  $SCRIPT_NAME -u http://myserver.com/upload.php file.txt

EOF
}

# Load configuration from file
load_config_file() {
    local config_file="$HOME/.config/uploader.txt"
    
    if [[ -f "$config_file" ]]; then
        log_verbose "Loading configuration from $config_file"
        
        # Read config file line by line
        while IFS='=' read -r key value; do
            # Skip comments and empty lines
            [[ -z "$key" || "$key" =~ ^[[:space:]]*# ]] && continue
            
            # Remove leading/trailing whitespace
            key=$(echo "$key" | xargs)
            value=$(echo "$value" | xargs)
            
            case "$key" in
                "url")
                    if [[ -z "$API_URL" ]]; then
                        API_URL="$value"
                        log_verbose "Set API URL from config: $API_URL"
                    fi
                    ;;
                "key")
                    if [[ -z "$SEC_KEY" ]]; then
                        SEC_KEY="$value"
                        log_verbose "Set SEC_KEY from config"
                    fi
                    ;;
                *)
                    log_warning "Unknown config key: $key"
                    ;;
            esac
        done < "$config_file"
    else
        log_verbose "No config file found at $config_file"
    fi
}

# Load configuration from environment variables
load_config_env() {
    if [[ -n "${FILE_UPLOAD_URL:-}" && -z "$API_URL" ]]; then
        API_URL="$FILE_UPLOAD_URL"
        log_verbose "Set API URL from environment: $API_URL"
    fi
    
    if [[ -n "${FILE_UPLOAD_KEY:-}" && -z "$SEC_KEY" ]]; then
        SEC_KEY="$FILE_UPLOAD_KEY"
        log_verbose "Set SEC_KEY from environment"
    fi
}

# Initialize configuration
init_config() {
    # Load config in order: file first, then environment (env overrides file)
    load_config_file
    load_config_env
    
    # Set defaults if not configured
    if [[ -z "$API_URL" ]]; then
        API_URL="http://localhost/upload.php"
        log_verbose "Using default API URL: $API_URL"
    fi
    
    # Check if SEC_KEY is set
    if [[ -z "$SEC_KEY" ]]; then
        log_error "Security key not configured. Set FILE_UPLOAD_KEY environment variable or add 'key=...' to ~/.config/uploader.txt"
        exit 1
    fi
}

# Check if required tools are available
check_dependencies() {
    local missing_deps=()
    
    if ! command -v curl >/dev/null 2>&1; then
        missing_deps+=("curl")
    fi
    
    if ! command -v jq >/dev/null 2>&1; then
        log_warning "jq not found - JSON parsing will be limited"
    fi
    
    if [[ ${#missing_deps[@]} -gt 0 ]]; then
        log_error "Missing required dependencies: ${missing_deps[*]}"
        log_error "Please install: ${missing_deps[*]}"
        exit 1
    fi
}

# Validate file size
check_file_size() {
    local file_path="$1"
    local file_size
    
    if [[ ! -f "$file_path" ]]; then
        return 1
    fi
    
    file_size=$(stat -f%z "$file_path" 2>/dev/null || stat -c%s "$file_path" 2>/dev/null)
    
    if [[ $file_size -gt $MAX_FILE_SIZE ]]; then
        log_error "File $file_path is too large ($file_size bytes, max $MAX_FILE_SIZE bytes)"
        return 1
    fi
    
    return 0
}

# Check if file should be uploaded
should_upload_file() {
    local filename="$1"
    
    # Skip hidden files and common temporary files
    if [[ "$filename" =~ ^\. ]]; then
        return 1
    fi
    
    if [[ "$filename" =~ \.(tmp|swp|~)$ ]]; then
        return 1
    fi
    
    return 0
}

# Extract JSON message from response
extract_json_message() {
    local json_response="$1"
    
    if command -v jq >/dev/null 2>&1; then
        echo "$json_response" | jq -r '.message // empty' 2>/dev/null || echo ""
    else
        # Simple regex-based extraction as fallback
        echo "$json_response" | grep -o '"message"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/.*"message"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/' || echo ""
    fi
}

# Upload a single file
upload_file() {
    local file_path="$1"
    local subdir="${2:-}"
    local temp_file
    local response
    local http_code
    local success=0
    
    # Validate file
    if ! check_file_size "$file_path"; then
        ((FILES_FAILED++))
        return 1
    fi
    
    if [[ ! -r "$file_path" ]]; then
        log_error "Cannot read file: $file_path"
        ((FILES_FAILED++))
        return 1
    fi
    
    # Create temporary file for response
    temp_file=$(mktemp)
    
    log_verbose "Uploading $file_path${subdir:+ to subdirectory '$subdir'}"
    
    # Build curl command
    local curl_cmd=(
        curl -s -w "%{http_code}" -o "$temp_file"
        -X POST
        -F "file=@$file_path"
        -F "key=$SEC_KEY"
    )
    
    if [[ -n "$subdir" ]]; then
        curl_cmd+=(-F "subdir=$subdir")
    fi
    
    curl_cmd+=("$API_URL")
    
    # Execute upload
    if response=$("${curl_cmd[@]}" 2>/dev/null); then
        http_code="${response: -3}"
        response_body=$(cat "$temp_file")
        
        if [[ "$http_code" == "200" ]]; then
            success=1
            ((FILES_UPLOADED++))
            
            if [[ $VERBOSE -eq 1 ]]; then
                log_success "Uploaded: $file_path"
                if [[ -n "$response_body" ]]; then
                    log_verbose "Response: $response_body"
                fi
            else
                # Show progress
                local progress=$(( (FILES_UPLOADED + FILES_FAILED) * 100 / FILES_TOTAL ))
                local elapsed=$(( $(date +%s) - START_TIME ))
                echo -e "${GREEN}✓${NC} $(basename "$file_path") [$((FILES_UPLOADED + FILES_FAILED))/$FILES_TOTAL files, ${progress}%, ${elapsed}s elapsed]"
            fi
        else
            local error_msg
            error_msg=$(extract_json_message "$response_body")
            
            if [[ -n "$error_msg" ]]; then
                log_error "Upload failed for $file_path: $error_msg"
            else
                log_error "Server error for $file_path: HTTP $http_code"
            fi
            
            if [[ $VERBOSE -eq 1 && -n "$response_body" ]]; then
                log_verbose "Full response: $response_body"
            fi
            
            ((FILES_FAILED++))
        fi
    else
        log_error "Upload failed for $file_path: Network error"
        ((FILES_FAILED++))
    fi
    
    # Cleanup
    rm -f "$temp_file"
    
    return $((1 - success))
}

# Collect files for upload (recursive)
collect_files() {
    local dir_path="$1"
    local subdir_prefix="${2:-}"
    local files=()
    
    log_verbose "Scanning directory: $dir_path"
    
    while IFS= read -r -d '' file; do
        local filename=$(basename "$file")
        
        if should_upload_file "$filename"; then
            files+=("$file")
        else
            log_verbose "Skipping $file (filtered)"
        fi
    done < <(find "$dir_path" -type f -print0)
    
    echo "${files[@]}"
}

# Upload files in parallel
upload_files_parallel() {
    local files=("$@")
    local pids=()
    local max_jobs=$CONCURRENT_UPLOADS
    local job_count=0
    
    log_info "Using $max_jobs concurrent upload threads"
    log_info "Queued ${#files[@]} files for parallel upload"
    
    for file in "${files[@]}"; do
        # Wait if we've reached the maximum number of concurrent jobs
        while [[ $job_count -ge $max_jobs ]]; do
            # Check for completed jobs
            for i in "${!pids[@]}"; do
                if ! kill -0 "${pids[$i]}" 2>/dev/null; then
                    wait "${pids[$i]}"
                    unset "pids[$i]"
                    ((job_count--))
                fi
            done
            
            # Rebuild array to remove gaps
            pids=("${pids[@]}")
            
            if [[ $job_count -ge $max_jobs ]]; then
                sleep 0.1
            fi
        done
        
        # Start upload in background
        upload_file "$file" &
        pids+=($!)
        ((job_count++))
    done
    
    # Wait for all remaining jobs to complete
    for pid in "${pids[@]}"; do
        wait "$pid"
    done
}

# Upload directory contents
upload_directory() {
    local dir_path="$1"
    local subdir_prefix="${2:-}"
    local files
    
    log_info "Uploading directory: $dir_path"
    
    # Collect all files
    mapfile -t files < <(collect_files "$dir_path" "$subdir_prefix")
    
    if [[ ${#files[@]} -eq 0 ]]; then
        log_warning "No files found to upload in $dir_path"
        return 1
    fi
    
    FILES_TOTAL=${#files[@]}
    START_TIME=$(date +%s)
    
    # Upload files in parallel
    upload_files_parallel "${files[@]}"
    
    return 0
}

# Parse command line arguments
parse_arguments() {
    local target_path=""
    local subdir=""
    
    while [[ $# -gt 0 ]]; do
        case $1 in
            -h|--help)
                print_usage
                exit 0
                ;;
            -v|--verbose)
                VERBOSE=1
                shift
                ;;
            -u|--url)
                if [[ $# -lt 2 ]]; then
                    log_error "Error: --url requires a URL argument"
                    exit 1
                fi
                API_URL="$2"
                shift 2
                ;;
            -j|--jobs)
                if [[ $# -lt 2 ]]; then
                    log_error "Error: --jobs requires a number argument"
                    exit 1
                fi
                CONCURRENT_UPLOADS="$2"
                if [[ ! "$CONCURRENT_UPLOADS" =~ ^[0-9]+$ ]] || [[ $CONCURRENT_UPLOADS -lt 1 ]] || [[ $CONCURRENT_UPLOADS -gt $MAX_CONCURRENT_UPLOADS ]]; then
                    log_error "Error: jobs must be between 1 and $MAX_CONCURRENT_UPLOADS"
                    exit 1
                fi
                shift 2
                ;;
            --version)
                echo "File Uploader v$VERSION (Bash)"
                exit 0
                ;;
            -*)
                log_error "Unknown option: $1"
                exit 1
                ;;
            *)
                if [[ -z "$target_path" ]]; then
                    target_path="$1"
                elif [[ -z "$subdir" ]]; then
                    subdir="$1"
                else
                    log_error "Too many arguments"
                    exit 1
                fi
                shift
                ;;
        esac
    done
    
    if [[ -z "$target_path" ]]; then
        log_error "Error: file or directory path is required"
        print_usage
        exit 1
    fi
    
    echo "$target_path|$subdir"
}

# Main function
main() {
    local target_path subdir
    local result
    
    # Parse arguments
    result=$(parse_arguments "$@")
    IFS='|' read -r target_path subdir <<< "$result"
    
    # Initialize
    check_dependencies
    init_config
    
    if [[ $VERBOSE -eq 1 ]]; then
        log_info "API URL: $API_URL"
        log_info "Target: $target_path"
        [[ -n "$subdir" ]] && log_info "Subdirectory: $subdir"
        echo
    fi
    
    # Check if target exists
    if [[ ! -e "$target_path" ]]; then
        log_error "Cannot access path '$target_path'"
        exit 1
    fi
    
    # Process based on file type
    if [[ -d "$target_path" ]]; then
        upload_directory "$target_path" "$subdir"
    elif [[ -f "$target_path" ]]; then
        FILES_TOTAL=1
        START_TIME=$(date +%s)
        upload_file "$target_path" "$subdir"
    else
        log_error "Error: '$target_path' is not a regular file or directory"
        exit 1
    fi
    
    # Print summary
    echo
    echo "=== Upload Summary ==="
    echo "Files uploaded: $FILES_UPLOADED"
    echo "Files failed: $FILES_FAILED"
    
    if [[ $FILES_FAILED -eq 0 ]]; then
        echo "Overall result: SUCCESS"
        exit 0
    else
        echo "Overall result: PARTIAL/FAILURE"
        exit 1
    fi
}

# Run main function with all arguments
main "$@"
