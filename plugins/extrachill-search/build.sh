#!/bin/bash

# Universal WordPress Build Script for extrachill-search Plugin
# Creates production-ready plugin package

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${BLUE}[BUILD]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Project details
PROJECT_NAME="extrachill-search"
PROJECT_VERSION="0.1.0"
PROJECT_TYPE="plugin"

print_status "Universal WordPress Build Script"
print_status "================================="

# Check for required tools
print_status "Checking build dependencies..."
command -v composer >/dev/null 2>&1 || { print_error "Composer is required but not installed."; exit 1; }
command -v zip >/dev/null 2>&1 || { print_error "Zip is required but not installed."; exit 1; }
print_success "All build dependencies found"

# Detect project type
print_status "Detecting project type..."
if [ -f "${PROJECT_NAME}.php" ]; then
    PROJECT_TYPE="plugin"
    print_success "Detected WordPress plugin: ${PROJECT_NAME}.php"
else
    print_error "No plugin file found."
    exit 1
fi

# Extract project metadata
print_status "Extracting project metadata..."
if [ -f "${PROJECT_NAME}.php" ]; then
    PROJECT_VERSION=$(grep -o "Version: [0-9]\+\.[0-9]\+\.[0-9]\+" "${PROJECT_NAME}.php" | grep -o "[0-9]\+\.[0-9]\+\.[0-9]\+" | head -1)
    if [ -z "$PROJECT_VERSION" ]; then
        PROJECT_VERSION="0.1.0"
    fi
fi
print_success "Project: ${PROJECT_NAME} v${PROJECT_VERSION}"

# Start build process
print_status "Starting build process for ${PROJECT_NAME} v${PROJECT_VERSION}"
print_status "============================================="

# Clean previous builds
print_status "Cleaning previous build artifacts..."
rm -rf build/
print_success "Previous builds cleaned"

# Install production dependencies
print_status "Installing production dependencies..."
if [ -f "composer.json" ]; then
    composer install --no-dev --optimize-autoloader
    print_success "Production dependencies installed"
else
    print_warning "No composer.json found, skipping dependency installation"
fi

# Copy project files to build directory
print_status "Copying project files to build directory..."
mkdir -p "build/${PROJECT_NAME}/"
rsync -av --exclude='build/' --exclude='.git/' --exclude='node_modules/' --exclude='vendor/' --exclude='composer.lock' --exclude='package-lock.json' --exclude='.DS_Store' --exclude='AGENTS.md' --exclude='README.md' --exclude='.buildignore' --exclude='build.sh' --exclude='phpunit.xml' --exclude='tests/' --exclude='docs/' . "build/${PROJECT_NAME}/"
print_success "Project files copied successfully"

# Validate build structure
print_status "Validating build structure..."
if [ ! -f "build/${PROJECT_NAME}/${PROJECT_NAME}.php" ]; then
    print_error "Main plugin file missing in build directory."
    exit 1
fi
print_success "Build structure validation passed"

# Create production ZIP file
print_status "Creating production ZIP file..."
cd build/
zip -r "${PROJECT_NAME}.zip" "${PROJECT_NAME}/" -q
cd ..

# Get archive info
local file_size=$(du -h "build/${PROJECT_NAME}.zip" | cut -f1)
local total_files=$(unzip -l "build/${PROJECT_NAME}.zip" | tail -1 | awk '{print $2}')

print_success "Production ZIP created: build/${PROJECT_NAME}.zip ($file_size, $total_files files)"

# Clean up intermediate directory now that ZIP is created
print_status "Cleaning up intermediate build directory..."
rm -rf "build/${PROJECT_NAME}"
print_success "Intermediate directory removed (production files are in ZIP)"

# Restore development dependencies
print_status "Restoring development dependencies..."
if [ -f "composer.json" ]; then
    composer install
    print_success "Development dependencies restored"
fi

print_success "Build process completed successfully!"
print_success "Production package: build/${PROJECT_NAME}.zip"
echo ""
print_status "Need production files? Simply unzip the archive!"

print_status "Build complete!"