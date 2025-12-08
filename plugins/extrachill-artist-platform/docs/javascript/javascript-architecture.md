# JavaScript Architecture

The plugin uses modern JavaScript patterns organized by functionality across different contexts (public, management, and blocks).

## Organization

### Gutenberg Block System (React)

**Location**: `src/blocks/link-page-editor/`

Modern React-based components for Gutenberg block editor providing complete link page editing interface.

**Component Structure**:
- **Editor.js**: Main editor container managing tabs and state
- **Preview.js**: Live preview component showing real-time updates
- **JumpToPreview.js**: Mobile navigation button to jump to preview panel
- **Tab Components** (`tabs/`):
  - `TabInfo.js`: Artist info, title, and biography editing
  - `TabLinks.js`: Link management with drag-and-drop reordering
  - `TabCustomize.js`: Styling options (fonts, colors, backgrounds)
  - `TabAdvanced.js`: Advanced settings (tracking, expiration, YouTube)
  - `TabAnalytics.js`: Analytics dashboard with charts
  - `TabSocials.js`: Social platform link management
- **Shared Components** (`shared/`):
  - `ColorPicker.js`: Color selection interface
  - `ImageUploader.js`: Media upload handler
  - `DraggableList.js`: Drag-and-drop list component
  - `LinkPageUrl.js`: Display canonical link page URL
  - `QRCodeModal.js`: QR code generation and download

**Context System** (`context/`):
- **EditorContext.js**: Manages editor state and tab navigation
- **PreviewContext.js**: Manages preview data and live updates

**Custom Hooks** (`hooks/`):
- `useArtist.js`: Hook for artist data and metadata
- `useLinks.js`: Hook for link management and updates
- `useMediaUpload.js`: Hook for media upload handling
- `useSocials.js`: Hook for social platform management

**REST API Integration** (`api/`):
- `client.js`: Centralized API client for all block requests
- Handles image uploads, link page saves, and data fetching
- Automatic nonce handling and error management

**Build Configuration**:
- **Webpack**: `webpack.config.js` - Compiles React and SCSS
- **wp-scripts**: WordPress build tooling for React/Webpack integration
- **Compiled Output**: `build/blocks/link-page-editor/` - Generated assets
- **Asset Enqueuing**: Auto-detected via `register_block_type()` manifest
- **Build Process**: `npm run build` compiles source to production bundle

### Public Interface Scripts

**Location**: `inc/link-pages/live/assets/js/`
- `link-page-public-tracking.js` - Analytics and click tracking
- `link-page-subscribe.js` - Subscription form functionality
- `link-page-youtube-embed.js` - YouTube video embed handling
- `extrch-share-modal.js` - Native Web Share API with social fallbacks
- `link-page-edit-button.js` - Permission check and button rendering

### Artist Profile Management

**Location**: `inc/artist-profiles/assets/js/`
- `manage-artist-profiles.js` - Profile editing, image previews, roster management with AJAX
- `manage-artist-subscribers.js` - Subscriber list management
- `artist-members-admin.js` - Backend member administration

### Global Components

**Location**: `assets/js/`
- `artist-switcher.js` - Artist selection dropdown for switching contexts
- `artist-platform.js` - Core plugin functionality
- `artist-platform-home.js` - Homepage-specific features
- `artist-grid-pagination.js` - AJAX pagination for artist grid with smooth transitions

### Join Flow Interface

**Location**: `inc/join/assets/js/`
- `join-flow-ui.js` - Modal handling for existing vs new account selection

### Advanced Features

**Location**: `src/blocks/link-page-editor/`
- Link expiration scheduling
- Temporary redirect configuration  
- YouTube embed settings
- Subscription form configuration
- Google Tag Manager and Meta Pixel integration

## Core Patterns

### REST API Integration

**Client Pattern** (Gutenberg blocks):
```javascript
// Centralized API client in src/blocks/link-page-editor/api/client.js
const response = await apiClient.post( '/extrachill/v1/link-pages/{id}', data );
```

### Form Serialization

Complex data structures handled via:
- **Gutenberg block attributes** (primary modern approach)
- **JSON meta fields** (persistent storage)

### AJAX Integration

**WordPress Native Patterns**:
- `add_action('wp_ajax_*')` for action-based handlers
- Nonce verification in each handler
- Centralized permission checks via `inc/core/filters/permissions.php`

## Build System

**Webpack Configuration** (`webpack.config.js`):
- Compiles React JSX and modern JavaScript
- SCSS preprocessing for component styles
- Production and development modes

**Build Commands**:
```bash
npm run build    # Production bundle
npm run dev      # Development with watch mode
npm run lint     # Code linting
npm run format   # Code formatting
```

**Output**:
- Compiled assets: `build/blocks/link-page-editor/`
- Block manifest: `build/blocks/link-page-editor/block.json`
- Auto-enqueued by WordPress via `register_block_type()`

## Performance Optimization

### Conditional Asset Loading

Assets loaded based on context:
- **Join flow assets**: Only on login page with `from_join` parameter
- **Management assets**: Only on artist/link page management pages
- **Public assets**: Only on public link pages

### AJAX Optimization

- Nonce-based security
- Debounced updates for real-time features
- Centralized error handling

### Code Splitting

Gutenberg block system provides natural code splitting via:
- Separate block registration and enqueuing
- Context-specific component loading
- Lazy hook initialization

## Browser Compatibility

**Modern API Usage**:
- **Fetch API**: For modern browsers (no IE support)
- **Native Web Share**: Fallback to clipboard copy
- **Intersection Observer**: For lazy loading
- **ES6 modules**: Via Webpack transpilation

**Fallbacks**:
- Clipboard copy fallback for Web Share API
- Traditional form submission fallback
