#!/bin/bash

# Extra Chill Artist Platform - Build Script
# Clean -> Install prod deps -> Copy -> Validate -> ZIP -> Restore dev deps

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Plugin configuration
PLUGIN_MAIN_FILE="extrachill-artist-platform.php"
PLUGIN_SLUG="extrachill-artist-platform"
BUILD_DIR="dist"
PROD_DIR="$BUILD_DIR/$PLUGIN_SLUG"
ZIP_FILE="$BUILD_DIR/$PLUGIN_SLUG.zip"

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

check_dependencies() {
    print_status "Checking build dependencies..."

    local missing_tools=()

    if ! command -v rsync &> /dev/null; then
        missing_tools+=("rsync")
    fi

    if ! command -v zip &> /dev/null; then
        missing_tools+=("zip")
    fi

    if ! command -v composer &> /dev/null; then
        missing_tools+=("composer")
    fi

    if [ ${#missing_tools[@]} -ne 0 ]; then
        print_error "Missing required tools: ${missing_tools[*]}"
        print_error "Please install the missing tools and try again."
        exit 1
    fi

    print_success "All build dependencies found"
}

get_plugin_version() {
    if [ ! -f "$PLUGIN_MAIN_FILE" ]; then
        print_error "Main plugin file '$PLUGIN_MAIN_FILE' not found!"
        exit 1
    fi

    VERSION=$(grep -i "Version:" "$PLUGIN_MAIN_FILE" | head -1 | sed 's/.*Version:[ ]*\([0-9\.]*\).*/\1/')

    if [ -z "$VERSION" ]; then
        print_error "Could not extract version from $PLUGIN_MAIN_FILE"
        exit 1
    fi

    echo "$VERSION"
}

# Function to clean previous builds
clean_previous_builds() {
    print_status "Cleaning previous build artifacts..."

    if [ -d "$BUILD_DIR" ]; then
        rm -rf "$BUILD_DIR"
    fi

    print_success "Previous builds cleaned"
}

# Function to install production dependencies
install_production_deps() {
    print_status "Installing production dependencies..."

    if [ -f "composer.json" ]; then
        composer install --no-dev --optimize-autoloader --no-interaction
        print_success "Production dependencies installed"
    else
        print_warning "No composer.json found, skipping Composer dependencies"
    fi
}

# Function to restore development dependencies
restore_dev_deps() {
    print_status "Restoring development dependencies..."

    if [ -f "composer.json" ]; then
        composer install --no-interaction
        print_success "Development dependencies restored"
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
EOF
    fi
}

# Function to copy files with exclusions
copy_plugin_files() {
    print_status "Copying plugin files to production directory..."

    # Create build directories
    mkdir -p "$PROD_DIR"

    # Create rsync excludes file
    local exclude_file="/tmp/.rsync-excludes-$$"
    create_rsync_excludes "$exclude_file"

    # Copy files using rsync with excludes
    rsync -av --exclude-from="$exclude_file" ./ "$PROD_DIR/"

    # Clean up exclude file
    rm -f "$exclude_file"

    print_success "Plugin files copied successfully"
}

# Function to validate plugin structure
validate_plugin_structure() {
    print_status "Validating plugin structure..."

    # Check for main plugin file
    if [ ! -f "$PROD_DIR/$PLUGIN_MAIN_FILE" ]; then
        print_error "Main plugin file not found in production build!"
        return 1
    fi

    # Check for essential directories
    local essential_dirs=("inc" "assets")
    local missing_dirs=()

    for dir in "${essential_dirs[@]}"; do
        if [ ! -d "$PROD_DIR/$dir" ]; then
            missing_dirs+=("$dir")
        fi
    done

    if [ ${#missing_dirs[@]} -ne 0 ]; then
        print_error "Essential directories missing from build: ${missing_dirs[*]}"
        return 1
    fi

    # Verify composer.lock is present for production
    if [ -f "composer.json" ] && [ ! -f "$PROD_DIR/composer.lock" ]; then
        print_error "composer.lock missing from production build!"
        return 1
    fi

    print_success "Plugin structure validation passed"
    return 0
}

# Function to create production ZIP
create_production_zip() {
    print_status "Creating production ZIP file..."

    # Remove existing ZIP if it exists
    if [ -f "$ZIP_FILE" ]; then
        rm -f "$ZIP_FILE"
    fi

    # Create ZIP from production directory
    cd "$BUILD_DIR"
    zip -r "$PLUGIN_SLUG.zip" "$PLUGIN_SLUG/" -q
    cd - > /dev/null

    # Get file size
    local file_size=$(ls -lh "$ZIP_FILE" | awk '{print $5}')

    print_success "Production ZIP created: $ZIP_FILE ($file_size)"

    # Show contents summary
    print_status "Archive contents summary:"
    unzip -l "$ZIP_FILE" | head -15
    local total_files=$(unzip -l "$ZIP_FILE" | tail -1)
    echo "..."
    echo "$total_files"
}

build_plugin() {
    local version="$1"

    print_status "Starting build process for version $version"

    clean_previous_builds
    install_production_deps
    copy_plugin_files

    if ! validate_plugin_structure; then
        print_error "Plugin validation failed"
        restore_dev_deps
        exit 1
    fi

    create_production_zip
    restore_dev_deps

    print_success "Build process completed successfully!"
    print_success "Production package: $ZIP_FILE"
    print_success "Clean production directory: $PROD_DIR"
}

# Main script execution
main() {
    print_status "Extra Chill Artist Platform - Production Build"
    print_status "============================================="

    # Check all required dependencies
    check_dependencies

    # Get plugin version
    local version
    version=$(get_plugin_version)
    print_status "Plugin version: $version"

    # Execute build process
    build_plugin "$version"

    print_status "Build process complete!"
}

# Run the main function
main "$@"