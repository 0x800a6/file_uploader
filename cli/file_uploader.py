#!/usr/bin/env python3
"""
Advanced File Uploader CLI
A comprehensive, feature-rich file uploader with interactive and command-line modes.

Features:
- Interactive and command-line modes
- Real-time progress bars and statistics
- Advanced configuration management with profiles
- Batch operations and queue management
- Resume capabilities for large files
- File compression and encryption options
- Comprehensive error handling and logging
- File validation and security checks
- Parallel uploads with configurable concurrency
- Built-in file browser and selection
- Advanced filtering and pattern matching
"""

import os
import sys
import json
import time
import hashlib
import argparse
import threading
import mimetypes
import configparser
from pathlib import Path
from typing import Dict, List, Optional, Tuple, Any, Callable
from dataclasses import dataclass, asdict
from datetime import datetime
import urllib.parse
import urllib.request
import urllib.error
import http.client
import ssl
import gzip
import zlib
import base64
import secrets
import tempfile
import shutil
import fnmatch
import re
from concurrent.futures import ThreadPoolExecutor, as_completed
from queue import Queue, Empty
import signal

# Version information
VERSION = "2.0.0"
APP_NAME = "Advanced File Uploader"

# ANSI color codes for terminal output
class Colors:
    RED = '\033[0;31m'
    GREEN = '\033[0;32m'
    YELLOW = '\033[1;33m'
    BLUE = '\033[0;34m'
    MAGENTA = '\033[0;35m'
    CYAN = '\033[0;36m'
    WHITE = '\033[1;37m'
    BOLD = '\033[1m'
    UNDERLINE = '\033[4m'
    END = '\033[0m'

# Configuration defaults
DEFAULT_CONFIG = {
    'url': 'http://localhost/upload.php',
    'key': '',
    'max_file_size': 104857600,  # 100MB
    'max_concurrent': 4,
    'chunk_size': 8192,
    'timeout': 30,
    'retry_attempts': 3,
    'retry_delay': 1,
    'compress_threshold': 1024,  # 1KB
    'encryption_enabled': False,
    'verify_ssl': True,
    'follow_redirects': True,
    'user_agent': f'{APP_NAME}/{VERSION}',
    'log_level': 'INFO',
    'log_file': '',
    'temp_dir': '',
    'default_subdir': '',
    'allowed_extensions': '',
    'blocked_extensions': '',
    'max_depth': 10,
    'exclude_patterns': '',
    'include_patterns': '',
    'preserve_structure': True,
    'create_checksums': True,
    'resume_enabled': True,
    'encryption_key': '',
    'compression_level': 6
}

@dataclass
class UploadStats:
    """Statistics for upload operations"""
    total_files: int = 0
    uploaded_files: int = 0
    failed_files: int = 0
    skipped_files: int = 0
    total_bytes: int = 0
    uploaded_bytes: int = 0
    start_time: float = 0
    end_time: float = 0
    
    @property
    def success_rate(self) -> float:
        if self.total_files == 0:
            return 0.0
        return (self.uploaded_files / self.total_files) * 100
    
    @property
    def duration(self) -> float:
        if self.end_time > 0:
            return self.end_time - self.start_time
        return time.time() - self.start_time
    
    @property
    def upload_speed(self) -> float:
        if self.duration > 0:
            return self.uploaded_bytes / self.duration
        return 0.0

@dataclass
class FileInfo:
    """Information about a file to be uploaded"""
    path: Path
    size: int
    mtime: float
    checksum: str
    mime_type: str
    relative_path: str
    subdir: str = ''
    encrypted: bool = False
    compressed: bool = False
    chunk_count: int = 1
    resume_pos: int = 0

class Logger:
    """Simple logging utility"""
    
    def __init__(self, level: str = 'INFO', log_file: Optional[str] = None):
        self.DEBUG = 0
        self.INFO = 1
        self.WARNING = 2
        self.ERROR = 3
        self.level = getattr(self, level.upper(), self.INFO)
        self.log_file = log_file
        
    def log(self, level: int, message: str, *args):
        if level >= self.level:
            timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            level_name = ['DEBUG', 'INFO', 'WARNING', 'ERROR'][level]
            msg = f"[{timestamp}] [{level_name}] {message % args if args else message}"
            
            # Print to console with colors
            if level == self.DEBUG:
                print(f"{Colors.BLUE}{msg}{Colors.END}")
            elif level == self.INFO:
                print(f"{Colors.GREEN}{msg}{Colors.END}")
            elif level == self.WARNING:
                print(f"{Colors.YELLOW}{msg}{Colors.END}")
            elif level == self.ERROR:
                print(f"{Colors.RED}{msg}{Colors.END}")
            
            # Write to log file if specified
            if self.log_file:
                try:
                    with open(self.log_file, 'a', encoding='utf-8') as f:
                        f.write(msg + '\n')
                except Exception:
                    pass
    
    def debug(self, message: str, *args):
        self.log(self.DEBUG, message, *args)
    
    def info(self, message: str, *args):
        self.log(self.INFO, message, *args)
    
    def warning(self, message: str, *args):
        self.log(self.WARNING, message, *args)
    
    def error(self, message: str, *args):
        self.log(self.ERROR, message, *args)

class ProgressBar:
    """Simple progress bar for terminal output"""
    
    def __init__(self, total: int, width: int = 50, desc: str = ''):
        self.total = total
        self.width = width
        self.desc = desc
        self.current = 0
        self.start_time = time.time()
        
    def update(self, n: int = 1):
        self.current = min(self.current + n, self.total)
        self._display()
    
    def set_progress(self, current: int):
        self.current = min(current, self.total)
        self._display()
    
    def _display(self):
        if self.total == 0:
            return
            
        progress = self.current / self.total
        filled = int(self.width * progress)
        bar = '█' * filled + '░' * (self.width - filled)
        
        elapsed = time.time() - self.start_time
        if progress > 0:
            eta = elapsed * (1 - progress) / progress
            eta_str = f"ETA: {int(eta//60):02d}:{int(eta%60):02d}"
        else:
            eta_str = "ETA: --:--"
        
        speed = self.current / elapsed if elapsed > 0 else 0
        speed_str = f"{speed:.1f}/s"
        
        print(f"\r{self.desc} |{bar}| {self.current}/{self.total} ({progress*100:.1f}%) {speed_str} {eta_str}", end='', flush=True)
        
        if self.current >= self.total:
            print()  # New line when complete

class ConfigManager:
    """Advanced configuration management with profiles"""
    
    def __init__(self, config_dir: Optional[str] = None):
        if config_dir is None:
            config_dir = Path.home() / '.config' / 'file_uploader'
        
        self.config_dir = Path(config_dir)
        self.config_dir.mkdir(parents=True, exist_ok=True)
        
        self.config_file = self.config_dir / 'config.ini'
        self.profiles_dir = self.config_dir / 'profiles'
        self.profiles_dir.mkdir(exist_ok=True)
        
        self.config = configparser.ConfigParser()
        self.current_profile = 'default'
        
    def load_config(self, profile: str = 'default') -> Dict[str, Any]:
        """Load configuration for a specific profile"""
        self.current_profile = profile
        
        # Start with defaults
        config = DEFAULT_CONFIG.copy()
        
        # Load main config file
        if self.config_file.exists():
            self.config.read(self.config_file)
            if 'default' in self.config:
                for key, value in self.config['default'].items():
                    config[key] = self._convert_value(value)
        
        # Load profile-specific config
        profile_file = self.profiles_dir / f'{profile}.ini'
        if profile_file.exists():
            profile_config = configparser.ConfigParser()
            profile_config.read(profile_file)
            if 'profile' in profile_config:
                for key, value in profile_config['profile'].items():
                    config[key] = self._convert_value(value)
        
        return config
    
    def save_config(self, config: Dict[str, Any], profile: str = None):
        """Save configuration for a profile"""
        if profile is None:
            profile = self.current_profile
        
        if profile == 'default':
            # Save to main config file
            if not self.config.has_section('default'):
                self.config.add_section('default')
            
            for key, value in config.items():
                self.config.set('default', key, str(value))
            
            with open(self.config_file, 'w') as f:
                self.config.write(f)
        else:
            # Save to profile-specific file
            profile_config = configparser.ConfigParser()
            profile_config.add_section('profile')
            
            for key, value in config.items():
                profile_config.set('profile', key, str(value))
            
            profile_file = self.profiles_dir / f'{profile}.ini'
            with open(profile_file, 'w') as f:
                profile_config.write(f)
    
    def list_profiles(self) -> List[str]:
        """List available profiles"""
        profiles = ['default']
        
        for profile_file in self.profiles_dir.glob('*.ini'):
            profile_name = profile_file.stem
            if profile_name != 'default':
                profiles.append(profile_name)
        
        return profiles
    
    def delete_profile(self, profile: str):
        """Delete a profile"""
        if profile == 'default':
            raise ValueError("Cannot delete default profile")
        
        profile_file = self.profiles_dir / f'{profile}.ini'
        if profile_file.exists():
            profile_file.unlink()
    
    def _convert_value(self, value: str) -> Any:
        """Convert string value to appropriate type"""
        # Remove comments (everything after #)
        if '#' in value:
            value = value.split('#')[0].strip()
        
        # Boolean values
        if value.lower() in ('true', 'false'):
            return value.lower() == 'true'
        
        # Integer values (but preserve strings that start with 0 unless they're pure digits)
        if value.isdigit():
            # If it starts with 0 and has more than 1 digit, treat as string to preserve leading zeros
            if value.startswith('0') and len(value) > 1:
                return value
            return int(value)
        
        # Float values
        try:
            return float(value)
        except ValueError:
            pass
        
        # List values (comma-separated)
        if ',' in value:
            return [item.strip() for item in value.split(',')]
        
        return value

class FileValidator:
    """File validation and security checks"""
    
    def __init__(self, config: Dict[str, Any]):
        self.config = config
        self.max_size = int(config.get('max_file_size', DEFAULT_CONFIG['max_file_size']))
        self.max_depth = int(config.get('max_depth', DEFAULT_CONFIG['max_depth']))
        
        # Parse allowed/blocked extensions
        allowed = config.get('allowed_extensions', '')
        blocked = config.get('blocked_extensions', '')
        
        # Handle both string and list values (from config conversion)
        if isinstance(allowed, list):
            self.allowed_extensions = set(allowed)
        else:
            self.allowed_extensions = set(allowed.split(',') if allowed else [])
        
        if isinstance(blocked, list):
            self.blocked_extensions = set(blocked)
        else:
            self.blocked_extensions = set(blocked.split(',') if blocked else [])
        
        # Parse patterns
        exclude = config.get('exclude_patterns', '')
        include = config.get('include_patterns', '')
        
        # Handle both string and list values (from config conversion)
        if isinstance(exclude, list):
            self.exclude_patterns = exclude
        else:
            self.exclude_patterns = exclude.split(',') if exclude else []
        
        if isinstance(include, list):
            self.include_patterns = include
        else:
            self.include_patterns = include.split(',') if include else []
    
    def validate_file(self, file_path: Path, base_path: Path = None) -> Tuple[bool, str]:
        """Validate a single file"""
        try:
            # Check if file exists and is readable
            if not file_path.exists():
                return False, "File does not exist"
            
            if not file_path.is_file():
                return False, "Not a regular file"
            
            if not os.access(file_path, os.R_OK):
                return False, "File is not readable"
            
            # Check file size
            file_size = file_path.stat().st_size
            if file_size > self.max_size:
                return False, f"File too large ({file_size} bytes, max {self.max_size})"
            
            # Check extension
            ext = file_path.suffix.lower().lstrip('.')
            if self.allowed_extensions and ext not in self.allowed_extensions:
                return False, f"Extension '{ext}' not allowed"
            
            if ext in self.blocked_extensions:
                return False, f"Extension '{ext}' is blocked"
            
            # Check patterns
            if base_path:
                relative_path = file_path.relative_to(base_path)
                path_str = str(relative_path)
            else:
                path_str = str(file_path)
            
            # Check exclude patterns
            for pattern in self.exclude_patterns:
                if fnmatch.fnmatch(path_str, pattern.strip()):
                    return False, f"File matches exclude pattern: {pattern}"
            
            # Check include patterns (if specified)
            if self.include_patterns:
                matches_include = False
                for pattern in self.include_patterns:
                    if fnmatch.fnmatch(path_str, pattern.strip()):
                        matches_include = True
                        break
                if not matches_include:
                    return False, f"File does not match any include pattern"
            
            return True, "Valid"
            
        except Exception as e:
            return False, f"Validation error: {str(e)}"
    
    def scan_directory(self, dir_path: Path, max_depth: int = None) -> List[FileInfo]:
        """Scan directory and return list of valid files"""
        if max_depth is None:
            max_depth = self.max_depth
        
        files = []
        base_path = dir_path
        
        def scan_recursive(current_path: Path, depth: int):
            if depth > max_depth:
                return
            
            try:
                for item in current_path.iterdir():
                    if item.is_file():
                        valid, reason = self.validate_file(item, base_path)
                        if valid:
                            file_info = self._create_file_info(item, base_path)
                            files.append(file_info)
                        # Skip invalid files silently in directory scan
                    elif item.is_dir():
                        scan_recursive(item, depth + 1)
            except PermissionError:
                pass  # Skip directories we can't read
        
        scan_recursive(dir_path, 0)
        return files
    
    def _create_file_info(self, file_path: Path, base_path: Path) -> FileInfo:
        """Create FileInfo object for a file"""
        stat = file_path.stat()
        relative_path = str(file_path.relative_to(base_path))
        
        # Calculate checksum
        checksum = self._calculate_checksum(file_path)
        
        # Detect MIME type
        mime_type, _ = mimetypes.guess_type(str(file_path))
        if not mime_type:
            mime_type = 'application/octet-stream'
        
        return FileInfo(
            path=file_path,
            size=stat.st_size,
            mtime=stat.st_mtime,
            checksum=checksum,
            mime_type=mime_type,
            relative_path=relative_path
        )
    
    def _calculate_checksum(self, file_path: Path) -> str:
        """Calculate SHA-256 checksum of file"""
        sha256_hash = hashlib.sha256()
        try:
            with open(file_path, "rb") as f:
                for chunk in iter(lambda: f.read(4096), b""):
                    sha256_hash.update(chunk)
            return sha256_hash.hexdigest()
        except Exception:
            return ""

class FileProcessor:
    """File processing utilities for compression and encryption"""
    
    @staticmethod
    def should_compress(file_info: FileInfo, threshold: int = 1024) -> bool:
        """Determine if file should be compressed"""
        # Don't compress already compressed files
        compressed_extensions = {'.zip', '.gz', '.bz2', '.xz', '.7z', '.rar', 
                               '.jpg', '.jpeg', '.png', '.gif', '.mp3', '.mp4', '.avi'}
        
        if file_info.path.suffix.lower() in compressed_extensions:
            return False
        
        # Compress if file is larger than threshold
        return file_info.size > threshold
    
    @staticmethod
    def compress_file(file_info: FileInfo, level: int = 6) -> bytes:
        """Compress file data"""
        try:
            with open(file_info.path, 'rb') as f:
                data = f.read()
            
            compressed = gzip.compress(data, compresslevel=level)
            return compressed
        except Exception:
            return b""
    
    @staticmethod
    def encrypt_data(data: bytes, key: str) -> bytes:
        """Simple encryption using XOR (for demonstration - use proper encryption in production)"""
        if not key:
            return data
        
        key_bytes = key.encode('utf-8')
        encrypted = bytearray()
        
        for i, byte in enumerate(data):
            encrypted.append(byte ^ key_bytes[i % len(key_bytes)])
        
        return bytes(encrypted)

class ResumeManager:
    """Manages resume state for large file uploads"""
    
    def __init__(self, resume_dir: str = None):
        if resume_dir is None:
            resume_dir = Path.home() / '.config' / 'file_uploader' / 'resume'
        
        self.resume_dir = Path(resume_dir)
        self.resume_dir.mkdir(parents=True, exist_ok=True)
    
    def get_resume_file(self, file_info: FileInfo) -> Path:
        """Get resume state file path for a file"""
        resume_hash = hashlib.md5(f"{file_info.path}:{file_info.size}:{file_info.mtime}".encode()).hexdigest()
        return self.resume_dir / f"{resume_hash}.json"
    
    def save_resume_state(self, file_info: FileInfo, uploaded_bytes: int, chunk_size: int):
        """Save resume state"""
        resume_file = self.get_resume_file(file_info)
        state = {
            'file_path': str(file_info.path),
            'file_size': file_info.size,
            'file_mtime': file_info.mtime,
            'uploaded_bytes': uploaded_bytes,
            'chunk_size': chunk_size,
            'timestamp': time.time()
        }
        
        try:
            with open(resume_file, 'w') as f:
                json.dump(state, f)
        except Exception:
            pass
    
    def load_resume_state(self, file_info: FileInfo) -> Tuple[int, int]:
        """Load resume state and return (uploaded_bytes, chunk_size)"""
        resume_file = self.get_resume_file(file_info)
        
        if not resume_file.exists():
            return 0, 0
        
        try:
            with open(resume_file, 'r') as f:
                state = json.load(f)
            
            # Verify file hasn't changed
            if (state.get('file_size') == file_info.size and 
                state.get('file_mtime') == file_info.mtime):
                return state.get('uploaded_bytes', 0), state.get('chunk_size', 0)
            else:
                # File changed, remove resume state
                resume_file.unlink()
                return 0, 0
        except Exception:
            return 0, 0
    
    def clear_resume_state(self, file_info: FileInfo):
        """Clear resume state for a file"""
        resume_file = self.get_resume_file(file_info)
        try:
            if resume_file.exists():
                resume_file.unlink()
        except Exception:
            pass
    
    def cleanup_old_resume_files(self, max_age_hours: int = 24):
        """Clean up old resume files"""
        cutoff_time = time.time() - (max_age_hours * 3600)
        
        try:
            for resume_file in self.resume_dir.glob('*.json'):
                if resume_file.stat().st_mtime < cutoff_time:
                    resume_file.unlink()
        except Exception:
            pass

class UploadManager:
    """Manages file uploads with progress tracking and error handling"""
    
    def __init__(self, config: Dict[str, Any], logger: Logger):
        self.config = config
        self.logger = logger
        self.stats = UploadStats()
        self.queue = Queue()
        self.results = {}
        self.lock = threading.Lock()
        self.resume_manager = ResumeManager()
        
    def upload_file(self, file_info: FileInfo, progress_callback: Callable = None) -> Dict[str, Any]:
        """Upload a single file with resume capability"""
        result = {
            'success': False,
            'error': None,
            'response': None,
            'file_info': file_info
        }
        
        try:
            # Check if resume is enabled and file is large enough
            resume_enabled = self.config.get('resume_enabled', True)
            resume_threshold = self.config.get('resume_threshold', 10 * 1024 * 1024)  # 10MB
            
            if resume_enabled and file_info.size > resume_threshold:
                return self._upload_file_resumable(file_info, progress_callback)
            else:
                return self._upload_file_simple(file_info, progress_callback)
                
        except Exception as e:
            result['error'] = str(e)
            self.logger.error("Upload error for %s: %s", file_info.relative_path, str(e))
            return result
    
    def _upload_file_simple(self, file_info: FileInfo, progress_callback: Callable = None) -> Dict[str, Any]:
        """Upload file in a single request"""
        result = {
            'success': False,
            'error': None,
            'response': None,
            'file_info': file_info
        }
        
        # Prepare file data
        file_data = self._prepare_file_data(file_info)
        
        # Create multipart form data
        form_data, boundary = self._create_form_data(file_info, file_data)
        
        # Make request
        response = self._make_request(form_data, boundary)
        
        # Parse response
        result.update(self._parse_response(response, file_info))
        
        if result['success']:
            self.logger.info("Uploaded: %s", file_info.relative_path)
        else:
            self.logger.error("Failed to upload %s: %s", file_info.relative_path, result['error'])
        
        # Update progress
        if progress_callback:
            progress_callback(1)
        
        return result
    
    def _upload_file_resumable(self, file_info: FileInfo, progress_callback: Callable = None) -> Dict[str, Any]:
        """Upload file with resume capability"""
        result = {
            'success': False,
            'error': None,
            'response': None,
            'file_info': file_info
        }
        
        # Load resume state
        uploaded_bytes, chunk_size = self.resume_manager.load_resume_state(file_info)
        
        if uploaded_bytes > 0:
            self.logger.info("Resuming upload of %s from %d bytes", file_info.relative_path, uploaded_bytes)
        
        # Upload in chunks
        total_uploaded = uploaded_bytes
        chunk_size = self.config.get('chunk_size', 8192)
        
        try:
            with open(file_info.path, 'rb') as f:
                # Seek to resume position
                f.seek(uploaded_bytes)
                
                while total_uploaded < file_info.size:
                    # Read chunk
                    chunk = f.read(chunk_size)
                    if not chunk:
                        break
                    
                    # Create form data for chunk
                    chunk_data, chunk_boundary = self._create_chunk_form_data(file_info, chunk, total_uploaded, file_info.size)
                    
                    # Make request
                    response = self._make_request(chunk_data, chunk_boundary)
                    
                    # Parse response
                    chunk_result = self._parse_response(response, file_info)
                    
                    if not chunk_result['success']:
                        result['error'] = chunk_result['error']
                        break
                    
                    # Update progress
                    total_uploaded += len(chunk)
                    
                    # Save resume state
                    self.resume_manager.save_resume_state(file_info, total_uploaded, chunk_size)
                    
                    # Update progress callback
                    if progress_callback:
                        progress = total_uploaded / file_info.size
                        progress_callback(progress)
                    
                    self.logger.debug("Uploaded chunk: %d/%d bytes (%.1f%%)", 
                                    total_uploaded, file_info.size, (total_uploaded / file_info.size) * 100)
            
            # Check if upload completed successfully
            if total_uploaded >= file_info.size:
                result['success'] = True
                result['response'] = chunk_result.get('response')
                
                # Clear resume state
                self.resume_manager.clear_resume_state(file_info)
                
                self.logger.info("Uploaded: %s", file_info.relative_path)
            else:
                result['error'] = f"Upload incomplete: {total_uploaded}/{file_info.size} bytes"
                self.logger.error("Failed to upload %s: %s", file_info.relative_path, result['error'])
        
        except Exception as e:
            result['error'] = str(e)
            self.logger.error("Resumable upload error for %s: %s", file_info.relative_path, str(e))
        
        return result
    
    def _create_chunk_form_data(self, file_info: FileInfo, chunk: bytes, offset: int, total_size: int) -> tuple[bytes, str]:
        """Create form data for a chunk upload"""
        boundary = f"----WebKitFormBoundary{secrets.token_hex(16)}"
        
        form_parts = []
        
        # Chunk data
        form_parts.append(f'--{boundary}')
        form_parts.append(f'Content-Disposition: form-data; name="chunk"')
        form_parts.append('Content-Type: application/octet-stream')
        form_parts.append('')
        form_parts.append('')  # Placeholder for chunk data
        
        # File info
        form_parts.append(f'--{boundary}')
        form_parts.append(f'Content-Disposition: form-data; name="filename"')
        form_parts.append('')
        form_parts.append(file_info.path.name)
        
        form_parts.append(f'--{boundary}')
        form_parts.append(f'Content-Disposition: form-data; name="offset"')
        form_parts.append('')
        form_parts.append(str(offset))
        
        form_parts.append(f'--{boundary}')
        form_parts.append(f'Content-Disposition: form-data; name="total_size"')
        form_parts.append('')
        form_parts.append(str(total_size))
        
        # Security key
        form_parts.append(f'--{boundary}')
        form_parts.append(f'Content-Disposition: form-data; name="key"')
        form_parts.append('')
        form_parts.append(str(self.config['key']))
        
        # Subdirectory
        if file_info.subdir:
            form_parts.append(f'--{boundary}')
            form_parts.append(f'Content-Disposition: form-data; name="subdir"')
            form_parts.append('')
            form_parts.append(file_info.subdir)
        
        # Close boundary
        form_parts.append(f'--{boundary}--')
        
        # Build form data
        form_data = b'\r\n'.join([part.encode('utf-8') for part in form_parts])
        
        # Insert chunk data
        chunk_pos = form_data.find(b'\r\n\r\n\r\n')
        if chunk_pos != -1:
            form_data = form_data[:chunk_pos + 4] + chunk + form_data[chunk_pos + 4:]
        
        return form_data, boundary
    
    def _prepare_file_data(self, file_info: FileInfo) -> bytes:
        """Prepare file data for upload (compression/encryption)"""
        try:
            with open(file_info.path, 'rb') as f:
                data = f.read()
            
            # Apply compression if needed
            if FileProcessor.should_compress(file_info, self.config.get('compress_threshold', 1024)):
                compressed = FileProcessor.compress_file(file_info, self.config.get('compression_level', 6))
                if compressed and len(compressed) < len(data):
                    file_info.compressed = True
                    data = compressed
            
            # Apply encryption if enabled
            if self.config.get('encryption_enabled', False):
                key = self.config.get('encryption_key', '')
                if key:
                    encrypted = FileProcessor.encrypt_data(data, key)
                    file_info.encrypted = True
                    data = encrypted
            
            return data
            
        except Exception as e:
            self.logger.error("Error preparing file data: %s", str(e))
            return b""
    
    def _create_form_data(self, file_info: FileInfo, file_data: bytes) -> tuple[bytes, str]:
        """Create multipart form data"""
        boundary = f"----WebKitFormBoundary{secrets.token_hex(16)}"
        
        form_parts = []
        
        # File field
        filename = file_info.path.name
        if file_info.compressed:
            filename += '.gz'
        
        form_parts.append(f'--{boundary}')
        form_parts.append(f'Content-Disposition: form-data; name="file"; filename="{filename}"')
        form_parts.append(f'Content-Type: {file_info.mime_type}')
        form_parts.append('')
        form_parts.append('')  # Placeholder for file data
        
        # Security key
        form_parts.append(f'--{boundary}')
        form_parts.append(f'Content-Disposition: form-data; name="key"')
        form_parts.append('')
        form_parts.append(str(self.config['key']))
        
        # Subdirectory
        if file_info.subdir:
            form_parts.append(f'--{boundary}')
            form_parts.append(f'Content-Disposition: form-data; name="subdir"')
            form_parts.append('')
            form_parts.append(file_info.subdir)
        
        # Close boundary
        form_parts.append(f'--{boundary}--')
        
        # Build form data
        form_data = b'\r\n'.join([part.encode('utf-8') for part in form_parts])
        
        # Insert file data
        file_data_pos = form_data.find(b'\r\n\r\n\r\n')
        if file_data_pos != -1:
            form_data = form_data[:file_data_pos + 4] + file_data + form_data[file_data_pos + 4:]
        
        return form_data, boundary
    
    def _make_request(self, form_data: bytes, boundary: str) -> str:
        """Make HTTP request"""
        url = self.config['url']
        timeout = self.config.get('timeout', 30)
        
        # Debug logging
        self.logger.debug("Making request to: %s", url)
        self.logger.debug("Using key: %s", self.config.get('key', 'NOT SET'))
        
        # Parse URL
        parsed = urllib.parse.urlparse(url)
        
        # Create request
        req = urllib.request.Request(
            url,
            data=form_data,
            headers={
                'Content-Type': 'multipart/form-data; boundary=' + boundary,
                'User-Agent': self.config.get('user_agent', f'{APP_NAME}/{VERSION}')
            }
        )
        
        # Make request
        try:
            with urllib.request.urlopen(req, timeout=timeout) as response:
                return response.read().decode('utf-8')
        except urllib.error.HTTPError as e:
            error_body = e.read().decode('utf-8')
            raise Exception(f"HTTP {e.code}: {error_body}")
        except urllib.error.URLError as e:
            raise Exception(f"URL error: {str(e)}")
    
    def _parse_response(self, response: str, file_info: FileInfo) -> Dict[str, Any]:
        """Parse server response"""
        try:
            data = json.loads(response)
            return {
                'success': data.get('success', False),
                'error': data.get('message', 'Unknown error') if not data.get('success', False) else None,
                'response': data
            }
        except json.JSONDecodeError:
            return {
                'success': False,
                'error': f"Invalid JSON response: {response[:200]}",
                'response': response
            }

def main():
    """Main entry point"""
    parser = argparse.ArgumentParser(
        description=f'{APP_NAME} v{VERSION}',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  %(prog)s file.txt                           # Upload single file
  %(prog)s -i                                 # Interactive mode
  %(prog)s -d /path/to/dir                    # Upload directory
  %(prog)s -p production file.txt             # Use production profile
  %(prog)s file.txt --subdir memes/developer  # Upload to specific subdirectory
  %(prog)s --config-url http://...            # Override URL
  %(prog)s --parallel 8                       # Use 8 parallel uploads
  %(prog)s --compress --encrypt               # Enable compression and encryption
        """
    )
    
    # Input options
    parser.add_argument('files', nargs='*', help='Files or directories to upload')
    parser.add_argument('-i', '--interactive', action='store_true', help='Interactive mode')
    parser.add_argument('-d', '--directory', help='Upload directory contents')
    parser.add_argument('-r', '--recursive', action='store_true', help='Recursive directory upload')
    
    # Configuration options
    parser.add_argument('-p', '--profile', default='default', help='Configuration profile to use')
    parser.add_argument('--config-url', help='Override upload URL')
    parser.add_argument('--config-key', help='Override security key')
    parser.add_argument('--list-profiles', action='store_true', help='List available profiles')
    parser.add_argument('--save-profile', help='Save current settings as profile')
    
    # Upload options
    parser.add_argument('--subdir', help='Target subdirectory on server')
    parser.add_argument('--upload-path', dest='subdir', help='Target subdirectory on server (alias for --subdir)')
    parser.add_argument('--parallel', type=int, default=4, help='Number of parallel uploads')
    parser.add_argument('--chunk-size', type=int, default=8192, help='Chunk size for uploads')
    parser.add_argument('--timeout', type=int, default=30, help='Request timeout in seconds')
    parser.add_argument('--retry', type=int, default=3, help='Number of retry attempts')
    
    # Processing options
    parser.add_argument('--compress', action='store_true', help='Enable file compression')
    parser.add_argument('--encrypt', action='store_true', help='Enable file encryption')
    parser.add_argument('--encryption-key', help='Encryption key (will prompt if not provided)')
    parser.add_argument('--compression-level', type=int, default=6, help='Compression level (1-9)')
    
    # Filtering options
    parser.add_argument('--include', help='Include pattern (e.g., "*.txt,*.pdf")')
    parser.add_argument('--exclude', help='Exclude pattern (e.g., "*.tmp,*.log")')
    parser.add_argument('--max-size', type=int, help='Maximum file size in bytes')
    parser.add_argument('--max-depth', type=int, default=10, help='Maximum directory depth')
    
    # Output options
    parser.add_argument('-v', '--verbose', action='store_true', help='Verbose output')
    parser.add_argument('--quiet', action='store_true', help='Quiet mode (minimal output)')
    parser.add_argument('--log-file', help='Log file path')
    parser.add_argument('--no-progress', action='store_true', help='Disable progress bars')
    
    # Other options
    parser.add_argument('--version', action='version', version=f'{APP_NAME} v{VERSION}')
    parser.add_argument('--dry-run', action='store_true', help='Show what would be uploaded without uploading')
    
    args = parser.parse_args()
    
    # Initialize logger
    log_level = 'DEBUG' if args.verbose else ('ERROR' if args.quiet else 'INFO')
    logger = Logger(log_level, args.log_file)
    
    try:
        # Load configuration
        config_manager = ConfigManager()
        
        if args.list_profiles:
            profiles = config_manager.list_profiles()
            print(f"Available profiles: {', '.join(profiles)}")
            return 0
        
        config = config_manager.load_config(args.profile)
        
        # Apply command line overrides
        if args.config_url:
            config['url'] = args.config_url
        if args.config_key:
            config['key'] = args.config_key
        if args.parallel:
            config['max_concurrent'] = args.parallel
        if args.chunk_size:
            config['chunk_size'] = args.chunk_size
        if args.timeout:
            config['timeout'] = args.timeout
        if args.retry:
            config['retry_attempts'] = args.retry
        if args.compress:
            config['compression_enabled'] = True
        if args.encrypt:
            config['encryption_enabled'] = True
        if args.encryption_key:
            config['encryption_key'] = args.encryption_key
        if args.compression_level:
            config['compression_level'] = args.compression_level
        if args.max_size:
            config['max_file_size'] = args.max_size
        if args.max_depth:
            config['max_depth'] = args.max_depth
        if args.include:
            config['include_patterns'] = args.include
        if args.exclude:
            config['exclude_patterns'] = args.exclude
        
        # Save profile if requested
        if args.save_profile:
            config_manager.save_config(config, args.save_profile)
            logger.info("Saved configuration as profile: %s", args.save_profile)
            return 0
        
        # Validate required configuration
        if not config.get('url'):
            logger.error("Upload URL not configured")
            return 1
        
        if not config.get('key'):
            logger.error("Security key not configured")
            return 1
        
        # Interactive mode
        if args.interactive:
            return interactive_mode(config, logger)
        
        # Command line mode
        return command_line_mode(args, config, logger)
        
    except KeyboardInterrupt:
        logger.info("Operation cancelled by user")
        return 1
    except Exception as e:
        logger.error("Unexpected error: %s", str(e))
        return 1

def interactive_mode(config: Dict[str, Any], logger: Logger) -> int:
    """Interactive mode implementation"""
    print(f"{Colors.BOLD}{Colors.CYAN}{APP_NAME} - Interactive Mode{Colors.END}")
    print("=" * 50)
    
    try:
        # Get current directory
        current_dir = Path.cwd()
        
        while True:
            print(f"\n{Colors.BOLD}Current Directory: {Colors.END}{current_dir}")
            print(f"{Colors.CYAN}Commands:{Colors.END}")
            print("  [1] Browse files and directories")
            print("  [2] Select files for upload")
            print("  [3] Upload queue management")
            print("  [4] Configuration settings")
            print("  [5] Start upload")
            print("  [6] Exit")
            
            try:
                choice = input(f"\n{Colors.YELLOW}Enter your choice (1-6): {Colors.END}").strip()
            except (KeyboardInterrupt, EOFError):
                print(f"\n{Colors.YELLOW}Exiting...{Colors.END}")
                return 0
            
            if choice == '1':
                current_dir = browse_directory(current_dir)
            elif choice == '2':
                selected_files = select_files(current_dir, config, logger)
                if selected_files:
                    print(f"{Colors.GREEN}Selected {len(selected_files)} files{Colors.END}")
            elif choice == '3':
                manage_upload_queue()
            elif choice == '4':
                config = configure_settings(config)
            elif choice == '5':
                return start_interactive_upload(config, logger)
            elif choice == '6':
                print(f"{Colors.YELLOW}Exiting...{Colors.END}")
                return 0
            else:
                print(f"{Colors.RED}Invalid choice. Please enter 1-6.{Colors.END}")
    
    except KeyboardInterrupt:
        print(f"\n{Colors.YELLOW}Exiting...{Colors.END}")
        return 0

def browse_directory(current_dir: Path) -> Path:
    """Interactive directory browser"""
    try:
        # Get directory contents
        items = []
        for item in sorted(current_dir.iterdir(), key=lambda x: (x.is_file(), x.name.lower())):
            items.append(item)
        
        if not items:
            print(f"{Colors.YELLOW}Directory is empty{Colors.END}")
            return current_dir
        
        # Display items
        print(f"\n{Colors.BOLD}Contents of {current_dir}:{Colors.END}")
        print(f"{Colors.CYAN}{'Type':<6} {'Size':<12} {'Name'}{Colors.END}")
        print("-" * 50)
        
        for i, item in enumerate(items, 1):
            if item.is_dir():
                item_type = "DIR"
                size = ""
                color = Colors.BLUE
            else:
                item_type = "FILE"
                try:
                    size = f"{item.stat().st_size:,} bytes"
                except:
                    size = "? bytes"
                color = Colors.GREEN
            
            print(f"{color}{i:<6}{Colors.END} {size:<12} {item.name}")
        
        # Get user selection
        try:
            choice = input(f"\n{Colors.YELLOW}Enter number to select (0 to go up, Enter to stay): {Colors.END}").strip()
        except (KeyboardInterrupt, EOFError):
            return current_dir
        
        if choice == '':
            return current_dir
        elif choice == '0':
            # Go up one directory
            if current_dir.parent != current_dir:
                return current_dir.parent
            else:
                print(f"{Colors.YELLOW}Already at root directory{Colors.END}")
                return current_dir
        else:
            try:
                index = int(choice) - 1
                if 0 <= index < len(items):
                    selected_item = items[index]
                    if selected_item.is_dir():
                        return selected_item
                    else:
                        print(f"{Colors.YELLOW}Selected file: {selected_item.name}{Colors.END}")
                        return current_dir
                else:
                    print(f"{Colors.RED}Invalid selection{Colors.END}")
                    return current_dir
            except ValueError:
                print(f"{Colors.RED}Invalid input{Colors.END}")
                return current_dir
    
    except PermissionError:
        print(f"{Colors.RED}Permission denied to access directory{Colors.END}")
        return current_dir

def select_files(current_dir: Path, config: Dict[str, Any], logger: Logger) -> List[FileInfo]:
    """Interactive file selection"""
    print(f"\n{Colors.BOLD}File Selection Mode{Colors.END}")
    print(f"Current directory: {current_dir}")
    
    # Scan for files
    validator = FileValidator(config)
    all_files = validator.scan_directory(current_dir)
    
    if not all_files:
        print(f"{Colors.YELLOW}No valid files found in current directory{Colors.END}")
        return []
    
    # Display files with selection
    selected_files = []
    selected_indices = set()
    
    while True:
        print(f"\n{Colors.CYAN}Available files ({len(all_files)} total, {len(selected_files)} selected):{Colors.END}")
        print(f"{Colors.BOLD}{'Sel':<4} {'Size':<12} {'Name'}{Colors.END}")
        print("-" * 60)
        
        for i, file_info in enumerate(all_files, 1):
            selected = "✓" if i in selected_indices else " "
            size = f"{file_info.size:,} bytes"
            print(f"{Colors.GREEN}{selected:<4}{Colors.END} {size:<12} {file_info.relative_path}")
        
        print(f"\n{Colors.YELLOW}Commands:{Colors.END}")
        print("  [number] - Toggle file selection")
        print("  [a] - Select all files")
        print("  [n] - Select none")
        print("  [f] - Filter files by pattern")
        print("  [d] - Done with selection")
        
        try:
            choice = input(f"\n{Colors.YELLOW}Enter command: {Colors.END}").strip().lower()
        except (KeyboardInterrupt, EOFError):
            return selected_files
        
        if choice == 'd':
            break
        elif choice == 'a':
            selected_indices = set(range(1, len(all_files) + 1))
            selected_files = all_files.copy()
        elif choice == 'n':
            selected_indices.clear()
            selected_files.clear()
        elif choice == 'f':
            filter_files(all_files, selected_indices)
        else:
            try:
                index = int(choice)
                if 1 <= index <= len(all_files):
                    if index in selected_indices:
                        selected_indices.remove(index)
                        selected_files = [all_files[i-1] for i in selected_indices]
                    else:
                        selected_indices.add(index)
                        selected_files = [all_files[i-1] for i in selected_indices]
                else:
                    print(f"{Colors.RED}Invalid file number{Colors.END}")
            except ValueError:
                print(f"{Colors.RED}Invalid command{Colors.END}")
    
    return selected_files

def filter_files(all_files: List[FileInfo], selected_indices: set):
    """Filter files by pattern"""
    try:
        pattern = input(f"{Colors.YELLOW}Enter filter pattern (e.g., *.txt): {Colors.END}").strip()
        if not pattern:
            return
        
        filtered_count = 0
        for i, file_info in enumerate(all_files, 1):
            if fnmatch.fnmatch(file_info.relative_path, pattern):
                selected_indices.add(i)
                filtered_count += 1
        
        print(f"{Colors.GREEN}Selected {filtered_count} files matching pattern '{pattern}'{Colors.END}")
    
    except (KeyboardInterrupt, EOFError):
        pass

class UploadQueue:
    """Upload queue management with persistence"""
    
    def __init__(self, queue_file: str = None):
        if queue_file is None:
            queue_file = Path.home() / '.config' / 'file_uploader' / 'queue.json'
        
        self.queue_file = Path(queue_file)
        self.queue_file.parent.mkdir(parents=True, exist_ok=True)
        self.queue: List[Dict[str, Any]] = []
        self.load_queue()
    
    def load_queue(self):
        """Load queue from file"""
        try:
            if self.queue_file.exists():
                with open(self.queue_file, 'r') as f:
                    data = json.load(f)
                    self.queue = data.get('queue', [])
        except Exception:
            self.queue = []
    
    def save_queue(self):
        """Save queue to file"""
        try:
            with open(self.queue_file, 'w') as f:
                json.dump({'queue': self.queue, 'timestamp': time.time()}, f, indent=2)
        except Exception:
            pass
    
    def add_file(self, file_info: FileInfo, config: Dict[str, Any]):
        """Add file to queue"""
        queue_item = {
            'file_path': str(file_info.path),
            'relative_path': file_info.relative_path,
            'size': file_info.size,
            'checksum': file_info.checksum,
            'subdir': file_info.subdir,
            'config': config,
            'status': 'pending',
            'added_at': time.time(),
            'attempts': 0,
            'last_error': None
        }
        self.queue.append(queue_item)
        self.save_queue()
    
    def remove_file(self, index: int):
        """Remove file from queue"""
        if 0 <= index < len(self.queue):
            self.queue.pop(index)
            self.save_queue()
    
    def clear_queue(self):
        """Clear entire queue"""
        self.queue.clear()
        self.save_queue()
    
    def get_pending_files(self) -> List[Dict[str, Any]]:
        """Get all pending files"""
        return [item for item in self.queue if item['status'] == 'pending']
    
    def update_status(self, index: int, status: str, error: str = None):
        """Update file status"""
        if 0 <= index < len(self.queue):
            self.queue[index]['status'] = status
            if error:
                self.queue[index]['last_error'] = error
            self.queue[index]['attempts'] += 1
            self.save_queue()

def manage_upload_queue():
    """Manage upload queue"""
    queue = UploadQueue()
    
    while True:
        print(f"\n{Colors.BOLD}Upload Queue Management{Colors.END}")
        print(f"Queue contains {len(queue.queue)} files")
        
        if queue.queue:
            pending = len(queue.get_pending_files())
            completed = len([f for f in queue.queue if f['status'] == 'completed'])
            failed = len([f for f in queue.queue if f['status'] == 'failed'])
            
            print(f"  Pending: {pending}")
            print(f"  Completed: {completed}")
            print(f"  Failed: {failed}")
            
            print(f"\n{Colors.YELLOW}Commands:{Colors.END}")
            print("  [1] View queue contents")
            print("  [2] Remove file from queue")
            print("  [3] Clear completed files")
            print("  [4] Clear failed files")
            print("  [5] Clear entire queue")
            print("  [6] Retry failed files")
            print("  [7] Back to main menu")
        else:
            print(f"{Colors.YELLOW}Queue is empty{Colors.END}")
            print(f"\n{Colors.YELLOW}Commands:{Colors.END}")
            print("  [1] Back to main menu")
        
        try:
            choice = input(f"\n{Colors.YELLOW}Enter choice: {Colors.END}").strip()
        except (KeyboardInterrupt, EOFError):
            return
        
        if choice == '1':
            if queue.queue:
                view_queue_contents(queue)
            else:
                return
        elif choice == '2' and queue.queue:
            remove_file_from_queue(queue)
        elif choice == '3' and queue.queue:
            queue.queue = [f for f in queue.queue if f['status'] != 'completed']
            queue.save_queue()
            print(f"{Colors.GREEN}Cleared completed files{Colors.END}")
        elif choice == '4' and queue.queue:
            queue.queue = [f for f in queue.queue if f['status'] != 'failed']
            queue.save_queue()
            print(f"{Colors.GREEN}Cleared failed files{Colors.END}")
        elif choice == '5' and queue.queue:
            try:
                confirm = input(f"{Colors.RED}Clear entire queue? (y/N): {Colors.END}").strip().lower()
                if confirm == 'y':
                    queue.clear_queue()
                    print(f"{Colors.GREEN}Queue cleared{Colors.END}")
            except (KeyboardInterrupt, EOFError):
                pass
        elif choice == '6' and queue.queue:
            retry_failed_files(queue)
        elif choice == '7' or (choice == '1' and not queue.queue):
            return
        else:
            print(f"{Colors.RED}Invalid choice{Colors.END}")

def view_queue_contents(queue: UploadQueue):
    """View queue contents"""
    print(f"\n{Colors.BOLD}Queue Contents:{Colors.END}")
    print(f"{'#':<3} {'Status':<10} {'Size':<12} {'File'}{Colors.END}")
    print("-" * 80)
    
    for i, item in enumerate(queue.queue, 1):
        status = item['status']
        if status == 'pending':
            status_color = Colors.YELLOW
        elif status == 'completed':
            status_color = Colors.GREEN
        elif status == 'failed':
            status_color = Colors.RED
        else:
            status_color = Colors.WHITE
        
        size = f"{item['size']:,} bytes"
        print(f"{i:<3} {status_color}{status:<10}{Colors.END} {size:<12} {item['relative_path']}")
        
        if item['last_error']:
            print(f"     {Colors.RED}Error: {item['last_error']}{Colors.END}")

def remove_file_from_queue(queue: UploadQueue):
    """Remove file from queue"""
    try:
        index = int(input(f"{Colors.YELLOW}Enter file number to remove (1-{len(queue.queue)}): {Colors.END}").strip()) - 1
        if 0 <= index < len(queue.queue):
            file_name = queue.queue[index]['relative_path']
            queue.remove_file(index)
            print(f"{Colors.GREEN}Removed {file_name} from queue{Colors.END}")
        else:
            print(f"{Colors.RED}Invalid file number{Colors.END}")
    except ValueError:
        print(f"{Colors.RED}Invalid input{Colors.END}")

def retry_failed_files(queue: UploadQueue):
    """Retry failed files"""
    failed_files = [f for f in queue.queue if f['status'] == 'failed']
    if not failed_files:
        print(f"{Colors.YELLOW}No failed files to retry{Colors.END}")
        return
    
    retry_count = 0
    for item in failed_files:
        item['status'] = 'pending'
        item['last_error'] = None
        retry_count += 1
    
    queue.save_queue()
    print(f"{Colors.GREEN}Marked {retry_count} failed files for retry{Colors.END}")

def configure_settings(config: Dict[str, Any]) -> Dict[str, Any]:
    """Interactive configuration"""
    print(f"\n{Colors.BOLD}Configuration Settings{Colors.END}")
    
    while True:
        print(f"\n{Colors.CYAN}Current Settings:{Colors.END}")
        print(f"  URL: {config.get('url', 'Not set')}")
        print(f"  Key: {'*' * len(config.get('key', '')) if config.get('key') else 'Not set'}")
        print(f"  Max file size: {config.get('max_file_size', 0) // (1024*1024)} MB")
        print(f"  Max concurrent: {config.get('max_concurrent', 4)}")
        print(f"  Timeout: {config.get('timeout', 30)}s")
        print(f"  Compression: {'Enabled' if config.get('compression_enabled') else 'Disabled'}")
        print(f"  Encryption: {'Enabled' if config.get('encryption_enabled') else 'Disabled'}")
        
        print(f"\n{Colors.YELLOW}Commands:{Colors.END}")
        print("  [1] Change URL")
        print("  [2] Change security key")
        print("  [3] Change max file size")
        print("  [4] Change concurrent uploads")
        print("  [5] Toggle compression")
        print("  [6] Toggle encryption")
        print("  [7] Back to main menu")
        
        try:
            choice = input(f"\n{Colors.YELLOW}Enter choice (1-7): {Colors.END}").strip()
        except (KeyboardInterrupt, EOFError):
            return config
        
        if choice == '1':
            new_url = input(f"Enter new URL [{config.get('url', '')}]: ").strip()
            if new_url:
                config['url'] = new_url
        elif choice == '2':
            new_key = input(f"Enter new security key: ").strip()
            if new_key:
                config['key'] = new_key
        elif choice == '3':
            try:
                new_size = int(input(f"Enter max file size in MB [{config.get('max_file_size', 0) // (1024*1024)}]: ").strip())
                config['max_file_size'] = new_size * 1024 * 1024
            except ValueError:
                print(f"{Colors.RED}Invalid size{Colors.END}")
        elif choice == '4':
            try:
                new_parallel = int(input(f"Enter max concurrent uploads [{config.get('max_concurrent', 4)}]: ").strip())
                if 1 <= new_parallel <= 16:
                    config['max_concurrent'] = new_parallel
                else:
                    print(f"{Colors.RED}Must be between 1 and 16{Colors.END}")
            except ValueError:
                print(f"{Colors.RED}Invalid number{Colors.END}")
        elif choice == '5':
            config['compression_enabled'] = not config.get('compression_enabled', False)
        elif choice == '6':
            config['encryption_enabled'] = not config.get('encryption_enabled', False)
        elif choice == '7':
            break
        else:
            print(f"{Colors.RED}Invalid choice{Colors.END}")
    
    return config

def start_interactive_upload(config: Dict[str, Any], logger: Logger) -> int:
    """Start upload from interactive mode"""
    # This would integrate with the selected files from the interactive session
    # For now, just show a message
    print(f"{Colors.YELLOW}Interactive upload not fully implemented yet{Colors.END}")
    print("Use command line mode for actual uploads.")
    return 0

def command_line_mode(args: argparse.Namespace, config: Dict[str, Any], logger: Logger) -> int:
    """Command line mode implementation"""
    try:
        # Determine files to upload
        files_to_upload = []
        
        if args.directory:
            # Upload directory
            dir_path = Path(args.directory)
            if not dir_path.exists() or not dir_path.is_dir():
                logger.error("Directory not found: %s", args.directory)
                return 1
            
            validator = FileValidator(config)
            files_to_upload = validator.scan_directory(dir_path)
            
        elif args.files:
            # Upload specified files
            for file_path in args.files:
                path = Path(file_path)
                if not path.exists():
                    logger.error("File not found: %s", file_path)
                    return 1
                
                if path.is_file():
                    validator = FileValidator(config)
                    valid, reason = validator.validate_file(path)
                    if valid:
                        file_info = validator._create_file_info(path, path.parent)
                        files_to_upload.append(file_info)
                    else:
                        logger.warning("Skipping %s: %s", file_path, reason)
                elif path.is_dir():
                    validator = FileValidator(config)
                    dir_files = validator.scan_directory(path)
                    files_to_upload.extend(dir_files)
        
        else:
            logger.error("No files specified. Use -h for help.")
            return 1
        
        if not files_to_upload:
            logger.warning("No valid files found to upload")
            return 1
        
        # Dry run
        if args.dry_run:
            print(f"\n{Colors.YELLOW}Dry run - would upload {len(files_to_upload)} files:{Colors.END}")
            for file_info in files_to_upload:
                print(f"  {file_info.relative_path} ({file_info.size} bytes)")
            return 0
        
        # Upload files
        logger.info("Starting upload of %d files", len(files_to_upload))
        
        upload_manager = UploadManager(config, logger)
        upload_manager.stats.total_files = len(files_to_upload)
        upload_manager.stats.start_time = time.time()
        
        # Set subdirectory for all files
        subdir = args.subdir or config.get('default_subdir', '')
        for file_info in files_to_upload:
            file_info.subdir = subdir
        
        # Create progress bar
        progress = None
        if not args.no_progress and not args.quiet:
            progress = ProgressBar(len(files_to_upload), desc="Uploading")
        
        # Upload files
        success_count = 0
        with ThreadPoolExecutor(max_workers=config['max_concurrent']) as executor:
            futures = []
            
            for file_info in files_to_upload:
                future = executor.submit(upload_manager.upload_file, file_info)
                futures.append(future)
            
            for future in as_completed(futures):
                try:
                    result = future.result()
                    if result['success']:
                        success_count += 1
                        upload_manager.stats.uploaded_bytes += result['file_info'].size
                    
                    if progress:
                        progress.update(1)
                        
                except Exception as e:
                    logger.error("Upload failed: %s", str(e))
                    if progress:
                        progress.update(1)
        
        upload_manager.stats.end_time = time.time()
        upload_manager.stats.uploaded_files = success_count
        upload_manager.stats.failed_files = len(files_to_upload) - success_count
        
        # Print summary
        if not args.quiet:
            print(f"\n{Colors.BOLD}Upload Summary:{Colors.END}")
            print(f"  Files uploaded: {Colors.GREEN}{upload_manager.stats.uploaded_files}{Colors.END}")
            print(f"  Files failed: {Colors.RED}{upload_manager.stats.failed_files}{Colors.END}")
            print(f"  Success rate: {upload_manager.stats.success_rate:.1f}%")
            print(f"  Total time: {upload_manager.stats.duration:.1f}s")
            print(f"  Upload speed: {upload_manager.stats.upload_speed / 1024:.1f} KB/s")
        
        return 0 if upload_manager.stats.failed_files == 0 else 1
        
    except Exception as e:
        logger.error("Command line mode error: %s", str(e))
        return 1

if __name__ == '__main__':
    sys.exit(main())
