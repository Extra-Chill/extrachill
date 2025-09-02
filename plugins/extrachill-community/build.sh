#!/bin/bash

# Extra Chill Community Theme - Build Script
# Generates a clean distribution zip file for WordPress theme

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Theme configuration
THEME_MAIN_FILE="style.css"
THEME_SLUG="extrachill-community"
BUILD_DIR="dist"
TEMP_DIR="$BUILD_DIR/temp"

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

# Function to extract version from style.css
get_theme_version() {
    if [ ! -f "$THEME_MAIN_FILE" ]; then
        print_error "Main theme file '$THEME_MAIN_FILE' not found!"
        exit 1
    fi
    
    # Extract version from theme header
    VERSION=$(grep -i "Version:" "$THEME_MAIN_FILE" | head -1 | sed 's/.*Version:[ ]*\([0-9\.]*\).*/\1/')
    
    if [ -z "$VERSION" ]; then
        print_error "Could not extract version from $THEME_MAIN_FILE"
        exit 1
    fi
    
    echo "$VERSION"
}

# Function to check if rsync is available
check_rsync() {
    if ! command -v rsync &> /dev/null; then
        print_error "rsync is required but not installed. Please install rsync."
        exit 1
    fi
}

# Function to create exclude file for rsync
create_rsync_excludes() {
    local exclude_file="$1"
    
    # Read .buildignore if it exists
    if [ -f ".buildignore" ]; then
        # Convert .buildignore to rsync exclude format
        sed 's|^/||; s|/$||; /^#/d; /^$/d' .buildignore > "$exclude_file"
    else
        # Default excludes if no .buildignore file
        cat > "$exclude_file" << EOF
.git
.gitignore
.gitattributes
README.md
CLAUDE.md
next-steps.md
.claude
.vscode
.idea
*.swp
*.swo
*~
dist
build
*.zip
*.tar.gz
.DS_Store
._*
node_modules
vendor
*.log
*.tmp
*.temp
.env*
build.sh
package.json
.buildignore
tests
phpunit.xml*
.github
EOF
    fi
}

# Function to validate theme structure
validate_theme() {
    local theme_dir="$1"
    
    print_status "Validating theme structure..."
    
    # Check for required WordPress theme files
    local required_files=("style.css" "index.php")
    for file in "${required_files[@]}"; do
        if [ ! -f "$theme_dir/$file" ]; then
            print_error "Required theme file '$file' not found in build!"
            return 1
        fi
    done
    
    # Check for main theme file (functions.php)
    if [ ! -f "$theme_dir/functions.php" ]; then
        print_warning "functions.php not found in build"
    fi
    
    # Check for essential theme directories
    local essential_dirs=("css" "js" "fonts" "bbpress" "forum-features" "page-templates" "login" "extrachill-integration")
    for dir in "${essential_dirs[@]}"; do
        if [ ! -d "$theme_dir/$dir" ]; then
            print_warning "Directory '$dir' not found in build"
        fi
    done
    
    print_success "Theme structure validated"
    return 0
}

# Main build function
build_theme() {
    local version="$1"
    local zip_filename="$THEME_SLUG-v$version.zip"
    
    print_status "Starting build process for version $version"
    
    # Clean up any previous builds
    if [ -d "$BUILD_DIR" ]; then
        print_status "Cleaning previous build..."
        rm -rf "$BUILD_DIR"
    fi
    
    # Create build directories
    mkdir -p "$TEMP_DIR"
    
    # Create rsync excludes file
    local exclude_file="$TEMP_DIR/.rsync-excludes"
    create_rsync_excludes "$exclude_file"
    
    print_status "Copying theme files..."
    
    # Copy files using rsync with excludes
    rsync -av --exclude-from="$exclude_file" ./ "$TEMP_DIR/$THEME_SLUG/"
    
    # Validate the build
    if ! validate_theme "$TEMP_DIR/$THEME_SLUG"; then
        print_error "Theme validation failed"
        exit 1
    fi
    
    # Create the zip file
    print_status "Creating zip file: $zip_filename"
    cd "$TEMP_DIR"
    
    if command -v zip &> /dev/null; then
        zip -r "../$zip_filename" "$THEME_SLUG/" -q
    else
        print_error "zip command not found. Please install zip utility."
        exit 1
    fi
    
    cd - > /dev/null
    
    # Clean up temp directory
    rm -rf "$TEMP_DIR"
    
    # Get file size
    local file_size=$(ls -lh "$BUILD_DIR/$zip_filename" | awk '{print $5}')
    
    print_success "Build completed successfully!"
    print_success "Output: $BUILD_DIR/$zip_filename ($file_size)"
    
    # Show contents summary
    print_status "Archive contents:"
    unzip -l "$BUILD_DIR/$zip_filename" | head -20
    echo "..."
    echo "$(unzip -l "$BUILD_DIR/$zip_filename" | tail -1)"
}

# Main script execution
main() {
    print_status "Extra Chill Community Theme Build Script"
    print_status "======================================="
    
    # Check dependencies
    check_rsync
    
    # Get theme version
    local version
    version=$(get_theme_version)
    print_status "Theme version: $version"
    
    # Build the theme
    build_theme "$version"
    
    print_status "Build process complete!"
}

# Run the main function
main "$@"