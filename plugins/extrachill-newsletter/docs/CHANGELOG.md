# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.3] - 2026-01-23

- Add direct analytics tracking via Abilities API

## [0.2.1] - 2026-01-06

### Added
- Added `extrachill_subscribe_to_list()` low-level Sendy subscription helper that supports optional subscriber name and source tracking.
- Added `extrachill_newsletter_subscribed` action to support subscription tracking integrations.

### Changed
- Updated subscription workflow to accept optional subscriber name and source URL.
- Updated newsletter form REST payload to support batch-style `emails` array and send `source_url`.
- Updated newsletter schema breadcrumb items on the newsletter site to match the visible breadcrumb trail.

### Fixed
- Improved Sendy subscription result handling by returning explicit status codes for invalid, already subscribed, and general failure states.

## [0.2.0] - 2025-12-30

### Added
- Added 'contact' integration context for newsletter subscriptions via contact forms

### Changed
- Major overhaul of HTML email template with modern responsive design, dark mode support, and preheader text
- Replaced admin-ajax with REST API for "Push to Sendy" campaign management
- Refactored post meta integration to use `extrachill_post_meta_parts` and `extrachill_post_meta_published_prefix` filters for cleaner theme integration
- Updated breadcrumb customization to include the post title for newsletter posts
- Improved frontend asset loading by including `restUrl` in localized `newsletterParams`
- Refined newsletter inline form max-width on tablet/desktop displays
- Optimized YouTube thumbnail conversion in email templates with escaped URLs and improved regex

### Fixed
- Cleaned up redundant `newsletter_customize_post_meta` function from main plugin file
- Removed unused AJAX handling logic in favor of REST API endpoints
- Improved error handling and feedback in "Push to Sendy" admin interface

## [0.1.9] - 2025-12-10

### Changed
- Refactored plugin loading to use `plugins_loaded` hook with priority 20 for improved multisite dependency management
- Improved activation/deactivation handling with transient-based rewrite rule flushing for better performance

### Fixed
- Removed unnecessary screen reader label from newsletter form template for cleaner markup

## [0.1.7] - 2025-12-09

### Changed
- Replaced hardcoded blog IDs and URLs with dynamic functions for improved multisite flexibility
- Updated `is_newsletter_site()` and asset loading to use `ec_get_blog_id('newsletter')`
- Refactored breadcrumb and homepage hooks to use dynamic site URL functions
- Updated email templates to use `ec_get_site_url()` for logo and footer links
- Cleaned up newsletter forms CSS by removing unused festival wire, popup, and shortcode styles
- Simplified form button styling and removed redundant font-size declarations
- Added consistent button classes to form submit buttons
- Updated README to reference changelog file instead of inline version history

## [0.1.6] - 2025-12-07

### Changed
- Refined homepage newsletter form styling for better grid integration with reduced padding and compact layout
- Updated homepage form preset to use simpler "Subscribe" heading and shorter description
- Removed standalone newsletter grid display function (now handled by blog plugin)
- Modified admin asset loading to only enqueue on newsletter settings page
- Updated newsletter archive description for more concise messaging

### Fixed
- Improved visual consistency of newsletter forms across different display contexts

## [0.1.4] - 2025-12-05

### Changed
- Improved JavaScript error handling with better HTTP response validation and specific error messages
- Enhanced user feedback by always clearing email field and setting localStorage on successful subscription
- Refactored script localization to occur after enqueuing for better code organization

### Fixed
- Removed console error logging for cleaner production code
- Improved form submission reliability with consistent success handling

## [0.1.5] - 2025-12-05

### Changed
- Centralized asset loading by moving script enqueueing and localization to `inc/core/assets.php`
- Improved JavaScript error handling with validation for required REST nonce parameters

### Fixed
- Enhanced code organization for better maintainability of asset management

## [0.1.3] - 2025-12-04

### Added
- Newsletter subscription form display after single post content on all sites

### Fixed
- Corrected all @since version tags throughout codebase to reflect accurate release history (0.1.0 initial, 0.1.2 current)

## [0.1.2] - 2025-12-04

### Removed
- Festival wire tip submission system completely removed (form template, display functions, CSS loading, documentation)

### Changed
- Refactored JavaScript to use generic form handler with data attributes instead of specific form handlers
- Updated all subscription form templates to use `data-newsletter-form` and `data-newsletter-context` attributes
- Removed individual form IDs, action fields, and nonce fields from templates
- Changed homepage template from full page override to content-only inclusion via action hook
- Updated asset loading to remove festival wire CSS conditions
- Cleaned up documentation by removing festival wire references

### Fixed
- Improved form handling consistency across all subscription forms
- Enhanced maintainability of form JavaScript with single generic handler

## [0.1.1] - 2025-12-01

### Changed
- Refactored JavaScript from jQuery to vanilla JS for better performance and reduced dependencies
- Switched subscription forms from admin-ajax.php to REST API endpoints for improved security and maintainability
- Optimized asset loading with on-demand enqueuing strategy to reduce page load times
- Cleaned up CSS variables by removing fallback values for cleaner, more maintainable stylesheets
- Updated documentation for new architecture patterns and asset loading strategy
- Removed unused AJAX handlers file as functionality moved to REST API endpoints

### Fixed
- Improved error handling in subscription forms with better user feedback
- Enhanced CSS variable consistency across all stylesheet files

## [0.1.0] - 2025-11-XX

### Added
- Initial release with organized modular architecture
- Extracted newsletter functionality from ExtraChill theme
- Complete Sendy integration with centralized API configuration
- Multiple subscription forms (navigation, homepage, archive, content, footer)
- Template override system with plugin fallback
- Homepage-as-archive pattern for newsletter.extrachill.com
- Admin campaign management with push-to-Sendy functionality
- Integration system with declarative form registration
- Enhanced security with nonce verification and input sanitization
- Festival wire tip submission system with anti-spam protection
- Cloudflare Turnstile integration for spam prevention
- Rate limiting for tip submissions
- Centralized asset management with conditional loading
- Custom breadcrumb and post meta customization
- Sidebar widget for recent newsletters
- Responsive design and mobile optimization</content>
<parameter name="filePath">docs/CHANGELOG.md
