## Gutenberg Block Editor System

The Extra Chill Artist Platform provides management through Gutenberg blocks. The Link Page editor block is owned by `extrachill-link-pages`; Artist Platform plugs into it with an adapter.

### 1. Link Page Editor (owned by `extrachill-link-pages`)

The `extrachill/link-page-editor` block and its React UI live in `plugins/extrachill-link-pages/src/editor/`. Artist Platform does not register or ship its own copy of the editor.

Artist Platform contributes only the artist owner adapter:

- `src/blocks/link-page-editor/adapter.js` registers the artist adapter with the portable editor and routes artist, links, and socials saves through the Artist Platform REST APIs (`src/blocks/shared/api/client.js`).
- `inc/link-pages/editor-adapter.php` hooks `ec_link_page_editor_configuration`, enqueues `build/blocks/link-page-editor/adapter.js` (the `blocks/link-page-editor/adapter` webpack entry), and supplies artist identities, fonts, social types, and management links.
- `inc/link-pages/manage-link-page-routing.php` sends owners from `/manage-link-page/` to their editor and replaces the editor block with a "Create Link Page" action when an artist has no Link Page yet. Provisioning stays mutation-only (nonce-checked POST).

### 2. Artist Analytics Block (`src/blocks/artist-analytics/`)

Dedicated Gutenberg block for viewing comprehensive link page analytics.

**Location**: `src/blocks/artist-analytics/`

**Block Registration**: Automatically registered for analytics dashboard

**Features**:
- Chart.js-powered analytics dashboard
- Daily page view aggregation
- Link click tracking and breakdown
- Date range filtering
- Artist context switching for multi-artist management

---

### 3. Artist Manager Block (`src/blocks/artist-manager/`)

Complete artist profile management interface.

**Location**: `src/blocks/artist-manager/`

**Block Registration**: Automatically registered on `artist_profile` post type

**Features**:
- Artist information and biography editing
- Profile image upload and management
- Social link management (integrated with social platform system)
- Roster/member management with team invitations
- Subscriber list management and export

---

### 4. Artist Creator Block (`src/blocks/artist-creator/`)

Dedicated Gutenberg block for artist profile creation.

**Location**: `src/blocks/artist-creator/`

**Features**:
- Guided artist profile creation with user permission checks
- Profile metadata initialization (name, biography, images)
- User prefill from authenticated context
- Automatic link page creation for new profiles
- REST API integration for save operations

---

---

## Build Process

```bash
npm run start   # Development build with watch mode
npm run build   # Production build into build/blocks/
```

Entries are declared in `webpack.config.js`. Each block (`artist-analytics`, `artist-manager`, `artist-creator`) builds `index` and `view` bundles next to a copied `block.json`; the Link Page adapter builds to `build/blocks/link-page-editor/adapter.js` with its `adapter.asset.php`.

Blocks are registered from the build directory in `extrachill_artist_platform_register_blocks()`.
