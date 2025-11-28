# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.4] - 2025-11-28

### Added
- Added Events Calendar link to main navigation menu before Festival Wire.
- Added Events Calendar link to footer menu in "The Latest" column.
- Added Events button to homepage hero section.

### Changed
- Default taxonomy badge colors changed to black and white for neutral styling.

## [1.0.3] - 2025-11-26

### Removed
- Removed `wpshapere_remove_dashicons_wordpress` function that was deregistering dashicons for non-logged-in users, preventing plugins from loading them.
- Removed dashicons dequeue from `extrachill_prevent_admin_styles_on_frontend` function.

## [1.0.2] - 2025-11-26

### Added
- Added `extrachill_override_related_posts_display` filter and `extrachill_custom_related_posts_display` action in `inc/single/related-posts.php` to allow plugins to completely override the related posts section.

### Changed
- Documentation updates and cleanup.

## [1.0.1] - 2025-11-25

### Removed
- Removed `add_target_blank_to_external_links` filter from `functions.php` to fix pagination issues and improve UX.
