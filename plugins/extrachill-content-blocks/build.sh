#!/bin/bash

# ExtraChill Blocks Build Script
# Creates production-ready ZIP package for WordPress deployment

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Plugin information
PLUGIN_FILE="extrachill-blocks.php"
PLUGIN_NAME="extrachill-blocks"
BUILD_DIR="build"
DIST_DIR="dist"

echo -e "${BLUE}Building ExtraChill Blocks Plugin...${NC}"

# Check if plugin file exists
if [ ! -f "$PLUGIN_FILE" ]; then
    echo -e "${RED}Error: $PLUGIN_FILE not found${NC}"
    exit 1
fi

# Extract version from plugin file
VERSION=$(grep "Version:" $PLUGIN_FILE | head -1 | awk -F: '{print $2}' | sed 's/[[:space:]]//g')

if [ -z "$VERSION" ]; then
    echo -e "${RED}Error: Could not extract version from $PLUGIN_FILE${NC}"
    exit 1
fi

echo -e "${YELLOW}Version: $VERSION${NC}"

# Check for required tools
command -v rsync >/dev/null 2>&1 || { echo -e "${RED}Error: rsync is required but not installed${NC}"; exit 1; }
command -v zip >/dev/null 2>&1 || { echo -e "${RED}Error: zip is required but not installed${NC}"; exit 1; }

# Clean previous builds
echo -e "${YELLOW}Cleaning previous builds...${NC}"
rm -rf "$BUILD_DIR"
rm -rf "$DIST_DIR"
mkdir -p "$BUILD_DIR"
mkdir -p "$DIST_DIR"

# Copy files excluding development items
echo -e "${YELLOW}Copying plugin files...${NC}"
rsync -av --exclude-from=<(echo "
$BUILD_DIR/
$DIST_DIR/
.git/
.github/
node_modules/
vendor/bin/
tests/
.gitignore
.buildignore
build.sh
composer.lock
package-lock.json
*.log
.DS_Store
.claude/
CLAUDE.md
README.md
") . "$BUILD_DIR/$PLUGIN_NAME/"

# Validate plugin structure
echo -e "${YELLOW}Validating plugin structure...${NC}"
if [ ! -f "$BUILD_DIR/$PLUGIN_NAME/$PLUGIN_FILE" ]; then
    echo -e "${RED}Error: Main plugin file missing in build${NC}"
    exit 1
fi

# Create ZIP package
echo -e "${YELLOW}Creating ZIP package...${NC}"
cd "$BUILD_DIR"
zip -r "../$DIST_DIR/$PLUGIN_NAME-$VERSION.zip" "$PLUGIN_NAME/"
cd ..

# Build summary
ZIP_SIZE=$(du -h "$DIST_DIR/$PLUGIN_NAME-$VERSION.zip" | cut -f1)
FILE_COUNT=$(find "$BUILD_DIR/$PLUGIN_NAME" -type f | wc -l)

echo -e "${GREEN}✓ Build completed successfully!${NC}"
echo -e "${GREEN}✓ Package: $DIST_DIR/$PLUGIN_NAME-$VERSION.zip${NC}"
echo -e "${GREEN}✓ Size: $ZIP_SIZE${NC}"
echo -e "${GREEN}✓ Files: $FILE_COUNT${NC}"