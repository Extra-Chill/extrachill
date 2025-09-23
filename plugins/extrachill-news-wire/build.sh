#!/bin/bash

# Build script for ExtraChill News Wire plugin
# Creates optimized production package

PLUGIN_NAME="extrachill-news-wire"
DIST_DIR="dist"

# Clean previous builds
rm -rf $DIST_DIR

# Create dist directory
mkdir -p $DIST_DIR

# Copy plugin files to dist, excluding development files
rsync -av --exclude='.git' --exclude='build.sh' --exclude='.DS_Store' --exclude='*.md' . $DIST_DIR/$PLUGIN_NAME/

# Create ZIP file
cd $DIST_DIR
zip -r $PLUGIN_NAME.zip $PLUGIN_NAME/
cd ..

echo "Build complete: $DIST_DIR/$PLUGIN_NAME/ and $DIST_DIR/$PLUGIN_NAME.zip"