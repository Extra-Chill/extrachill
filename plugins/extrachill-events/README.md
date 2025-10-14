# ExtraChill Events

Calendar and event plugin integrations for the ExtraChill ecosystem. Provides seamless integration between ExtraChill themes and popular event plugins with unified styling and functionality.

## Features

- **DM Events Integration**: Automatic badge styling compatibility with ExtraChill themes
- **Theme-Agnostic**: Works across all ExtraChill themes
- **Modular Architecture**: Only loads integrations for active plugins
- **Performance Optimized**: Minimal overhead, loads only when needed

## Installation

### Production Build Installation
1. Navigate to plugin directory and create production build:
   ```bash
   cd extrachill-plugins/extrachill-events
   ./build.sh
   ```
2. Upload the generated ZIP from `/build` directory via WordPress admin: **Plugins > Add New > Upload Plugin**
3. Activate the plugin
4. Plugin automatically detects and integrates with supported event plugins

### Local Development
1. Copy plugin to your WordPress plugins directory:
   ```bash
   cp -r extrachill-plugins/extrachill-events /path/to/wp-content/plugins/
   ```
2. Activate through WordPress admin

## Supported Plugins

- **DM Events**: Badge styling, breadcrumb integration, taxonomy mapping

## Requirements

- WordPress 5.0+
- PHP 7.4+
- ExtraChill theme (for styling integration)

## Development

```bash
# Navigate to plugin directory
cd extrachill-plugins/extrachill-events

# Install dependencies
composer install

# Run tests
composer test

# Code linting
composer run lint:php

# Build production package
./build.sh

# Output: /build/extrachill-events/ directory and /build/extrachill-events.zip file
```

## License

GPL v2 or later