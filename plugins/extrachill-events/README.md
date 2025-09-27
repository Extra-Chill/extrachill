# ExtraChill Events

Calendar and event plugin integrations for the ExtraChill ecosystem. Provides seamless integration between ExtraChill themes and popular event plugins with unified styling and functionality.

## Features

- **DM Events Integration**: Automatic badge styling compatibility with ExtraChill themes
- **Theme-Agnostic**: Works across all ExtraChill themes
- **Modular Architecture**: Only loads integrations for active plugins
- **Performance Optimized**: Minimal overhead, loads only when needed

## Installation

1. Upload the plugin to `/wp-content/plugins/`
2. Activate through WordPress admin
3. Plugin automatically detects and integrates with supported event plugins

## Supported Plugins

- **DM Events**: Badge styling, breadcrumb integration, taxonomy mapping

## Requirements

- WordPress 5.0+
- PHP 7.4+
- ExtraChill theme (for styling integration)

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Code linting
composer run lint:php

# Build production package
./build.sh
```

## License

GPL v2 or later