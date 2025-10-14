# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **WordPress plugin** called "ExtraChill Blocks" that contains community engagement Gutenberg blocks for the ExtraChill platform. The plugin provides business-focused blocks for music industry tools, community engagement features, and AI-powered interactive experiences.

## Architecture

The codebase follows a modern, unified plugin architecture with automatic block discovery:

```
extrachill-blocks/
├── extrachill-blocks.php          # Main plugin file
├── package.json                   # Unified build configuration
├── blocks/                        # Individual block directories
│   ├── trivia/                   # Interactive trivia questions
│   ├── image-voting/             # Image voting with email capture
│   ├── rapper-name-generator/    # Rapper name generator
│   ├── band-name-generator/      # Band name generator
│   ├── ai-adventure/             # AI-powered text adventure game
│   │   ├── includes/             # AI Adventure specific includes
│   │   │   ├── api-handler.php  # REST API handler with ai_request filter
│   │   │   └── prompt-builder.php # Prompt construction utilities
│   │   └── index.php             # Block initialization
│   ├── ai-adventure-path/        # Adventure story path
│   └── ai-adventure-step/        # Adventure story step
└── build/                        # Compiled assets
```

## Block Structure

Each block follows this standardized structure:
```
blocks/block-name/
├── block.json              # Block configuration (API v2/3)
├── src/
│   └── index.js           # Block editor JavaScript
├── render.php             # Server-side rendering
├── index.php              # Block initialization (optional)
├── includes/              # Block-specific PHP classes
└── assets/               # Block-specific assets
```

## Common Development Commands

All commands are run from the plugin root directory:

```bash
# Build all blocks
npm run build

# Development server for all blocks
npm run start

# Build specific blocks
npm run build:trivia
npm run build:image-voting
npm run build:rapper-name-generator
npm run build:band-name-generator

# Development server for specific blocks
npm run start:trivia
npm run start:image-voting
npm run start:rapper-name-generator
npm run start:band-name-generator

# Code quality
npm run lint:js
npm run lint:css
npm run format

# Create distribution package
./build.sh

# Build AI Adventure blocks
npm run build:ai-adventure
npm run build:ai-adventure-path
npm run build:ai-adventure-step

# Development server for AI Adventure
npm run start:ai-adventure
npm run start:ai-adventure-path
npm run start:ai-adventure-step
```

## Block Registration System

The plugin uses automatic block discovery:
- Main plugin scans `blocks/` directory for `block.json` files
- Each block's `index.php` is automatically loaded if present
- Blocks are registered using `register_block_type()` with the directory path
- Assets are conditionally loaded only when blocks are present

## Key Technical Details

### Main Plugin (`extrachill-blocks.php`)
- Registers all blocks automatically via `glob()` pattern matching
- Handles shared asset loading with conditional enqueuing
- Manages block activation and lifecycle hooks
- Integrates with ExtraChill ecosystem

### Trivia Block (`blocks/trivia/`)
- Interactive multiple-choice questions with real-time scoring
- Server-side rendering with dynamic content
- REST API integration for attempt logging
- Customizable result messages and scoring ranges
- Chart.js integration for analytics

### Image Voting Block (`blocks/image-voting/`)
- Image selection with vote tracking
- Email capture integration
- Vote data stored in WordPress post content
- AJAX-powered voting system
- Community engagement features

### Generator Blocks (`blocks/*-name-generator/`)
- Rapper and band name generators for music industry
- Form-based interface with dynamic generation
- Server-side rendering for better SEO
- ExtraChill-specific branding and styling

### AI Adventure Blocks (`blocks/ai-adventure*/`)
- AI-powered interactive text adventure game system
- **ai-adventure**: Main container block for adventures
- **ai-adventure-path**: Story path/branch block
- **ai-adventure-step**: Individual story step with triggers
- AI-powered storytelling via ExtraChill AI Client plugin (provider: openai, model: gpt-5-nano)
- REST API endpoint: `/wp-json/extrachill-blocks/v1/adventure`
- Nested block structure for complex branching narratives
- Real-time AI-generated responses and story progression

## Build System

### WordPress Asset Compilation
- **Unified Build**: Single `package.json` with shared dependencies across all blocks
- **Individual Compilation**: Each block can be built separately for development
- **WordPress Scripts**: Uses `@wordpress/scripts` for modern tooling
- **Asset Optimization**: Automatic minification and optimization
- **Development Mode**: Hot reload and source maps via `npm start`

### Production Deployment
- **Universal Build Script**: Symlinked to shared build script at `../../.github/build.sh`
- **Build Process**: Run `./build.sh` after compiling assets with `npm run build`
- **Output**: Creates `/build/extrachill-blocks/` directory and `/build/extrachill-blocks.zip` file (non-versioned)
- **File Exclusion**: `.buildignore` patterns exclude development files (node_modules, src files, etc.)
- **Composer Integration**: Uses `composer install --no-dev` for production dependencies

## Asset Management

- **Conditional Loading**: Assets only load when blocks are present
- **Cache Busting**: Uses `filemtime()` for automatic versioning
- **Development/Production**: Automatic detection of build mode
- **Performance**: Modular loading prevents unnecessary requests

## Data Storage

The plugin uses WordPress native content storage:
- Block data stored in post content via WordPress block system
- No custom database tables required
- Proper sanitization and security measures
- Integration with ExtraChill multisite architecture

## Block Naming Convention

All blocks use the namespace `extrachill-blocks/block-name`:
- `extrachill-blocks/trivia`
- `extrachill-blocks/image-voting`
- `extrachill-blocks/rapper-name-generator`
- `extrachill-blocks/band-name-generator`
- `extrachill-blocks/ai-adventure`
- `extrachill-blocks/ai-adventure-path`
- `extrachill-blocks/ai-adventure-step`

## AI Integration

The plugin uses the **ExtraChill AI Client** plugin for centralized AI provider integration:

### Architecture Decision: Centralized AI Management
- **No Local Settings Page**: Eliminates per-plugin API key configuration complexity
- **Hardcoded Configuration**: OpenAI provider with gpt-5-nano model defined in `api-handler.php`
- **Standalone Client Removed**: Previous `includes/openai.php` removed in favor of centralized approach
- **Dependency**: Requires ExtraChill AI Client plugin as prerequisite for AI functionality
- **Migration Rationale**: Centralizes API key management, reduces code duplication, simplifies maintenance

### Technical Implementation
- **AI Request Filter**: Uses `ai_request` filter from ai-http-client library
- **Network-Wide API Keys**: Managed centrally via ExtraChill AI Client plugin (Network Admin → Settings → AI Client)
- **Model Changes**: Update `api-handler.php` constants and redeploy plugin to change provider or model
- **REST API**: `/wp-json/extrachill-blocks/v1/adventure` - AI Adventure game endpoint
- **Agentic Capabilities**: Full support for tools/function calling via ai-http-client (not currently used)
- **Multi-Provider Support**: Library supports OpenAI, Anthropic, Google Gemini, Grok, OpenRouter
- **Security**: Input sanitization, output escaping, capability checks

### Testing AI Functionality
1. Verify ExtraChill AI Client plugin is activated
2. Configure OpenAI API key in Network Admin → Settings → AI Client
3. Create test post with AI Adventure block
4. Test introduction generation and conversation turns
5. Verify progression triggers work correctly

## Development Environment

- **ExtraChill Integration**: Designed for ExtraChill Platform multisite network
- **WordPress Version**: 5.8+ required
- **PHP Version**: 7.4+ required
- **Node.js**: 16+ required for build tools

## Security and Best Practices

- All user input is sanitized using WordPress functions
- Nonce verification for AJAX requests
- Capability checks where appropriate
- SQL injection prevention with prepared statements
- XSS protection with proper escaping

## Integration with ExtraChill Platform

- **Multisite Compatibility**: Works across ExtraChill multisite network
- **Theme Integration**: Designed to work with extrachill theme
- **Cross-Domain Features**: Integrates with ExtraChill multisite authentication
- **Community Features**: Built for music community engagement

## Deployment

### Prerequisites
- **Required Plugin**: ExtraChill AI Client plugin must be activated for AI Adventure functionality
- **API Configuration**: OpenAI API key configured in Network Admin → Settings → AI Client

### Deployment Process
- Single plugin installation provides all community engagement blocks
- Network-wide deployment across ExtraChill multisite
- Unified update and maintenance system
- Business-focused functionality separated from personal blocks

### AI Functionality Verification
1. Confirm ExtraChill AI Client plugin is network-activated
2. Verify API keys are configured in network settings
3. Test AI Adventure block on development site first
4. Monitor REST API endpoint responses for errors
5. Validate story progression and trigger logic

## Migration from Chubes Blocks

This plugin was created by migrating business-focused blocks from chubes-blocks:
- **Trivia Block**: Community engagement quizzes
- **Image Voting Block**: Community voting features
- **Generator Blocks**: Music industry name generators
- **Data Storage**: WordPress native post content storage
- **Asset Systems**: Conditional loading and performance optimization maintained

### AI Architecture Refactoring
During development, the AI Adventure implementation underwent architectural refactoring:
- **Previous**: Standalone OpenAI client in `includes/openai.php` with local settings page
- **Current**: Centralized AI Client plugin integration via `ai_request` filter
- **Rationale**: Eliminates redundant API key configuration, centralizes AI provider management across all ExtraChill plugins
- **Impact**: Requires ExtraChill AI Client plugin as dependency, removes local settings complexity
- **Code Location**: AI integration logic now in `blocks/ai-adventure/includes/api-handler.php`

## Development Standards

### WordPress Plugin Architecture
- Full compliance with WordPress plugin development standards
- PSR-4 compatible structure for future enhancement
- Proper plugin initialization and asset management
- Security-first development practices

### ExtraChill Integration
- Designed specifically for ExtraChill Platform
- Multisite network compatibility
- Community engagement focus
- Music industry tools and features

### Build Process
- Standardized build script creates production ZIP packages
- Version extraction from plugin headers
- File exclusion via `.buildignore` patterns
- Production optimization with clean directory structure