#!/bin/bash

# ExtraChill Theme Build Script
# Creates production-ready ZIP file for WordPress deployment

set -e  # Exit on any error

THEME_NAME="extrachill"
BUILD_DIR="dist"
TEMP_DIR="$BUILD_DIR/$THEME_NAME"
ZIP_FILE="$BUILD_DIR/$THEME_NAME.zip"

echo "🚀 Starting build process for $THEME_NAME theme..."

# Clean previous builds
echo "🧹 Cleaning previous builds..."
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

# Create temporary directory
mkdir -p "$TEMP_DIR"

# Copy all files except those in .buildignore
echo "📁 Copying theme files..."
rsync -av --exclude-from='.buildignore' . "$TEMP_DIR/"

# Install production Composer dependencies if composer.json exists
if [ -f "composer.json" ]; then
    echo "📦 Installing production Composer dependencies..."
    cd "$TEMP_DIR"
    composer install --no-dev --optimize-autoloader --no-interaction
    cd - > /dev/null
fi

# Validate essential theme files
echo "✅ Validating theme structure..."
REQUIRED_FILES=(
    "$TEMP_DIR/style.css"
    "$TEMP_DIR/index.php"
    "$TEMP_DIR/functions.php"
)

for file in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "$file" ]; then
        echo "❌ Error: Required file missing: $(basename $file)"
        exit 1
    fi
done

echo "✅ All essential theme files present"

# Create ZIP file
echo "📦 Creating production ZIP file..."
cd "$BUILD_DIR"
zip -r "$THEME_NAME.zip" "$THEME_NAME/" -q
cd - > /dev/null

echo "✅ Build completed successfully!"
echo "📁 Production directory: $BUILD_DIR/$THEME_NAME/"
echo "📄 Production ZIP: $BUILD_DIR/$THEME_NAME.zip"
echo "📊 ZIP file size: $(du -h "$BUILD_DIR/$THEME_NAME.zip" | cut -f1)"

# Restore development dependencies if composer.json exists
if [ -f "../composer.json" ]; then
    echo "🔄 Restoring development dependencies..."
    cd ..
    composer install --no-interaction
fi

echo "🎉 Build process complete!"