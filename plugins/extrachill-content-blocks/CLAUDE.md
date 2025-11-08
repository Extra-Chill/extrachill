# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **WordPress plugin** called "ExtraChill Blocks" that contains community engagement Gutenberg blocks for the ExtraChill platform. The plugin provides business-focused blocks for music industry tools, community engagement features, and AI-powered interactive experiences.

## Architecture

The codebase follows **WordPress multi-block plugin standards** with automatic block discovery and custom webpack configuration:

```
extrachill-blocks/
├── extrachill-blocks.php          # Main plugin file
├── package.json                   # Unified build configuration
├── webpack.config.js              # Custom webpack configuration
├── build.sh                       # Standalone production build script (301 lines)
├── .buildignore                   # Production file exclusions
├── src/                           # Source blocks (WordPress standard)
│   ├── trivia/                   # Interactive trivia questions
│   │   ├── block.json           # Block configuration
│   │   ├── index.js             # Editor JavaScript
│   │   ├── index.php            # Block initialization & REST API
│   │   ├── render.php           # Server-side rendering
│   │   └── assets/              # Static assets (CSS/JS)
│   │       ├── css/             # Trivia-specific frontend CSS
│   │       └── js/              # Trivia-specific frontend JS
│   ├── image-voting/             # Image voting with email capture
│   │   ├── block.json
│   │   ├── index.js
│   │   ├── index.php            # AJAX handlers & newsletter integration
│   │   ├── view.js              # Frontend JavaScript (viewScript)
│   │   └── render.php
│   ├── rapper-name-generator/    # Rapper name generator
│   ├── band-name-generator/      # Band name generator
│   ├── ai-adventure/             # AI-powered text adventure game
│   │   ├── block.json
│   │   ├── index.js             # Editor JavaScript
│   │   ├── index.php            # Block initialization
│   │   ├── view.js              # Frontend game logic (viewScript)
│   │   ├── style.scss           # Frontend styles
│   │   ├── editor.scss          # Editor styles
│   │   └── includes/            # PHP classes
│   │       └── api-handler.php  # REST API handler with centralized AI client
│   ├── ai-adventure-path/        # Adventure story path
│   └── ai-adventure-step/        # Adventure story step
└── build/                        # Auto-generated compiled assets
    ├── trivia/
    │   ├── index.php            # Copied by custom webpack
    │   └── assets/              # Copied by custom webpack
    ├── image-voting/
    │   └── index.php            # Copied by custom webpack
    └── ai-adventure/
        ├── index.php            # Copied by custom webpack
        └── includes/            # Copied by --webpack-copy-php
```

## Block Structure (WordPress Multi-Block Standard)

Each block follows WordPress documentation standards:
```
src/block-name/
├── block.json              # Block configuration (API v2/3)
├── index.js                # Block editor JavaScript (directly in block dir)
├── view.js                 # Frontend JavaScript (optional)
├── style.scss              # Frontend styles (optional)
├── editor.scss             # Editor styles (optional)
├── render.php              # Server-side rendering (optional)
├── index.php               # Block initialization (optional)
└── includes/               # Block-specific PHP classes (optional)
```

**Key Requirements**:
- JavaScript files must be **directly in the block directory** (not in nested `src/`)
- block.json references files with `file:./` prefix (e.g., `"editorScript": "file:./index.js"`)
- wp-scripts automatically discovers all block.json files in `src/` directory
- Compiled output goes to `build/` with same directory structure

## Common Development Commands

All commands are run from the plugin root directory:

```bash
# Build all blocks (WordPress standard - automatic block.json discovery)
npm run build

# Development server with hot reload (watches all blocks automatically)
npm run start

# Code quality
npm run lint:js
npm run lint:css
npm run format

# Create production distribution package
./build.sh
```

**Notes**:
- Per WordPress multi-block plugin standards, wp-scripts automatically discovers all `block.json` files in `src/` directory
- Uses `--webpack-copy-php` flag to copy ALL PHP files from `src/` and subdirectories (including `includes/` folders)
- No need for individual build commands per block

## Block Registration System

The plugin uses WordPress standard automatic block discovery:
- Main plugin scans `build/` directory for `block.json` files after compilation
- wp-scripts automatically:
  - Discovers all block.json files in `src/` directory
  - Compiles referenced JavaScript/CSS files
  - Copies block.json and render.php to `build/`
  - Generates .asset.php files with dependencies
- Each block's `index.php` is automatically loaded if present
- Blocks are registered using `register_block_type()` pointing to `build/` directory
- Assets are conditionally loaded only when blocks are present

## Key Technical Details

### Main Plugin (`extrachill-blocks.php`)
- Registers all blocks automatically via `glob()` pattern matching
- Handles shared asset loading with conditional enqueuing
- Manages block activation and lifecycle hooks
- Integrates with ExtraChill ecosystem

### Trivia Block (`src/trivia/`)
- Interactive multiple-choice questions with real-time scoring
- Server-side rendering with dynamic content
- Customizable result messages and scoring ranges
- Special asset structure with `/assets/css/` and `/assets/js/` subdirectories
- Custom webpack handling to copy static assets to `build/trivia/assets/`
- Manual asset enqueuing in `index.php` (not handled by block.json)
- Chart.js integration for analytics

### Image Voting Block (`src/image-voting/`)
- Image selection with vote tracking
- Email capture with duplicate vote prevention
- Vote data stored in WordPress post content (block attributes)
- AJAX-powered voting system via `wp_ajax_extrachill_blocks_image_vote`
- REST API for vote count retrieval (`/wp-json/extrachill/v1/image-voting/vote-count/{post_id}/{instance_id}`, provided by extrachill-api)
- Newsletter integration via `newsletter_form_integrations` filter
- Uses `extrachill_multisite_subscribe()` bridge function for newsletter subscriptions
- Frontend JavaScript via `viewScript: "file:./view.js"` in block.json
- **Block Instance Identification**: Uses `uniqueBlockId` attribute (block.json lines 26-29) for tracking individual block instances
- **Unique ID Generation**: JavaScript creates unique ID on block insert via `createUniqueID()` (index.js lines 9-12, 44-48)
- **Server-Side Matching**: PHP matches block instances using `uniqueBlockId` attribute (index.php lines 53, 92, render.php line 26)
- **Architecture Decision**: Explicit attribute-based identification replaced computed MD5 approach for reliability
- Community engagement features

### Generator Blocks (`src/*-name-generator/`)
- Rapper and band name generators for music industry
- Form-based interface with dynamic generation
- Server-side rendering for better SEO
- ExtraChill-specific branding and styling

### AI Adventure Blocks (`src/ai-adventure*/`)
- AI-powered interactive text adventure game system
- **ai-adventure**: Main container block for adventures with custom render callback
- **ai-adventure-path**: Story path/branch block
- **ai-adventure-step**: Individual story step with triggers
- AI-powered storytelling via ExtraChill AI Client plugin (provider: openai, model: gpt-5-nano)
- REST API endpoint: `/wp-json/extrachill/v1/ai-adventure` (provided by extrachill-api)
- REST routes registered centrally via the extrachill-api plugin
- Nested block structure for complex branching narratives
- Real-time AI-generated responses and story progression
- Frontend JavaScript via `viewScript: "file:./view.js"` in block.json

## Build System

### WordPress-Standards-Compliant Compilation with Custom Configuration
**Follows [WordPress multi-block plugin documentation](https://developer.wordpress.org/news/2024/05/22/setting-up-a-multi-block-using-inner-blocks-and-post-meta/) with necessary customizations**

- **Automatic Block Discovery**: wp-scripts scans `src/` directory for all `block.json` files
- **Unified Build**: Single `npm run build` command compiles all blocks automatically
- **Custom Webpack Configuration**: `webpack.config.js` extends `@wordpress/scripts` default config
- **Custom File Copying**: CopyWebpackPlugin patterns for special requirements:
  - Copies all `src/**/index.php` files to maintain block initialization
  - Copies `src/trivia/assets/` to `build/trivia/assets/` for static CSS/JS files
- **Standard PHP Copying**: `--webpack-copy-php` flag handles PHP files in `includes/` subdirectories
- **Asset Optimization**: Automatic minification, tree-shaking, and code splitting
- **Development Mode**: Hot reload with `npm start` watches all blocks automatically

### Why Custom Webpack Configuration?

The trivia block requires a special asset structure with static CSS/JS files in an `/assets/` subdirectory. These files are manually enqueued in `index.php` rather than referenced in `block.json`, requiring custom webpack patterns to copy them to the build directory. Additionally, all blocks with `index.php` files need those files copied to maintain block initialization logic.

### How It Works
1. wp-scripts scans `src/` directory
2. Finds all `block.json` files
3. Compiles JavaScript files referenced in block.json (editorScript, viewScript, etc.)
4. Compiles SCSS/CSS files imported in JavaScript
5. Generates `.asset.php` files with WordPress package dependencies
6. Copies block.json and render.php to corresponding `build/` directories
7. Output: `build/block-name/` with all compiled assets

### File Reference Conventions
block.json files must use these exact references for wp-scripts:
- `"editorScript": "file:./index.js"` → Compiles to `build/block-name/index.js`
- `"viewScript": "file:./view.js"` → Compiles to `build/block-name/view.js` (used by image-voting and ai-adventure blocks)
- `"editorStyle": "file:./index.css"` → Generated from imported SCSS/CSS
- `"style": "file:./style-index.css"` → Generated from style.scss imports
- `"render": "file:./render.php"` → Auto-copied to `build/`

**viewScript Property**: The `viewScript` property in block.json provides a standardized way to include frontend-only JavaScript that doesn't execute in the block editor. WordPress automatically enqueues these scripts with generated handles (e.g., `extrachill-blocks-image-voting-view-script-0`). This is used by image-voting and ai-adventure blocks for interactive frontend functionality.

### Production Deployment
- **Standalone Build Script**: Local `build.sh` file (301 lines) - NOT a symlink
- **Universal WordPress Build Script**: Auto-detects plugin type from headers
- **Build Process**:
  1. Runs `npm run build` (compiles all blocks via custom webpack config)
  2. Runs `composer install --no-dev` for production dependencies
  3. Copies files to `/build/extrachill-blocks/` via rsync with `.buildignore` exclusions
  4. Validates build structure (checks for main file and common directories)
  5. Creates `/build/extrachill-blocks.zip` from clean directory
  6. Restores dev dependencies with `composer install`
- **Output**: Both clean directory AND zip file in `/build/` (non-versioned, CLAUDE.md compliant)
- **File Exclusion**: `.buildignore` excludes `src/`, `node_modules/`, docs, dev files

## Asset Management

The plugin uses a hybrid asset loading approach combining WordPress block.json automation, inline styles, and manual enqueuing for special cases:

### Inline Style Loading Pattern (WordPress 5.8+)
- **Implementation**: `extrachill_blocks_enqueue_block_assets()` function (lines 91-131 in extrachill-blocks.php)
- **Attachment Strategy**: Block styles attached to WordPress core `wp-block-library` handle using `wp_add_inline_style()`
- **Conditional Loading**: Styles only load when blocks are rendered via `has_block()` checks
- **WordPress 5.8+ Pattern**: Recommended approach for custom block styles in modern WordPress
- **Blocks Using This Pattern**: All blocks with compiled `style-index.css` files (image-voting, ai-adventure, generators)
- **Benefits**: Eliminates separate HTTP requests, automatic loading with WordPress core styles

### Standard Asset Loading (Most Blocks)
- **Automatic Enqueuing**: block.json properties (`editorScript`, `viewScript`) trigger automatic WordPress enqueuing
- **Generated Handles**: WordPress creates predictable handles (e.g., `extrachill-blocks-image-voting-view-script-0`)
- **Dependency Management**: `.asset.php` files provide automatic WordPress package dependencies
- **Used By**: image-voting, ai-adventure, generator blocks

### Custom Asset Loading (Trivia Block)
- **Manual Enqueuing**: Static CSS/JS files enqueued in `index.php` via `wp_enqueue_scripts` hook
- **Special Structure**: `/assets/css/` and `/assets/js/` subdirectories contain standalone files
- **Custom Webpack**: Files copied to build directory via CopyWebpackPlugin patterns
- **Reason**: Trivia block uses Chart.js and standalone frontend scripts not compiled by wp-scripts

### Shared Patterns
- **Cache Busting**: Uses `filemtime()` for automatic versioning across all enqueued assets
- **Script Localization**: AJAX endpoints and nonces passed via `wp_localize_script()`
- **Performance**: Conditional loading prevents unnecessary requests

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
- **REST API**: `/wp-json/extrachill/v1/ai-adventure` - AI Adventure game endpoint (via extrachill-api)
- **Agentic Capabilities**: Full support for tools/function calling via ai-http-client (not currently used)
- **Multi-Provider Support**: Library supports OpenAI, Anthropic, Google Gemini, Grok, OpenRouter
- **Security**: Input sanitization, output escaping, capability checks

### Testing AI Functionality
1. Verify ExtraChill AI Client plugin is activated
2. Configure OpenAI API key in Network Admin → Settings → AI Client
3. Create test post with AI Adventure block
4. Test introduction generation and conversation turns
5. Verify progression triggers work correctly

## Newsletter Integration

The image-voting block integrates with the ExtraChill newsletter system for email capture during voting:

### Architecture Pattern
- **Registration**: Plugin registers integration context via `newsletter_form_integrations` filter
- **Configuration**: Admin configures integration in Newsletter → Settings on newsletter.extrachill.com
- **Subscription**: Block calls `extrachill_multisite_subscribe()` bridge function
- **Self-Contained**: All newsletter logic handled by extrachill-newsletter plugin

### Implementation Details
```php
// Registration in extrachill-blocks.php
add_filter('newsletter_form_integrations', 'extrachill_blocks_register_newsletter_integration');
function extrachill_blocks_register_newsletter_integration($integrations) {
    $integrations['image_voting'] = array(
        'label' => __('Image Voting Block', 'extrachill-blocks'),
        'description' => __('Newsletter subscription when users vote on images', 'extrachill-blocks'),
        'list_id_key' => 'image_voting_list_id',
        'enable_key' => 'enable_image_voting',
        'plugin' => 'extrachill-blocks',
    );
    return $integrations;
}
```

### Subscription Flow
1. User submits vote with email address via AJAX
2. `extrachill_blocks_handle_image_vote()` handler validates email
3. Checks duplicate votes via email in block attributes
4. Calls `extrachill_multisite_subscribe($email, 'image_voting')` before counting vote
5. Vote counts even if newsletter subscription fails (non-blocking)
6. Errors logged via `error_log()` for admin review

### Configuration Requirements
- ExtraChill Newsletter plugin must be network-activated
- Admin configures Sendy list ID for 'image_voting' integration
- Enable/disable toggle in newsletter settings
- Network-wide settings accessible via `get_site_option('extrachill_newsletter_settings')`

### Error Handling
Newsletter subscription failures are logged but do not prevent vote counting. This ensures community engagement continues even if newsletter service is unavailable.

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
- **Previous**: Standalone OpenAI client with local settings page and admin UI
- **Current**: Centralized AI Client plugin integration via `ai_request` filter
- **Rationale**: Eliminates redundant API key configuration, centralizes AI provider management across all ExtraChill plugins
- **Impact**: Requires ExtraChill AI Client plugin as dependency, removes local settings complexity
- **Code Location**: AI integration logic in `src/ai-adventure/includes/api-handler.php`
- **Files Removed**: All standalone AI client code and admin settings pages deleted in favor of centralized approach

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