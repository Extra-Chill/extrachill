#!/bin/bash

# ExtraChill Newsletter Plugin Build Script
# Creates production-ready ZIP package for WordPress deployment
#
# Usage: ./build.sh
# Output: dist/extrachill-newsletter.zip
#
# @package ExtraChillNewsletter
# @since 1.0.0

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Plugin information
PLUGIN_NAME="extrachill-newsletter"
PLUGIN_FILE="extrachill-newsletter.php"

echo -e "${BLUE}=== ExtraChill Newsletter Plugin Build ===${NC}"
echo "Building production package for $PLUGIN_NAME..."
echo

# Verify we're in the right directory
if [ ! -f "$PLUGIN_FILE" ]; then
    echo -e "${RED}Error: $PLUGIN_FILE not found in current directory${NC}"
    echo "Please run this script from the plugin root directory"
    exit 1
fi

# Extract version from plugin file
VERSION=$(grep -E "^\s*\*\s*Version:" "$PLUGIN_FILE" | head -1 | sed -E 's/.*Version:\s*([0-9.]+).*/\1/' | xargs)
if [ -z "$VERSION" ]; then
    echo -e "${RED}Error: Could not extract version from $PLUGIN_FILE${NC}"
    exit 1
fi

echo -e "${YELLOW}Plugin Version:${NC} $VERSION"
echo

# Build configuration
DIST_DIR="dist"
BUILD_DIR="$DIST_DIR/$PLUGIN_NAME"
ZIP_FILE="$DIST_DIR/$PLUGIN_NAME.zip"
BUILDIGNORE_FILE=".buildignore"

# Clean previous builds
echo -e "${BLUE}Cleaning previous builds...${NC}"
if [ -d "$DIST_DIR" ]; then
    rm -rf "$DIST_DIR"
fi
mkdir -p "$DIST_DIR"
echo "✓ Cleaned dist directory"

# Create build directory
echo -e "${BLUE}Creating build directory...${NC}"
mkdir -p "$BUILD_DIR"
echo "✓ Created $BUILD_DIR"

# Copy all files except those in .buildignore
echo -e "${BLUE}Copying plugin files...${NC}"
if [ -f "$BUILDIGNORE_FILE" ]; then
    echo "Using $BUILDIGNORE_FILE for exclusions"

    # Use rsync with exclude patterns if available, otherwise use find and cp
    if command -v rsync >/dev/null 2>&1; then
        # Create rsync exclude file
        EXCLUDE_FILE=$(mktemp)
        grep -v '^#' "$BUILDIGNORE_FILE" | grep -v '^$' > "$EXCLUDE_FILE"

        # Use rsync to copy files
        rsync -av --exclude-from="$EXCLUDE_FILE" --exclude="$DIST_DIR" ./ "$BUILD_DIR/"

        rm -f "$EXCLUDE_FILE"
    else
        # Fallback to manual copying with exclusions
        # Copy all files first
        cp -r . "$BUILD_DIR/"

        # Remove excluded items
        while IFS= read -r pattern; do
            if [[ ! "$pattern" =~ ^[[:space:]]*# ]] && [ -n "$pattern" ]; then
                # Remove leading/trailing whitespace
                pattern=$(echo "$pattern" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
                if [ -n "$pattern" ]; then
                    rm -rf "$BUILD_DIR/$pattern" 2>/dev/null || true
                fi
            fi
        done < "$BUILDIGNORE_FILE"

        # Always remove the dist directory
        rm -rf "$BUILD_DIR/$DIST_DIR" 2>/dev/null || true
    fi
else
    echo "No .buildignore file found, copying all files except dist/"
    cp -r . "$BUILD_DIR/"
    rm -rf "$BUILD_DIR/$DIST_DIR" 2>/dev/null || true
fi

# Count copied files
FILE_COUNT=$(find "$BUILD_DIR" -type f | wc -l)
echo "✓ Copied $FILE_COUNT files to build directory"

# Validate essential plugin files exist
echo -e "${BLUE}Validating plugin structure...${NC}"
REQUIRED_FILES=("$PLUGIN_FILE" "includes/" "templates/" "assets/")
VALIDATION_FAILED=false

for item in "${REQUIRED_FILES[@]}"; do
    if [ ! -e "$BUILD_DIR/$item" ]; then
        echo -e "${RED}✗ Missing required file/directory: $item${NC}"
        VALIDATION_FAILED=true
    else
        echo "✓ Found $item"
    fi
done

if [ "$VALIDATION_FAILED" = true ]; then
    echo -e "${RED}Build validation failed. Please check required files.${NC}"
    exit 1
fi

# Verify plugin header is present
if ! grep -q "Plugin Name:" "$BUILD_DIR/$PLUGIN_FILE"; then
    echo -e "${RED}✗ Plugin header not found in $PLUGIN_FILE${NC}"
    exit 1
fi
echo "✓ Plugin header validated"

# Check if composer.json exists and handle dependencies
if [ -f "$BUILD_DIR/composer.json" ]; then
    echo -e "${BLUE}Installing production Composer dependencies...${NC}"
    cd "$BUILD_DIR"

    if command -v composer >/dev/null 2>&1; then
        composer install --no-dev --optimize-autoloader --no-interaction --quiet
        echo "✓ Composer dependencies installed"

        # Remove composer development files
        rm -f composer.json composer.lock
    else
        echo -e "${YELLOW}Warning: Composer not found, skipping dependency installation${NC}"
    fi

    cd "$SCRIPT_DIR"
fi

# Remove any development-only files that might have been copied
echo -e "${BLUE}Cleaning development files...${NC}"
find "$BUILD_DIR" -name "*.log" -delete 2>/dev/null || true
find "$BUILD_DIR" -name ".DS_Store" -delete 2>/dev/null || true
find "$BUILD_DIR" -name "Thumbs.db" -delete 2>/dev/null || true
echo "✓ Cleaned development files"

# Create ZIP archive
echo -e "${BLUE}Creating ZIP archive...${NC}"
cd "$DIST_DIR"

if command -v zip >/dev/null 2>&1; then
    zip -r "$(basename "$ZIP_FILE")" "$PLUGIN_NAME" -q
    echo "✓ Created ZIP archive using zip command"
elif command -v 7z >/dev/null 2>&1; then
    7z a "$(basename "$ZIP_FILE")" "$PLUGIN_NAME" > /dev/null
    echo "✓ Created ZIP archive using 7z command"
else
    echo -e "${RED}Error: Neither 'zip' nor '7z' command found${NC}"
    echo "Please install zip utilities to create the archive"
    exit 1
fi

cd "$SCRIPT_DIR"

# Verify ZIP was created successfully
if [ ! -f "$ZIP_FILE" ]; then
    echo -e "${RED}Error: ZIP file was not created${NC}"
    exit 1
fi

# Get file sizes
BUILD_SIZE=$(du -sh "$BUILD_DIR" | cut -f1)
ZIP_SIZE=$(du -sh "$ZIP_FILE" | cut -f1)

echo -e "${GREEN}=== Build Completed Successfully ===${NC}"
echo
echo -e "${YELLOW}Plugin:${NC} $PLUGIN_NAME v$VERSION"
echo -e "${YELLOW}Build Directory:${NC} $BUILD_DIR ($BUILD_SIZE)"
echo -e "${YELLOW}ZIP Archive:${NC} $ZIP_FILE ($ZIP_SIZE)"
echo -e "${YELLOW}Files Packaged:${NC} $FILE_COUNT"
echo
echo -e "${GREEN}Ready for WordPress deployment!${NC}"
echo
echo "To install:"
echo "1. Upload $ZIP_FILE to WordPress admin → Plugins → Add New → Upload Plugin"
echo "2. Or extract to wp-content/plugins/ directory"
echo
echo -e "${BLUE}Build log saved to: $(pwd)/build.log${NC}"

# Save build information
cat > build.log << EOF
ExtraChill Newsletter Plugin Build Log
=====================================

Build Date: $(date)
Plugin Name: $PLUGIN_NAME
Version: $VERSION
Build Directory: $BUILD_DIR
ZIP Archive: $ZIP_FILE
Files Packaged: $FILE_COUNT
Build Size: $BUILD_SIZE
ZIP Size: $ZIP_SIZE

Build completed successfully.
EOF

exit 0