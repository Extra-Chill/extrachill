# Build Process

NPM-based build system for compiling blocks and creating production packages.

## Package Structure

### package.json
- **Version**: 0.1.7
- **Build Tool**: `@wordpress/scripts` v27.1.0
- **Scripts**:
  - `build` - Complete production build
  - `build:event-submission` - Compile event submission block
  - `copy:block-files` - Copy static block files
  - `start` - Development watch mode

## Build Scripts

### npm run build
**Purpose**: Creates production-ready block assets

**Steps**:
1. Compiles `blocks/event-submission/src/index.js` to `build/event-submission/`
2. Copies static files (block.json, render.php, style.css, view.js)

**Output**:
```
build/event-submission/
├── index.js      # Compiled block registration
├── index.asset.php # WordPress asset dependencies
├── edit.js       # Compiled editor interface
├── block.json    # Block configuration
├── render.php    # Server-side rendering
├── style.css     # Frontend styles
└── view.js       # Frontend JavaScript
```

### npm run start
**Purpose**: Development mode with hot reloading

**Usage**: `npm run start`

**Features**:
- Watches source files for changes
- Automatic recompilation
- Development-friendly error messages

## Block Compilation

### Source Structure
```
blocks/event-submission/src/
├── index.js    # Main block registration
└── edit.js     # Editor component
```

### Build Output
- **index.js**: Compiled registration with dependencies
- **index.asset.php**: WordPress script/style dependencies
- **edit.js**: Compiled editor interface

## Production Build Integration

### ./build.sh Script
The plugin's `build.sh` script handles the complete production package:

1. Runs `npm run build` to compile blocks
2. Creates optimized ZIP file with all assets
3. Excludes development files (node_modules, src/, etc.)

### File Inclusion
**Included in build**:
- Compiled block assets from `build/event-submission/`
- PHP source files
- CSS assets
- Production dependencies

**Excluded from build**:
- `node_modules/`
- `blocks/event-submission/src/`
- Development configuration files
- `package.json`, `package-lock.json`

## Development Workflow

1. **Install Dependencies**: `npm install`
2. **Development**: `npm run start` (with hot reloading)
3. **Production Build**: `npm run build`
4. **Plugin Package**: `./build.sh` (creates ZIP)

## Dependencies

### @wordpress/scripts
- **Purpose**: Standardized WordPress block development tooling
- **Features**: ES6+ compilation, CSS processing, asset optimization
- **Version**: ^27.1.0 (latest stable)

### Block-Specific Configuration
- **Entry Point**: `blocks/event-submission/src/index.js`
- **Output Path**: `build/event-submission/`
- **Static Files**: Copied from source directory after compilation</content>
<parameter name="filePath">docs/build-process.md