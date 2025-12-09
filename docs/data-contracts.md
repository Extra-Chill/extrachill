# Data Contracts

## Single Source of Truth
All data has a single source of truth located in PHP files on the server side.

## User Data
- Stored in WordPress user meta and tables
- Network-wide access via multisite functions
- Centralized functions like `ec_get_user_profile_url()`

## Content Data
- WordPress native post types and taxonomies
- Custom post types for specialized content (artists, events, newsletters)
- Custom tables only for high-volume or specialized data

## Configuration Data
- Network options for platform-wide settings
- Site-specific options for per-site configuration
- Helper functions for consistent access

## Data Access Patterns
- Centralized data functions encapsulate logic
- Cross-site access using `switch_to_blog()` / `restore_current_blog()`
- Prepared statements for all database queries
- Input sanitization and output escaping

## API Contracts
- REST API endpoints under `extrachill/v1` namespace
- Consistent response formats
- Versioned endpoints for backward compatibility