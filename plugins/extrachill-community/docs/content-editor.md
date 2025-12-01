# Content Editor Features

Enhanced content creation tools for bbPress forums including rich text editing, image uploads, and content filtering.

## TinyMCE Editor Customization

### Visual Editor Integration
Full TinyMCE rich text editor integration for bbPress topic and reply forms with custom styling and functionality.

**Editor Features:**
- **Visual Editing**: WYSIWYG editor with formatting toolbar
- **Custom Styling**: Integrated CSS from `tinymce-editor.css` for consistent appearance
- **Paste Cleanup**: Automatic cleanup of pasted content with style removal
- **Image Support**: Native TinyMCE image insertion and upload support

**Toolbar Configuration:**
- **Available Buttons**: Bold, italic, image, blockquote, link/unlink, undo/redo, format select
- **Context Aware**: Enhanced toolbar only active on bbPress forms and artist profile pages
- **Simplified Interface**: Streamlined button set focused on essential formatting

### Autosave Functionality
Automatic draft saving with custom autosave plugin integration.

**Autosave Behavior:**
- **Trigger Delay**: 1.5 seconds after typing stops
- **Draft Storage**: Browser-based storage with unique prefixes
- **Retention**: 30-day draft retention period
- **Cleanup**: Automatic draft removal on successful form submission

**Technical Implementation:**
- Custom autosave plugin loaded from `bbpress/autosave/plugin.min.js`
- Debounced saving to prevent excessive storage operations
- Error handling for failed save operations

### Image Upload Integration
Seamless image upload functionality within the TinyMCE editor.

**Upload Features:**
- **Drag & Drop**: Direct image drag-and-drop into editor
- **File Browser**: Integration with WordPress media library
- **Automatic Insertion**: Uploaded images automatically inserted at cursor position
- **Security**: Nonce-based upload verification

**Implementation Details:**
- AJAX upload handling via `wp_ajax_handle_tinymce_image_upload`
- File validation and security checks
- Integration with WordPress media handling functions

## Content Filtering

### Input Sanitization
Comprehensive content filtering for forum posts and user-generated content.

**Filter Types:**
- **HTML Sanitization**: Removal of unauthorized HTML tags and attributes
- **Script Prevention**: Blocking of inline scripts and JavaScript injection
- **Link Validation**: Automatic link formatting and security checks
- **Content Length**: Reasonable limits on post content size

### Cross-Site Content Integration
Display of main site content within community profiles and feeds.

**Integration Features:**
- **Blog Comments Feed**: Cross-domain blog comment aggregation
- **Recent Activity**: Combined forum and blog activity streams
- **Content Expansion**: AJAX-based content loading for performance
- **Subforum Classes**: Dynamic CSS classes for forum categorization

## Usage Context

Content editor features enhance the forum posting experience by:
- Providing professional rich text editing capabilities
- Ensuring content security through comprehensive filtering
- Supporting multimedia content with image uploads
- Maintaining content integrity with automatic saving
- Enabling seamless cross-site content integration

These features work together to create a robust, user-friendly content creation environment that balances functionality with security.</content>
<parameter name="filePath">docs/content-editor.md