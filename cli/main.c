#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <dirent.h>
#include <sys/stat.h>
#include <curl/curl.h>
#include <errno.h>
#include <unistd.h>
#include <libgen.h>

// Default configuration - can be overridden at build time
#ifndef DEFAULT_API_URL
#define DEFAULT_API_URL "http://localhost/upload.php"
#endif
#define MAX_PATH_LENGTH 4096
#define MAX_FILENAME_LENGTH 256
#define MAX_FILE_SIZE (100 * 1024 * 1024)  // 100MB limit

// Secret key (compiled in during build)
#ifndef SEC_KEY
#error "SEC_KEY must be defined during compilation"
#endif

// Global configuration
static char g_api_url[MAX_PATH_LENGTH] = DEFAULT_API_URL;
static int g_verbose = 0;
static int g_files_uploaded = 0;
static int g_files_failed = 0;

// Structure to hold response data
struct response_data {
    char *memory;
    size_t size;
};

// Callback function to capture response
static size_t WriteMemoryCallback(void *contents, size_t size, size_t nmemb, struct response_data *userp) {
    size_t realsize = size * nmemb;
    char *ptr = realloc(userp->memory, userp->size + realsize + 1);
    
    if (!ptr) {
        fprintf(stderr, "Not enough memory (realloc returned NULL)\n");
        return 0;
    }
    
    userp->memory = ptr;
    memcpy(&(userp->memory[userp->size]), contents, realsize);
    userp->size += realsize;
    userp->memory[userp->size] = 0;
    
    return realsize;
}

/**
 * Build complete API URL with secret key parameter
 */
void build_api_url_with_key(char *url_buffer, size_t buffer_size, const char *base_url) {
    const char *key = SEC_KEY;
    
    // Check if URL already has query parameters
    if (strchr(base_url, '?') != NULL) {
        snprintf(url_buffer, buffer_size, "%s&key=%s", base_url, key);
    } else {
        snprintf(url_buffer, buffer_size, "%s?key=%s", base_url, key);
    }
}

/**
 * Check if file size is within acceptable limits
 */
int check_file_size(const char *file_path) {
    struct stat st;
    if (stat(file_path, &st) != 0) {
        fprintf(stderr, "Cannot get file size for %s: %s\n", file_path, strerror(errno));
        return 0;
    }
    
    if (st.st_size > MAX_FILE_SIZE) {
        fprintf(stderr, "File %s is too large (%ld bytes, max %d bytes)\n", 
                file_path, st.st_size, MAX_FILE_SIZE);
        return 0;
    }
    
    return 1;
}

/**
 * Upload a single file to the server
 */
int upload_file(const char *file_path, const char *subdir) {
    CURL *curl;
    CURLcode res;
    curl_mime *form = NULL;
    curl_mimepart *field = NULL;
    struct response_data response = {0};
    long response_code = 0;
    int success = 0;
    char url_with_key[MAX_PATH_LENGTH * 2]; // Extra space for key parameter
    
    // Validate file before uploading
    if (!check_file_size(file_path)) {
        g_files_failed++;
        return 0;
    }
    
    // Check if file exists and is readable
    if (access(file_path, R_OK) != 0) {
        fprintf(stderr, "Cannot read file %s: %s\n", file_path, strerror(errno));
        g_files_failed++;
        return 0;
    }

    curl = curl_easy_init();
    if (!curl) {
        fprintf(stderr, "Failed to initialize CURL\n");
        g_files_failed++;
        return 0;
    }

    form = curl_mime_init(curl);
    if (!form) {
        fprintf(stderr, "Failed to initialize MIME form\n");
        curl_easy_cleanup(curl);
        g_files_failed++;
        return 0;
    }

    // Add file
    field = curl_mime_addpart(form);
    if (!field) {
        fprintf(stderr, "Failed to add file part to form\n");
        curl_mime_free(form);
        curl_easy_cleanup(curl);
        g_files_failed++;
        return 0;
    }
    
    curl_mime_name(field, "file");
    curl_mime_filedata(field, file_path);

    // Add subdir field if provided
    if (subdir && strlen(subdir) > 0) {
        field = curl_mime_addpart(form);
        if (field) {
            curl_mime_name(field, "subdir");
            curl_mime_data(field, subdir, CURL_ZERO_TERMINATED);
        }
    }

    // Build URL with secret key
    build_api_url_with_key(url_with_key, sizeof(url_with_key), g_api_url);
    
    // Configure CURL options
    curl_easy_setopt(curl, CURLOPT_URL, url_with_key);
    curl_easy_setopt(curl, CURLOPT_MIMEPOST, form);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, WriteMemoryCallback);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, (void *)&response);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 300L);  // 5 minute timeout
    curl_easy_setopt(curl, CURLOPT_FOLLOWLOCATION, 1L);
    
    if (g_verbose) {
        printf("Uploading %s", file_path);
        if (subdir && strlen(subdir) > 0) {
            printf(" to subdirectory '%s'", subdir);
        }
        printf(" to %s", g_api_url);
        printf("...\n");
    }

    // Perform the upload
    res = curl_easy_perform(curl);
    
    // Get response code
    curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &response_code);
    
    if (res != CURLE_OK) {
        fprintf(stderr, "Upload failed for %s: %s\n", file_path, curl_easy_strerror(res));
        g_files_failed++;
    } else if (response_code != 200) {
        fprintf(stderr, "Server error for %s: HTTP %ld\n", file_path, response_code);
        if (response.memory && g_verbose) {
            fprintf(stderr, "Response: %s\n", response.memory);
        }
        g_files_failed++;
    } else {
        success = 1;
        g_files_uploaded++;
        if (g_verbose && response.memory) {
            printf("Success: %s\n", response.memory);
        } else if (!g_verbose) {
            printf("✓ %s\n", basename((char*)file_path));
        }
    }

    // Cleanup
    if (response.memory) {
        free(response.memory);
    }
    curl_mime_free(form);
    curl_easy_cleanup(curl);
    
    return success;
}

/**
 * Check if a file should be uploaded based on extension
 */
int should_upload_file(const char *filename) {
    // Skip hidden files and common temporary files
    if (filename[0] == '.') return 0;
    if (strstr(filename, ".tmp") || strstr(filename, ".swp") || strstr(filename, "~")) return 0;
    
    return 1;  // Upload all other files by default
}

/**
 * Recursively upload directory contents
 */
int upload_dir(const char *dir_path, const char *subdir_prefix) {
    DIR *dir;
    struct dirent *entry;
    struct stat st;
    char full_path[MAX_PATH_LENGTH];
    char new_subdir[MAX_PATH_LENGTH];
    int total_success = 1;

    dir = opendir(dir_path);
    if (!dir) {
        fprintf(stderr, "Cannot open directory %s: %s\n", dir_path, strerror(errno));
        return 0;
    }

    if (g_verbose) {
        printf("Scanning directory: %s\n", dir_path);
    }

    while ((entry = readdir(dir)) != NULL) {
        // Skip . and ..
        if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0) {
            continue;
        }

        // Construct full path
        int ret = snprintf(full_path, sizeof(full_path), "%s/%s", dir_path, entry->d_name);
        if (ret >= (int)sizeof(full_path)) {
            fprintf(stderr, "Path too long: %s/%s\n", dir_path, entry->d_name);
            continue;
        }

        if (stat(full_path, &st) == -1) {
            fprintf(stderr, "Cannot stat %s: %s\n", full_path, strerror(errno));
            continue;
        }

        // Construct subdirectory path for server
        if (subdir_prefix && strlen(subdir_prefix) > 0) {
            snprintf(new_subdir, sizeof(new_subdir), "%s/%s", subdir_prefix, entry->d_name);
        } else {
            snprintf(new_subdir, sizeof(new_subdir), "%s", entry->d_name);
        }

        if (S_ISDIR(st.st_mode)) {
            // Recursively upload subdirectory
            if (!upload_dir(full_path, new_subdir)) {
                total_success = 0;
            }
        } else if (S_ISREG(st.st_mode)) {
            // Upload regular file
            if (should_upload_file(entry->d_name)) {
                if (!upload_file(full_path, subdir_prefix)) {
                    total_success = 0;
                }
            } else if (g_verbose) {
                printf("Skipping %s (filtered)\n", full_path);
            }
        }
    }

    closedir(dir);
    return total_success;
}

/**
 * Print usage information
 */
void print_usage(const char *program_name) {
    printf("Usage: %s [OPTIONS] <file_or_dir> [subdir]\n\n", program_name);
    printf("Options:\n");
    printf("  -h, --help          Show this help message\n");
    printf("  -v, --verbose       Enable verbose output\n");
    printf("  -u, --url URL       Set custom API URL (default: %s)\n", DEFAULT_API_URL);
    printf("  --version           Show version information\n\n");
    printf("Arguments:\n");
    printf("  file_or_dir         File or directory to upload\n");
    printf("  subdir              Optional subdirectory on server (for files only)\n\n");
    printf("Examples:\n");
    printf("  %s file.txt\n", program_name);
    printf("  %s file.txt my_folder\n", program_name);
    printf("  %s file.txt \"docs/mydocs\"\n", program_name);
    printf("  %s -v /path/to/directory\n", program_name);
    printf("  %s -u http://myserver.com/upload.php file.txt\n", program_name);
}

/**
 * Parse command line arguments
 */
int parse_arguments(int argc, char *argv[], char **target_path, char **subdir) {
    int i;
    
    for (i = 1; i < argc; i++) {
        if (strcmp(argv[i], "-h") == 0 || strcmp(argv[i], "--help") == 0) {
            print_usage(argv[0]);
            exit(0);
        } else if (strcmp(argv[i], "-v") == 0 || strcmp(argv[i], "--verbose") == 0) {
            g_verbose = 1;
        } else if (strcmp(argv[i], "-u") == 0 || strcmp(argv[i], "--url") == 0) {
            if (i + 1 >= argc) {
                fprintf(stderr, "Error: --url requires a URL argument\n");
                return 0;
            }
            strncpy(g_api_url, argv[++i], sizeof(g_api_url) - 1);
            g_api_url[sizeof(g_api_url) - 1] = '\0';
        } else if (strcmp(argv[i], "--version") == 0) {
            printf("File Uploader v1.1.0\n");
            exit(0);
        } else if (argv[i][0] == '-') {
            fprintf(stderr, "Unknown option: %s\n", argv[i]);
            return 0;
        } else {
            // First non-option argument is the target path
            if (!*target_path) {
                *target_path = argv[i];
            } else if (!*subdir || strlen(*subdir) == 0) {
                *subdir = argv[i];
            } else {
                fprintf(stderr, "Too many arguments\n");
                return 0;
            }
        }
    }
    
    return *target_path != NULL;
}

int main(int argc, char *argv[]) {
    char *target_path = NULL;
    char *subdir = "";
    struct stat st;
    int success = 1;

    // Initialize curl globally
    if (curl_global_init(CURL_GLOBAL_DEFAULT) != CURLE_OK) {
        fprintf(stderr, "Failed to initialize CURL\n");
        return 1;
    }

    // Parse command line arguments
    if (!parse_arguments(argc, argv, &target_path, &subdir)) {
        print_usage(argv[0]);
        curl_global_cleanup();
        return 1;
    }

    if (g_verbose) {
        printf("API URL: %s\n", g_api_url);
        printf("Target: %s\n", target_path);
        if (subdir && strlen(subdir) > 0) {
            printf("Subdirectory: %s\n", subdir);
        }
        printf("\n");
    }

    // Check if target exists
    if (stat(target_path, &st) == -1) {
        fprintf(stderr, "Cannot access path '%s': %s\n", target_path, strerror(errno));
        curl_global_cleanup();
        return 1;
    }

    // Process based on file type
    if (S_ISDIR(st.st_mode)) {
        printf("Uploading directory: %s\n", target_path);
        success = upload_dir(target_path, subdir);
    } else if (S_ISREG(st.st_mode)) {
        success = upload_file(target_path, subdir);
    } else {
        fprintf(stderr, "Error: '%s' is not a regular file or directory\n", target_path);
        success = 0;
    }

    // Print summary
    printf("\n=== Upload Summary ===\n");
    printf("Files uploaded: %d\n", g_files_uploaded);
    printf("Files failed: %d\n", g_files_failed);
    printf("Overall result: %s\n", success && g_files_failed == 0 ? "SUCCESS" : "PARTIAL/FAILURE");

    curl_global_cleanup();
    return success && g_files_failed == 0 ? 0 : 1;
}

