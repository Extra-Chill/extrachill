# JavaScript Architecture

The plugin uses modern JavaScript patterns organized by functionality across different contexts (public, management, and blocks).

## Organization

### Gutenberg Block System (React)

**Location**: `src/blocks/`

Modern React-based components providing five Gutenberg blocks for comprehensive platform management:

#### 1. Link Page Editor Block

**Location**: `src/blocks/link-page-editor/`

Complete React-based component for Gutenberg block editor providing link page editing interface with live preview.

**Component Structure**:
- **Editor.js**: Main editor container managing tabs and state
- **Preview.js**: Live preview component showing real-time updates
- **JumpToPreview.js**: Mobile navigation button to jump to preview panel
- **Tab Components** (`tabs/`):
  - `TabInfo.js`: Artist info, title, and biography editing
  - `TabLinks.js`: Link management with drag-and-drop reordering
  - `TabCustomize.js`: Styling options (fonts, colors, backgrounds)
  - `TabAdvanced.js`: Advanced settings (tracking, expiration, YouTube)
  - `TabSocials.js`: Social platform link management
- **Shared Components** (`shared/`): ColorPicker, ImageUploader, DraggableList, LinkPageUrl, QRCodeModal
- **Context System**: EditorContext and PreviewContext for state management
- **Custom Hooks**: useArtist, useLinks, useMediaUpload, useSocials
- **REST API Integration**: Centralized API client for all block requests

#### 2. Link Page Analytics Block

**Location**: `src/blocks/artist-analytics/`

Separate analytics dashboard block providing comprehensive performance metrics for link pages.

**Features**:
- Chart.js-powered analytics dashboard
- Daily page view aggregation
- Link click tracking and breakdown
- Date range filtering
- Visual performance metrics
- Standalone block registration for dedicated analytics interface

**Component Structure**:
- **Analytics.js**: Main analytics dashboard component
- **ArtistSwitcher.js**: Artist context switching for multi-artist management
- **Context System**: AnalyticsContext for data management
- **Custom Hooks**: useAnalytics for analytics data
- **REST API Integration**: API client for analytics data endpoints

#### 3. Artist Manager Block

**Location**: `src/blocks/artist-manager/`

Complete artist profile management interface providing profile editing, roster management, and subscriber administration.

**Features**:
- Artist information and biography editing
- Profile image upload and management
- Roster/member management with invitation system
- Subscriber list management and export
- Social link management
- Tab-based interface for organized management

**Build Configuration**:
- **Webpack**: `webpack.config.js` - Compiles React and SCSS for all blocks
- **wp-scripts**: WordPress build tooling for React/Webpack integration
- **Compiled Output**: `build/blocks/*/` - Generated assets for each block
- **Asset Enqueuing**: Auto-detected via `register_block_type()` manifest for each block
- **Build Process**: `npm run build` compiles all blocks to production bundle

### Public Interface Scripts

**Location**: `inc/link-pages/live/assets/js/`
- `link-page-public-tracking.js` - Analytics and click tracking
- `link-page-subscribe.js` - Subscription form functionality
- `link-page-youtube-embed.js` - YouTube video embed handling
- `extrch-share-modal.js` - Native Web Share API with social fallbacks
- `link-page-edit-button.js` - Permission check and button rendering

### Artist Profile Management

Artist profile management is handled exclusively via the Artist Manager block (`src/blocks/artist-manager/`).

**Location**: `inc/artist-profiles/assets/js/`
- `artist-members-admin.js` - Backend member administration

### Global Components

**Location**: `assets/js/`
- `artist-platform.js` - Core plugin functionality
- `artist-platform-home.js` - Homepage-specific features

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
// Centralized API client in src/blocks/shared/api/client.js
const response = await apiClient.post( '/extrachill/v1/link-pages/{id}', data );
```

### Form Serialization

Complex data structures handled via:
- **Gutenberg block attributes** (primary modern approach)
- **JSON meta fields** (persistent storage)

## Build System

**Webpack Configuration** (`webpack.config.js`):
- Compiles React JSX and modern JavaScript
- SCSS preprocessing for component styles
- Production and development modes

**Build Commands**:
```bash
npm run build    # Production bundle
npm run start    # Development with watch mode
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
