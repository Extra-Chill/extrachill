# Extra Chill News Wire Documentation

## Architecture

Extra Chill News Wire provides a specialized platform for automated festival news. It is designed to run on a dedicated multisite instance (`wire.extrachill.com`, Blog ID 11) to isolate high-volume automated feeds from main editorial content.

### Core Components

1.  **Festival Wire CPT (`festival_wire`)**: The primary content container for news items.
2.  **Taxonomy System**: Uses `festival`, `category`, and `data_source` taxonomies for organization.
3.  **Wire Hub Homepage**: A dedicated template (`home-wire.php`) that serves as the entry point for the wire site.

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

## Migration Tools

Located under **Tools > Festival Wire Migration**:

-   **Tag Migration**: Converts standard post tags to the `festival` taxonomy.
-   **Author Migration**: Allows bulk reassignment of wire posts to specific users.

## Frontend Integration

The plugin enqueues specific assets (`festival-wire.css`, `festival-wire.js`) only when the `festival_wire` post type is active or when on the wire site homepage. It integrates with the theme's breadcrumb and pagination systems.
