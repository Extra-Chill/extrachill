#!/bin/bash

# ExtraChill Events Plugin Build Script
# Creates production-ready ZIP package for WordPress deployment

set -e

# Configuration
PLUGIN_NAME="extrachill-events"
BUILD_DIR="dist"
PLUGIN_FILE="extrachill-events.php"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}Building ExtraChill Events Plugin...${NC}"

# Get plugin version from main file
if [ ! -f "$PLUGIN_FILE" ]; then
    echo -e "${RED}Error: Plugin file $PLUGIN_FILE not found${NC}"
    exit 1
fi

VERSION=$(grep "Version:" $PLUGIN_FILE | head -1 | awk '{print $3}')
if [ -z "$VERSION" ]; then
    echo -e "${RED}Error: Could not extract version from $PLUGIN_FILE${NC}"
    exit 1
fi

echo -e "Plugin Version: ${GREEN}$VERSION${NC}"

# Clean previous builds
echo -e "${YELLOW}Cleaning previous builds...${NC}"
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

# Install production dependencies
echo -e "${YELLOW}Installing production dependencies...${NC}"
if [ -f "composer.json" ]; then
    composer install --no-dev --optimize-autoloader --no-interaction
fi

# Create build directory structure
BUILD_PATH="$BUILD_DIR/$PLUGIN_NAME"
mkdir -p "$BUILD_PATH"

# Copy files, excluding build files and development dependencies
echo -e "${YELLOW}Copying plugin files...${NC}"
rsync -av --exclude-from='.buildignore' ./ "$BUILD_PATH/" 2>/dev/null || {
    # Fallback if rsync fails
    cp -r . "$BUILD_PATH/"

    # Manually remove excluded items
    while IFS= read -r pattern; do
        [ -z "$pattern" ] && continue
        [ "${pattern:0:1}" = "#" ] && continue
        find "$BUILD_PATH" -name "$pattern" -exec rm -rf {} + 2>/dev/null || true
    done < .buildignore
}

# Validate essential plugin files
echo -e "${YELLOW}Validating plugin structure...${NC}"
REQUIRED_FILES=("$PLUGIN_FILE" "includes/class-dm-events-integration.php")
for file in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "$BUILD_PATH/$file" ]; then
        echo -e "${RED}Error: Required file $file missing from build${NC}"
        exit 1
    fi
done

# Create production ZIP
ZIP_NAME="${PLUGIN_NAME}-${VERSION}.zip"
echo -e "${YELLOW}Creating ZIP package: $ZIP_NAME${NC}"

cd "$BUILD_DIR"
zip -r "$ZIP_NAME" "$PLUGIN_NAME/" -q

# Validate ZIP contents
echo -e "${YELLOW}Validating ZIP package...${NC}"
if ! unzip -t "$ZIP_NAME" >/dev/null 2>&1; then
    echo -e "${RED}Error: ZIP package validation failed${NC}"
    exit 1
fi

cd ..

# Restore development dependencies
echo -e "${YELLOW}Restoring development dependencies...${NC}"
if [ -f "composer.json" ]; then
    composer install --no-interaction >/dev/null 2>&1
fi

# Build summary
echo -e "${GREEN}✓ Build completed successfully!${NC}"
echo -e "Package: ${GREEN}$BUILD_DIR/$ZIP_NAME${NC}"
echo -e "Size: $(du -h "$BUILD_DIR/$ZIP_NAME" | cut -f1)"
echo -e "Files: $(unzip -l "$BUILD_DIR/$ZIP_NAME" | tail -1 | awk '{print $2}') files"
echo -e "${YELLOW}Ready for WordPress deployment${NC}"