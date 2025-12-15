# Gutenberg Block Editor for Link Pages

Complete React-based Gutenberg block providing modern interface for link page editing.

## Overview

The Gutenberg block editor provides a streamlined, intuitive interface for artists to create and customize their link pages directly within the WordPress block editor.

**Location**: `src/blocks/link-page-editor/`

**Block Registration**: Automatically registered on `artist_link_page` post type via `register_block_type( __DIR__ . '/build/blocks/link-page-editor' )`

## Block Features

### Tab-Based Interface

The editor uses a tabbed interface with the following sections:

#### TabInfo
- Artist name and metadata editing
- Biography/description management
- Profile image upload and preview
- Direct preview updates as you type

#### TabLinks
- Add, edit, and organize links
- Drag-and-drop link reordering (dnd-kit integration)
- Link text and URL editing
- Section-based link organization
- Real-time preview updates

#### TabCustomize
- Font selection and customization
- Color picker for button colors, text colors, and backgrounds
- Background image upload and styling
- Button border radius adjustment
- Real-time styling preview

#### TabAdvanced
- Google Tag Manager ID tracking
- Meta Pixel (Facebook) tracking
- Link expiration scheduling
- YouTube embed URL configuration
- Custom redirect settings
- Subscription form settings

#### TabSocials
- 15+ social platform integration
- Add and manage social links
- Icon validation and selection
- Custom link labels
- Social link reordering

## Component Architecture

### Main Components

**Editor.js**: Main editor container managing tab state and overall layout

```javascript
// Features:
// - Tab navigation and state management
// - Mobile-responsive layout
// - Edit/preview panel arrangement
// - Save state synchronization
```

**Preview.js**: Live preview component showing real-time updates

```javascript
// Features:
// - Real-time rendering of changes
// - CSS variable application
// - Responsive preview sizing
// - Context-driven data updates
```

**JumpToPreview.js**: Mobile navigation button to jump to preview panel

```javascript
// Features:
// - Mobile-only visibility
// - Smooth scroll to preview
// - Visible when managing links/styles
```

### Tab Components

Each tab component handles specific functionality:

- **TabInfo.js**: Artist information editing
- **TabLinks.js**: Link management with drag-and-drop
- **TabCustomize.js**: Styling and appearance
- **TabAdvanced.js**: Advanced settings and tracking
- **TabSocials.js**: Social media integration

**Note**: Analytics dashboard moved to separate **Link Page Analytics Block** (`src/blocks/link-page-analytics/`) starting in v1.1.11. This provides a dedicated, standalone analytics interface outside the main editor.

### Shared Components

Reusable components used across tabs:

**ColorPicker.js**: Color selection interface
- RGB/Hex color input
- Preset color options
- Real-time color preview

**ImageUploader.js**: Media library integration
- Upload from media library
- Image preview
- Size validation
- Alt text management

**DraggableList.js**: Drag-and-drop list component
- dnd-kit integration (`@dnd-kit/core`, `@dnd-kit/sortable`)
- Touch-friendly mobile support
- Smooth animations
- Reorder callbacks

**LinkPageUrl.js**: Display canonical link page URL
- Shows extrachill.link/{artist-slug}
- Copy to clipboard functionality
- QR code integration link

**QRCodeModal.js**: QR code generation and download
- REST API-powered QR generation
- High-resolution output (1000px)
- PNG download functionality
- Modal interface

## State Management

### Context API

**EditorContext**: Manages editor-wide state

```javascript
// Provides:
// - currentTab: Currently active tab
// - setCurrentTab: Tab switching function
// - formData: All form data
// - setFormData: Form data updates
// - isDirty: Unsaved changes tracking
// - setIsDirty: Mark changes
```

**PreviewContext**: Manages preview-specific state

```javascript
// Provides:
// - previewData: Data for preview rendering
// - updatePreviewData: Update preview from form changes
// - cssVariables: Generated CSS variables
// - updateCssVariables: CSS variable updates
```

## Custom Hooks

### useArtist

Fetches and manages artist data and metadata.

```javascript
const { artist, loading, error } = useArtist( artistId );

// Returns:
// - artist: Artist profile data
// - loading: Data loading state
// - error: Error message if any
```

### useLinks

Manages link data and provides CRUD operations.

```javascript
const { links, addLink, removeLink, updateLink, reorderLinks } = useLinks( linkPageId );

// Operations:
// - addLink: Add new link to section
// - removeLink: Remove link by ID
// - updateLink: Update link properties
// - reorderLinks: Reorder links within sections
```

### useMediaUpload

Handles media library uploads and image management.

```javascript
const { uploadImage, isUploading, error } = useMediaUpload();

// Operations:
// - uploadImage: Upload file to media library
// - isUploading: Upload progress state
// - error: Upload error handling
```

### useSocials

Manages social platform links and settings.

```javascript
const { socials, addSocial, removeSocial, updateSocial } = useSocials( linkPageId );

// Operations:
// - addSocial: Add social platform link
// - removeSocial: Remove social link
// - updateSocial: Update social link properties
```

## REST API Integration

### API Client

**Location**: `src/blocks/shared/api/client.js`

Centralized API client for all block requests with automatic nonce handling and error management.

```javascript
// Usage examples:

// Get link page data
const data = await apiClient.get( `/extrachill/v1/link-pages/${linkPageId}` );

// Save link page
const response = await apiClient.post( `/extrachill/v1/link-pages/${linkPageId}`, formData );

// Upload media
const imageResponse = await apiClient.uploadMedia( file );

// Get analytics
const analytics = await apiClient.get( `/extrachill/v1/analytics/${linkPageId}` );

// Generate QR code
const qrCode = await apiClient.post( `/extrachill/v1/qrcode/${linkPageId}` );
```

### Error Handling

Automatic error handling and user feedback:

```javascript
// Errors propagated to UI
// - Network errors
// - Permission errors
// - Validation errors
// - Server errors

// User receives clear error messages
```

## Build Process

### Development

```bash
# Start development server with watch mode
npm run start

# Watches src/blocks/ directory
# Rebuilds on file changes
# Enables source maps for debugging
```

### Production

```bash
# Create production build
npm run build

# Minifies code
# Optimizes assets
# Outputs to build/blocks/
```

### Build Output

Compiled assets available at:
```
build/blocks/link-page-editor/
├── index.js                    # Main block script
├── index.css                   # Block styles
├── style.css                   # Front-end styles
└── block.json                  # Block metadata
```

### Webpack Configuration

**webpack.config.js** configures:
- React JSX compilation
- SCSS processing
- Asset bundling
- Code splitting
- Development/production optimization

## Styling

### SCSS Organization

**editor.scss**: Editor-specific styles
- Tab interface styling
- Form input styling
- Editor-only UI elements

**style.scss**: Front-end and editor styles
- Block container styles
- Responsive layout
- Component styling

### CSS Variables

Link page styling uses CSS variables for dynamic theming:

```javascript
// Set via TabCustomize
const cssVariables = {
    '--link-page-background-color': '#ffffff',
    '--link-page-text-color': '#000000',
    '--link-page-button-color': '#007cba',
    '--link-page-button-text-color': '#ffffff',
    '--link-page-title-font-family': 'Inter',
    '--link-page-body-font-family': 'Inter',
    '--link-page-button-border-radius': '8px',
    '--link-page-max-width': '400px'
};
```

## Management Interface

Link page management now uses the Gutenberg block on the `/manage-link-page` page (no query params). The legacy PHP interface has been removed.

## Integration with WordPress

### Block Registration

```php
// Automatic registration in main plugin
function extrachill_artist_platform_register_blocks() {
    register_block_type( __DIR__ . '/build/blocks/link-page-editor' );
}
add_action( 'init', 'extrachill_artist_platform_register_blocks' );
```

### Post Type Support

Block appears in Gutenberg editor for:
- `artist_link_page` post type
- Only when edit permissions present
- Automatically localized with current artist/link page

### Nonce and Security

Automatic nonce handling via API client:
- REST nonce included in all requests
- Permission checking on all endpoints
- Data sanitization and validation

## Mobile Experience

### Responsive Design

- Stacked layout on mobile
- Touch-friendly controls
- Optimized tab navigation

### Jump-to-Preview Button

Mobile-only feature helps with preview viewing:
- Appears on small screens only
- Smoothly scrolls to preview panel
- Useful when managing links or styling

## Performance Optimization

### Code Splitting

Webpack automatically splits code for optimal loading:
- Main block code
- Tab components lazy-loaded
- Large dependencies split into separate chunks

### Memoization

React components use memo to prevent unnecessary re-renders:
- Tab components memoized
- Preview component optimized
- Event handlers debounced

## Accessibility

### ARIA Labels

All form inputs include proper ARIA labels:
- Color inputs labeled
- Upload inputs accessible
- Tab navigation keyboard-accessible

### Keyboard Navigation

Full keyboard support:
- Tab between fields
- Enter to submit
- Escape to close modals

## Browser Support

Supports modern browsers:
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

Requires:
- ES6+ JavaScript support
- CSS Grid layout
- CSS Custom Properties (Variables)
- Fetch API

## Troubleshooting

### Block Not Appearing

1. Verify block build completed: `npm run build`
2. Check `build/blocks/link-page-editor/block.json` exists
3. Flush WordPress cache
4. Re-activate plugin

### Preview Not Updating

1. Check browser console for JavaScript errors
2. Verify CSS variables are applied correctly
3. Ensure PreviewContext is properly initialized
4. Check REST API is accessible

### Media Upload Issues

1. Verify WordPress media library is functional
2. Check file size limits
3. Ensure proper user permissions
4. Check media upload endpoint in browser console

### Styling Not Applied

1. Verify CSS variables are set in TabCustomize
2. Check preview container has CSS variable definitions
3. Ensure style.scss is compiled
4. Check for CSS specificity conflicts

## Development Guidelines

### Adding a New Tab Component

1. Create component in `src/blocks/link-page-editor/components/tabs/`
2. Accept props from Editor context
3. Dispatch updates via hooks
4. Add tab button in Editor.js
5. Include in build output

### Adding Custom Hook

1. Create in `src/blocks/link-page-editor/hooks/`
2. Use existing hooks internally
3. Return data and action functions
4. Document return values
5. Add to component usage

### Modifying API Client

1. Update `src/blocks/shared/api/client.js`
2. Add new endpoint method
3. Include nonce handling
4. Document usage
5. Test in browser DevTools Network tab

## Future Enhancements

Potential improvements:
- Template-based link page designs
- More drag-and-drop interfaces
- Advanced link analytics
- A/B testing interface
- Social platform scheduling
- Advanced permission controls
