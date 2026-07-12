# Extra Chill News Wire Documentation

## Architecture

Extra Chill News Wire provides a specialized platform for automated festival news. It is designed to run on a dedicated multisite instance (`wire.extrachill.com`, Blog ID 11) to isolate high-volume automated feeds from main editorial content.

### Core Components

1.  **Festival Wire CPT (`festival_wire`)**: The primary content container for news items.
2.  **Taxonomy System**: Attaches the network-owned `festival` and `location` taxonomies to Wire stories.
3.  **Wire Hub Homepage**: A dedicated template (`home-wire.php`) that serves as the entry point for the wire site.
4.  **Data Machine Pipelines**: Fetch, AI, and publish steps create Wire stories while retaining execution evidence outside this plugin.

### Multisite Integration

- **Blog ID**: 11 (wire.extrachill.com)
- **Shared Authentication**: Uses network-wide WordPress multisite authentication.
- **Dynamic URLs**: Uses `ec_get_site_url('community')` and other helpers for cross-site navigation.

## Templates

The plugin implements a robust template override system:

-   `archive-festival_wire.php`: Grid-based archive with native pagination.
-   `single-festival_wire.php`: Clean single post view with metadata.
-   `home-wire.php`: The homepage for the wire domain, showcasing latest updates.
-   `content-card.php`: Modular component used across archives and homepage.

## Provenance

Machine-published Wire stories use Data Machine's generic source URL, handler, flow, processed-item, and job-artifact primitives. News Wire owns story-level editorial and deduplication policy rather than duplicating that infrastructure.

See [provenance.md](provenance.md) for the required publication evidence, source-truth boundary, and identity rules.

## Frontend Integration

The plugin enqueues specific assets (`festival-wire.css`, `festival-wire.js`) only when the `festival_wire` post type is active or when on the wire site homepage. It integrates with the theme's breadcrumb and pagination systems.
