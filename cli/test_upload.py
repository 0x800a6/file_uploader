#!/usr/bin/env python3
"""
Test script for the Advanced File Uploader CLI
Creates test files and demonstrates various features
"""

import os
import tempfile
import shutil
from pathlib import Path

def create_test_files():
    """Create test files for demonstration"""
    test_dir = Path("test_files")
    test_dir.mkdir(exist_ok=True)
    
    # Create various test files
    test_files = [
        ("small.txt", "This is a small text file for testing."),
        ("medium.txt", "This is a medium text file.\n" * 100),
        ("large.txt", "This is a large text file.\n" * 1000),
        ("config.json", '{"test": true, "value": 123}'),
        ("data.csv", "name,age,city\nAlice,25,NYC\nBob,30,LA\n"),
        ("image.jpg", b"fake image data" * 100),  # Binary data
    ]
    
    for filename, content in test_files:
        file_path = test_dir / filename
        
        if isinstance(content, str):
            file_path.write_text(content)
        else:
            file_path.write_bytes(content)
        
        print(f"Created: {file_path}")
    
    # Create subdirectories with files
    subdir = test_dir / "subdir"
    subdir.mkdir(exist_ok=True)
    
    (subdir / "nested.txt").write_text("This is a nested file.")
    (subdir / "another.json").write_text('{"nested": true}')
    
    print(f"Created: {subdir}/nested.txt")
    print(f"Created: {subdir}/another.json")
    
    return test_dir

def cleanup_test_files():
    """Clean up test files"""
    test_dir = Path("test_files")
    if test_dir.exists():
        shutil.rmtree(test_dir)
        print("Cleaned up test files")

def main():
    """Main test function"""
    print("Advanced File Uploader CLI - Test Setup")
    print("=" * 50)
    
    # Create test files
    test_dir = create_test_files()
    
    print(f"\nTest files created in: {test_dir.absolute()}")
    print("\nYou can now test the CLI with:")
    print(f"python cli/file_uploader.py --config-key 'test_key' --dry-run -d {test_dir}")
    print(f"python cli/file_uploader.py --config-key 'test_key' -i")
    print(f"python cli/file_uploader.py --config-key 'test_key' --include '*.txt' --exclude '*large*' -d {test_dir}")
    
    print("\nPress Enter to clean up test files...")
    input()
    
    cleanup_test_files()

if __name__ == "__main__":
    main()
