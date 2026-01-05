# Extra Chill Analytics

Network-wide analytics tracking and reporting for the ExtraChill Platform WordPress multisite network.

## Overview

The ExtraChill Analytics plugin provides comprehensive view tracking and analytics reporting across all sites in the ExtraChill Platform multisite network. It tracks post views via asynchronous REST API requests to avoid blocking page loads, excluding preview views to maintain data accuracy. A network admin dashboard displays analytics data across all sites.

## Features

- **Unified Event Tracking**: Network-wide custom table for tracking newsletters, registrations, and more
- **Network-Wide Activation**: Plugin activated across all sites in the multisite network
- **Async View Tracking**: Uses `navigator.sendBeacon` and `fetch` with `keepalive` for non-blocking tracking
- **REST API Integration**: Tracks views via extrachill-api endpoint at `/extrachill/v1/analytics/view`
- **Preview Exclusion**: Automatically excludes preview mode from view counting
- **Post Meta Storage**: Stores view counts in WordPress post meta (`ec_post_views`)
- **Network Admin Dashboard**: React-powered analytics dashboard under Extra Chill Multisite menu
- **Asset Versioning**: Automatic cache busting using `filemtime()` on all enqueued assets
- **Conditional Loading**: Frontend tracking only loads on singular posts, admin dashboard only loads on analytics page

## Requirements

- WordPress Multisite
- PHP 7.4+
- WordPress 5.0+
- **Requires Plugin**: extrachill-api (REST API infrastructure for analytics endpoint)

## Installation

1. Upload `extrachill-analytics` directory to `wp-content/plugins/`
2. Network activate via "Extra Chill Multisite" → "Plugins" in network admin
3. Access analytics dashboard via "Extra Chill Multisite" → "Analytics" in network admin

## API Integration

This plugin integrates with the ExtraChill API (`extrachill-api`) plugin for the analytics tracking endpoint:

- **Track View** - `POST /wp-json/extrachill/v1/analytics/view` - Increment post view count
  - Request body: `{"post_id": 123}`
  - Automatically excludes preview mode
  - Stores count in `ec_post_views` post meta

## Development

See [AGENTS.md](AGENTS.md) for detailed development documentation, architectural patterns, and implementation details.

## Notes

This repo is a network plugin used inside the Extra Chill Platform multisite network. All analytics data is stored per-site using WordPress post meta, but can be aggregated network-wide via the analytics dashboard.
