#!/bin/bash

# Extra Chill Shop Plugin Build Script
# Creates production-ready ZIP package for WordPress deployment

set -e

PLUGIN_NAME="extrachill-shop"
BUILD_DIR="dist"
PLUGIN_DIR="$BUILD_DIR/$PLUGIN_NAME"

echo "🏗️  Building Extra Chill Shop Plugin..."

# Clean previous builds
if [ -d "$BUILD_DIR" ]; then
    echo "🧹 Cleaning previous build..."
    rm -rf "$BUILD_DIR"
fi

# Create build directory
mkdir -p "$PLUGIN_DIR"

# Install production dependencies
if [ -f "composer.json" ]; then
    echo "📦 Installing production dependencies..."
    composer install --no-dev --optimize-autoloader --no-interaction
fi

# Copy files excluding build ignored items
echo "📋 Copying plugin files..."
rsync -av --exclude-from=".buildignore" --exclude="$BUILD_DIR" . "$PLUGIN_DIR/"

# Validate essential plugin files exist
echo "✅ Validating plugin structure..."
if [ ! -f "$PLUGIN_DIR/$PLUGIN_NAME.php" ]; then
    echo "❌ Error: Main plugin file missing: $PLUGIN_NAME.php"
    exit 1
fi

if [ ! -d "$PLUGIN_DIR/inc" ]; then
    echo "❌ Error: Required /inc directory missing"
    exit 1
fi

# Get version from plugin header
VERSION=$(grep "Version:" "$PLUGIN_DIR/$PLUGIN_NAME.php" | head -1 | awk -F': ' '{print $2}' | tr -d ' ')
if [ -z "$VERSION" ]; then
    VERSION="1.0.0"
fi

# Create ZIP file
ZIP_FILE="$BUILD_DIR/$PLUGIN_NAME-$VERSION.zip"
echo "📦 Creating ZIP package: $ZIP_FILE"

cd "$BUILD_DIR"
zip -r "../$ZIP_FILE" "$PLUGIN_NAME/" -q

cd ..

# Restore development dependencies if composer.json exists
if [ -f "composer.json" ]; then
    echo "🔄 Restoring development dependencies..."
    composer install --no-interaction
fi

# Build complete
echo "✅ Build complete!"
echo "📁 Plugin directory: $PLUGIN_DIR"
echo "📦 ZIP package: $ZIP_FILE"
echo "🚀 Ready for WordPress deployment"

# Display package size
if command -v du >/dev/null 2>&1; then
    SIZE=$(du -h "$ZIP_FILE" | cut -f1)
    echo "📊 Package size: $SIZE"
fi