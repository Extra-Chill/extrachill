#!/bin/bash

# ExtraChill Contact Plugin Build Script
# Creates production-ready ZIP package for WordPress deployment

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo -e "${BLUE}ExtraChill Contact Plugin Build Script${NC}"
echo "========================================"

# Extract version from main plugin file
PLUGIN_FILE="extrachill-contact.php"
if [ ! -f "$PLUGIN_FILE" ]; then
    echo -e "${RED}Error: Plugin file $PLUGIN_FILE not found${NC}"
    exit 1
fi

VERSION=$(grep "Version:" "$PLUGIN_FILE" | head -1 | awk -F: '{print $2}' | tr -d ' ')
if [ -z "$VERSION" ]; then
    echo -e "${RED}Error: Could not extract version from $PLUGIN_FILE${NC}"
    exit 1
fi

echo -e "${YELLOW}Building version: ${VERSION}${NC}"

# Define directories
BUILD_DIR="dist"
PLUGIN_NAME="extrachill-contact"
PLUGIN_BUILD_DIR="${BUILD_DIR}/${PLUGIN_NAME}"
ZIP_FILE="${BUILD_DIR}/${PLUGIN_NAME}-${VERSION}.zip"

# Check for required tools
command -v rsync >/dev/null 2>&1 || { echo -e "${RED}Error: rsync is required but not installed.${NC}" >&2; exit 1; }
command -v zip >/dev/null 2>&1 || { echo -e "${RED}Error: zip is required but not installed.${NC}" >&2; exit 1; }

# Clean previous builds
echo -e "${YELLOW}Cleaning previous builds...${NC}"
rm -rf "$BUILD_DIR"
mkdir -p "$PLUGIN_BUILD_DIR"

# Copy files, excluding patterns from .buildignore
echo -e "${YELLOW}Copying plugin files...${NC}"

# Create rsync exclude file from .buildignore if it exists
EXCLUDE_FILE=""
if [ -f ".buildignore" ]; then
    EXCLUDE_FILE="/tmp/rsync_exclude_$$"
    # Convert .buildignore to rsync exclude format
    grep -v '^#' .buildignore | grep -v '^$' | sed 's/^/--exclude=/' > "$EXCLUDE_FILE"
fi

# Copy all files except those in .buildignore
if [ -n "$EXCLUDE_FILE" ]; then
    rsync -av --exclude-from="$EXCLUDE_FILE" \
          --exclude="$BUILD_DIR" \
          ./ "$PLUGIN_BUILD_DIR/"
    rm -f "$EXCLUDE_FILE"
else
    rsync -av --exclude="$BUILD_DIR" ./ "$PLUGIN_BUILD_DIR/"
fi

# Validate plugin structure
echo -e "${YELLOW}Validating plugin structure...${NC}"

# Check for essential files
REQUIRED_FILES=(
    "$PLUGIN_FILE"
    "includes/contact-form-core.php"
)

for file in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "${PLUGIN_BUILD_DIR}/${file}" ]; then
        echo -e "${RED}Error: Required file ${file} not found in build${NC}"
        exit 1
    fi
done

echo -e "${GREEN}✓ Plugin structure validated${NC}"

# Create ZIP file
echo -e "${YELLOW}Creating ZIP package...${NC}"
cd "$BUILD_DIR"
zip -r "../${ZIP_FILE}" "$PLUGIN_NAME/"
cd ..

# Verify ZIP file was created
if [ ! -f "$ZIP_FILE" ]; then
    echo -e "${RED}Error: Failed to create ZIP file${NC}"
    exit 1
fi

# Get file size
ZIP_SIZE=$(du -h "$ZIP_FILE" | cut -f1)

echo ""
echo -e "${GREEN}✓ Build completed successfully!${NC}"
echo -e "${GREEN}✓ Package: ${ZIP_FILE}${NC}"
echo -e "${GREEN}✓ Size: ${ZIP_SIZE}${NC}"
echo ""
echo -e "${BLUE}Build contents:${NC}"
find "$PLUGIN_BUILD_DIR" -type f | sort | sed 's|^'$PLUGIN_BUILD_DIR'/|  |'

echo ""
echo -e "${GREEN}Ready for WordPress deployment!${NC}"