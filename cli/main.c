#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <dirent.h>
#include <sys/stat.h>
#include <curl/curl.h>
#include <errno.h>
#include <unistd.h>
#include <libgen.h>
#include <pthread.h>
#include <semaphore.h>
#include <time.h>

// Default configuration - can be overridden at build time
#ifndef DEFAULT_API_URL
#define DEFAULT_API_URL "http://localhost/upload.php"
#endif
#define MAX_PATH_LENGTH 4096
#define MAX_FILENAME_LENGTH 256
#define MAX_FILE_SIZE (100 * 1024 * 1024)  // 100MB limit
#define MAX_CONCURRENT_UPLOADS 4  // Maximum parallel uploads
#define UPLOAD_QUEUE_SIZE 100     // Maximum queued uploads

// Secret key (compiled in during build)
#ifndef SEC_KEY
#error "SEC_KEY must be defined during compilation"
#endif

// Upload job structure
typedef struct upload_job {
    char file_path[MAX_PATH_LENGTH];
    char subdir[MAX_PATH_LENGTH];
    struct upload_job *next;
} upload_job_t;

// Upload queue structure
typedef struct {
    upload_job_t *head;
    upload_job_t *tail;
    pthread_mutex_t mutex;
    sem_t items;
    sem_t space;
    int shutdown;
} upload_queue_t;

// Worker thread data
typedef struct {
    int thread_id;
    upload_queue_t *queue;
    CURLM *multi_handle;
} worker_data_t;

// Global configuration
static char g_api_url[MAX_PATH_LENGTH] = DEFAULT_API_URL;
static int g_verbose = 0;
static int g_files_uploaded = 0;
static int g_files_failed = 0;
static int g_files_total = 0;
static int g_concurrent_uploads = MAX_CONCURRENT_UPLOADS;
static pthread_mutex_t g_stats_mutex = PTHREAD_MUTEX_INITIALIZER;
static time_t g_start_time;

// Structure to hold response data
struct response_data {
    char *memory;
    size_t size;
};

/**
 * Simple JSON message parser - extracts value of "message" field
 * Returns allocated string that must be freed, or NULL if not found
 */
char* extract_json_message(const char* json_str) {
    if (!json_str) return NULL;
    
    // Look for "message" field
    const char* message_start = strstr(json_str, "\"message\"");
    if (!message_start) return NULL;
    
    // Find the colon after "message"
    const char* colon = strchr(message_start, ':');
    if (!colon) return NULL;
    
    // Skip whitespace and find opening quote
    const char* quote_start = colon + 1;
    while (*quote_start && (*quote_start == ' ' || *quote_start == '\t')) {
        quote_start++;
    }
    
    if (*quote_start != '"') return NULL;
    quote_start++; // Skip opening quote
    
    // Find closing quote
    const char* quote_end = quote_start;
    while (*quote_end && *quote_end != '"') {
        if (*quote_end == '\\' && *(quote_end + 1)) {
            quote_end += 2; // Skip escaped character
        } else {
            quote_end++;
        }
    }
    
    if (*quote_end != '"') return NULL;
    
    // Allocate and copy message
    size_t msg_len = quote_end - quote_start;
    char* message = malloc(msg_len + 1);
    if (!message) return NULL;
    
    strncpy(message, quote_start, msg_len);
    message[msg_len] = '\0';
    
    return message;
}

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
 * Initialize upload queue
 */
int init_upload_queue(upload_queue_t *queue) {
    queue->head = NULL;
    queue->tail = NULL;
    queue->shutdown = 0;
    
    if (pthread_mutex_init(&queue->mutex, NULL) != 0) {
        return -1;
    }
    
    if (sem_init(&queue->items, 0, 0) != 0) {
        pthread_mutex_destroy(&queue->mutex);
        return -1;
    }
    
    if (sem_init(&queue->space, 0, UPLOAD_QUEUE_SIZE) != 0) {
        sem_destroy(&queue->items);
        pthread_mutex_destroy(&queue->mutex);
        return -1;
    }
    
    return 0;
}

/**
 * Destroy upload queue
 */
void destroy_upload_queue(upload_queue_t *queue) {
    pthread_mutex_lock(&queue->mutex);
    queue->shutdown = 1;
    
    // Free remaining jobs
    upload_job_t *job = queue->head;
    while (job) {
        upload_job_t *next = job->next;
        free(job);
        job = next;
    }
    
    pthread_mutex_unlock(&queue->mutex);
    
    sem_destroy(&queue->items);
    sem_destroy(&queue->space);
    pthread_mutex_destroy(&queue->mutex);
}

/**
 * Add job to upload queue
 */
int enqueue_upload(upload_queue_t *queue, const char *file_path, const char *subdir) {
    if (sem_wait(&queue->space) != 0) {
        return -1;
    }
    
    upload_job_t *job = malloc(sizeof(upload_job_t));
    if (!job) {
        sem_post(&queue->space);
        return -1;
    }
    
    strncpy(job->file_path, file_path, sizeof(job->file_path) - 1);
    job->file_path[sizeof(job->file_path) - 1] = '\0';
    
    strncpy(job->subdir, subdir ? subdir : "", sizeof(job->subdir) - 1);
    job->subdir[sizeof(job->subdir) - 1] = '\0';
    
    job->next = NULL;
    
    pthread_mutex_lock(&queue->mutex);
    if (queue->shutdown) {
        pthread_mutex_unlock(&queue->mutex);
        free(job);
        sem_post(&queue->space);
        return -1;
    }
    
    if (queue->tail) {
        queue->tail->next = job;
    } else {
        queue->head = job;
    }
    queue->tail = job;
    pthread_mutex_unlock(&queue->mutex);
    
    sem_post(&queue->items);
    return 0;
}

/**
 * Get job from upload queue
 */
upload_job_t* dequeue_upload(upload_queue_t *queue) {
    if (sem_wait(&queue->items) != 0) {
        return NULL;
    }
    
    pthread_mutex_lock(&queue->mutex);
    if (queue->shutdown && !queue->head) {
        pthread_mutex_unlock(&queue->mutex);
        return NULL;
    }
    
    upload_job_t *job = queue->head;
    if (job) {
        queue->head = job->next;
        if (!queue->head) {
            queue->tail = NULL;
        }
    }
    pthread_mutex_unlock(&queue->mutex);
    
    sem_post(&queue->space);
    return job;
}

/**
 * Signal queue shutdown
 */
void shutdown_queue(upload_queue_t *queue) {
    pthread_mutex_lock(&queue->mutex);
    queue->shutdown = 1;
    pthread_mutex_unlock(&queue->mutex);
    
    // Wake up all waiting threads
    for (int i = 0; i < MAX_CONCURRENT_UPLOADS; i++) {
        sem_post(&queue->items);
    }
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
 * Create optimized CURL handle with performance settings
 */
CURL* create_optimized_curl_handle(void) {
    CURL *curl = curl_easy_init();
    if (!curl) {
        return NULL;
    }
    
    // Performance optimizations
    curl_easy_setopt(curl, CURLOPT_TCP_KEEPALIVE, 1L);
    curl_easy_setopt(curl, CURLOPT_TCP_KEEPIDLE, 60L);
    curl_easy_setopt(curl, CURLOPT_TCP_KEEPINTVL, 60L);
    curl_easy_setopt(curl, CURLOPT_FORBID_REUSE, 0L);
    curl_easy_setopt(curl, CURLOPT_FRESH_CONNECT, 0L);
    curl_easy_setopt(curl, CURLOPT_MAXCONNECTS, 10L);
    
    // Compression support
    curl_easy_setopt(curl, CURLOPT_ACCEPT_ENCODING, "");
    
    // Timeout settings
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 300L);
    curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 30L);
    
    // HTTP settings
    curl_easy_setopt(curl, CURLOPT_FOLLOWLOCATION, 1L);
    curl_easy_setopt(curl, CURLOPT_MAXREDIRS, 3L);
    curl_easy_setopt(curl, CURLOPT_USERAGENT, "file_uploader/1.1.0");
    
    return curl;
}

/**
 * Upload a single file to the server (optimized version)
 */
int upload_file_optimized(const char *file_path, const char *subdir, CURL *curl) {
    curl_mime *form = NULL;
    curl_mimepart *field = NULL;
    struct response_data response = {0};
    long response_code = 0;
    int success = 0;
    char url_with_key[MAX_PATH_LENGTH * 2];
    CURLcode res;
    
    // Validate file before uploading
    if (!check_file_size(file_path)) {
        pthread_mutex_lock(&g_stats_mutex);
        g_files_failed++;
        pthread_mutex_unlock(&g_stats_mutex);
        return 0;
    }
    
    // Check if file exists and is readable
    if (access(file_path, R_OK) != 0) {
        fprintf(stderr, "Cannot read file %s: %s\n", file_path, strerror(errno));
        pthread_mutex_lock(&g_stats_mutex);
        g_files_failed++;
        pthread_mutex_unlock(&g_stats_mutex);
        return 0;
    }

    form = curl_mime_init(curl);
    if (!form) {
        fprintf(stderr, "Failed to initialize MIME form\n");
        pthread_mutex_lock(&g_stats_mutex);
        g_files_failed++;
        pthread_mutex_unlock(&g_stats_mutex);
        return 0;
    }

    // Add file
    field = curl_mime_addpart(form);
    if (!field) {
        fprintf(stderr, "Failed to add file part to form\n");
        curl_mime_free(form);
        pthread_mutex_lock(&g_stats_mutex);
        g_files_failed++;
        pthread_mutex_unlock(&g_stats_mutex);
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
    
    // Configure CURL options for this request
    curl_easy_setopt(curl, CURLOPT_URL, url_with_key);
    curl_easy_setopt(curl, CURLOPT_MIMEPOST, form);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, WriteMemoryCallback);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, (void *)&response);
    
    if (g_verbose) {
        printf("[Thread] Uploading %s", file_path);
        if (subdir && strlen(subdir) > 0) {
            printf(" to subdirectory '%s'", subdir);
        }
        printf("...\n");
    }

    // Perform the upload
    res = curl_easy_perform(curl);
    
    // Get response code
    curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &response_code);
    
    if (res != CURLE_OK) {
        fprintf(stderr, "Upload failed for %s: %s\n", file_path, curl_easy_strerror(res));
        pthread_mutex_lock(&g_stats_mutex);
        g_files_failed++;
        pthread_mutex_unlock(&g_stats_mutex);
    } else if (response_code != 200) {
        // Try to extract error message from JSON response
        char* error_message = NULL;
        if (response.memory) {
            error_message = extract_json_message(response.memory);
        }
        
        if (error_message) {
            fprintf(stderr, "Upload failed for %s: %s\n", file_path, error_message);
            free(error_message);
        } else {
            fprintf(stderr, "Server error for %s: HTTP %ld\n", file_path, response_code);
        }
        
        if (response.memory && g_verbose) {
            fprintf(stderr, "Full response: %s\n", response.memory);
        }
        pthread_mutex_lock(&g_stats_mutex);
        g_files_failed++;
        pthread_mutex_unlock(&g_stats_mutex);
    } else {
        success = 1;
        pthread_mutex_lock(&g_stats_mutex);
        g_files_uploaded++;
        
        // Show progress
        if (!g_verbose) {
            double progress = (double)(g_files_uploaded + g_files_failed) / g_files_total * 100.0;
            time_t elapsed = time(NULL) - g_start_time;
            printf("✓ %s [%d/%d files, %.1f%%, %lds elapsed]\n", 
                   basename((char*)file_path), g_files_uploaded + g_files_failed, 
                   g_files_total, progress, elapsed);
        }
        pthread_mutex_unlock(&g_stats_mutex);
        
        if (g_verbose && response.memory) {
            printf("Success: %s\n", response.memory);
        }
    }

    // Cleanup
    if (response.memory) {
        free(response.memory);
    }
    curl_mime_free(form);
    
    return success;
}

/**
 * Legacy upload function for compatibility
 */
int upload_file(const char *file_path, const char *subdir) {
    CURL *curl = create_optimized_curl_handle();
    if (!curl) {
        fprintf(stderr, "Failed to initialize CURL\n");
        pthread_mutex_lock(&g_stats_mutex);
        g_files_failed++;
        pthread_mutex_unlock(&g_stats_mutex);
        return 0;
    }
    
    int result = upload_file_optimized(file_path, subdir, curl);
    curl_easy_cleanup(curl);
    return result;
}

/**
 * Worker thread function for parallel uploads
 */
void* upload_worker(void *arg) {
    worker_data_t *data = (worker_data_t*)arg;
    CURL *curl = create_optimized_curl_handle();
    
    if (!curl) {
        fprintf(stderr, "Worker thread %d: Failed to initialize CURL\n", data->thread_id);
        return NULL;
    }
    
    if (g_verbose) {
        printf("Upload worker thread %d started\n", data->thread_id);
    }
    
    while (1) {
        upload_job_t *job = dequeue_upload(data->queue);
        if (!job) {
            break; // Queue shutdown
        }
        
        upload_file_optimized(job->file_path, job->subdir, curl);
        free(job);
    }
    
    curl_easy_cleanup(curl);
    
    if (g_verbose) {
        printf("Upload worker thread %d finished\n", data->thread_id);
    }
    
    return NULL;
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
 * Recursively collect files for upload (queue-based)
 */
int collect_files_for_upload(const char *dir_path, const char *subdir_prefix, upload_queue_t *queue) {
    DIR *dir;
    struct dirent *entry;
    struct stat st;
    char full_path[MAX_PATH_LENGTH];
    char new_subdir[MAX_PATH_LENGTH];
    int total_files = 0;

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
            // Recursively collect files from subdirectory
            total_files += collect_files_for_upload(full_path, new_subdir, queue);
        } else if (S_ISREG(st.st_mode)) {
            // Queue regular file for upload
            if (should_upload_file(entry->d_name)) {
                if (enqueue_upload(queue, full_path, subdir_prefix) == 0) {
                    total_files++;
                } else {
                    fprintf(stderr, "Failed to queue file %s\n", full_path);
                }
            } else if (g_verbose) {
                printf("Skipping %s (filtered)\n", full_path);
            }
        }
    }

    closedir(dir);
    return total_files;
}

/**
 * Parallel upload directory contents
 */
int upload_dir_parallel(const char *dir_path, const char *subdir_prefix) {
    upload_queue_t queue;
    pthread_t workers[MAX_CONCURRENT_UPLOADS];
    worker_data_t worker_data[MAX_CONCURRENT_UPLOADS];
    int files_queued = 0;
    
    // Initialize upload queue
    if (init_upload_queue(&queue) != 0) {
        fprintf(stderr, "Failed to initialize upload queue\n");
        return 0;
    }
    
    printf("Using %d concurrent upload threads\n", g_concurrent_uploads);
    
    // Start worker threads
    for (int i = 0; i < g_concurrent_uploads; i++) {
        worker_data[i].thread_id = i + 1;
        worker_data[i].queue = &queue;
        worker_data[i].multi_handle = NULL;
        
        if (pthread_create(&workers[i], NULL, upload_worker, &worker_data[i]) != 0) {
            fprintf(stderr, "Failed to create worker thread %d\n", i + 1);
            shutdown_queue(&queue);
            
            // Wait for already started threads
            for (int j = 0; j < i; j++) {
                pthread_join(workers[j], NULL);
            }
            
            destroy_upload_queue(&queue);
            return 0;
        }
    }
    
    // Collect all files and queue them for upload
    files_queued = collect_files_for_upload(dir_path, subdir_prefix, &queue);
    g_files_total = files_queued;
    g_start_time = time(NULL);
    printf("Queued %d files for parallel upload\n", files_queued);
    
    // Signal completion and wait for workers to finish
    shutdown_queue(&queue);
    
    for (int i = 0; i < g_concurrent_uploads; i++) {
        pthread_join(workers[i], NULL);
    }
    
    destroy_upload_queue(&queue);
    
    return files_queued > 0 ? 1 : 0;
}

/**
 * Recursively upload directory contents (legacy sequential version)
 */
int upload_dir(const char *dir_path, const char *subdir_prefix) {
    // Use parallel upload for better performance
    return upload_dir_parallel(dir_path, subdir_prefix);
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
    printf("  -j, --jobs N        Number of concurrent uploads (1-%d, default: %d)\n", 
           MAX_CONCURRENT_UPLOADS, MAX_CONCURRENT_UPLOADS);
    printf("  --version           Show version information\n\n");
    printf("Arguments:\n");
    printf("  file_or_dir         File or directory to upload\n");
    printf("  subdir              Optional subdirectory on server\n\n");
    printf("Examples:\n");
    printf("  %s file.txt\n", program_name);
    printf("  %s file.txt my_folder\n", program_name);
    printf("  %s myDir/\n", program_name);
    printf("  %s myDir/ some/path\n", program_name);
    printf("  %s -j 8 -v /path/to/directory\n", program_name);
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
        } else if (strcmp(argv[i], "-j") == 0 || strcmp(argv[i], "--jobs") == 0) {
            if (i + 1 >= argc) {
                fprintf(stderr, "Error: --jobs requires a number argument\n");
                return 0;
            }
            int jobs = atoi(argv[++i]);
            if (jobs < 1 || jobs > MAX_CONCURRENT_UPLOADS) {
                fprintf(stderr, "Error: jobs must be between 1 and %d\n", MAX_CONCURRENT_UPLOADS);
                return 0;
            }
            g_concurrent_uploads = jobs;
        } else if (strcmp(argv[i], "--version") == 0) {
            printf("File Uploader v1.2.0 (Parallel)\n");
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
        g_files_total = 1;
        g_start_time = time(NULL);
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

