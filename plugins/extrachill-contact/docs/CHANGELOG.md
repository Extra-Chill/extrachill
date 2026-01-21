# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v2.0.2
- **UI Refinement**: Replaced custom feedback styling with standard WordPress notice patterns for consistent user experience
- **Backend Consolidation**: Removed local REST API implementation in favor of centralized platform API
- **Integration Cleanup**: Simplified newsletter subscription logic and removed redundant integration filters
- **Documentation Overhaul**: Updated README and CLAUDE.md to fully reflect Gutenberg block architecture and remove legacy shortcode references
- **Asset Updates**: Refreshed compiled production assets

## v2.0.1
- **React Frontend Implementation**: Added complete TypeScript/React frontend with ContactForm component
- **Build System**: Implemented Vite-based build pipeline for compiling React components to IIFE bundles
- **Component Architecture**: Created reusable ContactForm component with Turnstile integration, form validation, and state management
- **Styling System**: Added CSS with Extra Chill design system variables and responsive design
- **TypeScript Integration**: Full type safety with interfaces for props, data structures, and component states
- **WordPress Integration**: Auto-mounting system for seamless integration with Gutenberg blocks

## v2.0.0
- **Major architectural overhaul**: Converted from shortcode-based to Gutenberg block-based contact form
- **Technology shift**: Implemented React-based frontend with REST API backend for headless architecture
- **New file structure**: Added `blocks/contact-form/` directory with block registration, rendering, and editor components
- **REST API implementation**: Added secure form submission endpoint at `extrachill/v1/contact/submit`
- **Email system consolidation**: Moved email handling to dedicated `includes/email-functions.php`
- **CSS rewrite**: Complete styling overhaul with new `.ec-contact-form*` class naming convention
- **Plugin refactoring**: Simplified main plugin file from class-based singleton to procedural registration
- **Breaking change**: Shortcode `[ec_custom_contact_form]` no longer supported - use Gutenberg block instead
- **WordPress version requirement**: Updated minimum requirement to WordPress 6.0 for block support

## v1.0.1
- Improved error handling: Replaced URL parameter-based error messages with proper notice system using `extrachill_set_notice()`
- Streamlined contact form subject options: Removed Press/Media, Artist Platform Support, Community Forum Support, and Account/Login Support options
- Updated CSS styling: Changed submit button colors from CSS variables to hardcoded values for better compatibility
- Documentation improvements: Restructured README with development status section and moved changelog to dedicated docs directory
- Code cleanup: Removed deprecated `display_contact_success_message()` function that displayed inline success/error messages
- Build system updates: Updated build output documentation to reflect current process

## v1.0.0
- Initial release
- Contact form with shortcode support
- HTML email templates with Extra Chill branding
- Sendy newsletter integration
- Cloudflare Turnstile protection
- Responsive CSS styling
- Build system for production deployment