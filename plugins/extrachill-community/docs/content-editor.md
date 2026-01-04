# Content Editor Features

Enhanced content creation tools for bbPress forums including dual editor support (Gutenberg blocks + TinyMCE), image uploads, and content filtering.

## Editor Architecture

### Gutenberg-Anywhere Standard
The community platform standardizes on a "Gutenberg-anywhere" architecture, utilizing the Blocks Everywhere integration as the primary content creation tool. This replaces the legacy TinyMCE editor for all modern forum interactions.

**Primary Editor**: Blocks Everywhere (Gutenberg)
- **Status**: Required platform standard.
- **Integration**: Enabled via `inc/content/editor/blocks-everywhere.php`.
- **Visual Accuracy**: Theme and plugin styles are enqueued into the editor iframe via the `blocks_everywhere_enqueue_iframe_assets` hook, providing a true WYSIWYG experience.
- **Contexts**: Active for bbPress topics, replies, and the inline reply system.

**Fallback Editor**: TinyMCE
- **Status**: Legacy fallback only.
- **Guard**: Automatically disabled when Blocks Everywhere is active.
- **Purpose**: Maintained strictly for environments where the modern block editor cannot load.

## Blocks Everywhere Integration (Gutenberg)

### Editor Integration Details
The integration is deeply hooked into the platform's asset and filtering systems:

1. **Theme Styling**: The extrachill theme enqueues `root.css` and `style.css` into the editor iframe.
2. **Community Styling**: The community plugin enqueues `bbpress.css` into the iframe to ensure forum elements are styled correctly within the editor.
3. **Block Control**: Block types are filtered to include Paragraphs, Headings, and Embeds while explicitly disabling security-sensitive blocks like Code.
4. **Inline Replies**: The block editor is integrated into the AJAX-powered inline reply system for a seamless conversational experience.

### Block Editor Features
Modern Gutenberg block editor integration for bbPress forums with enhanced content creation capabilities.

**Available Block Types**:
- **Paragraph**: Default text content with full formatting options
- **Heading**: Content structure with H1-H6 heading levels
- **Embed**: Media embeds (YouTube, SoundCloud, Twitter, etc.)

**Security Restrictions**:
- Code blocks are disabled for forum security
- Block types filtered through WordPress KES for content safety
- All blocks processed through bbPress content filtering pipeline

**User Experience**:
- Drag-and-drop block reordering
- Inline formatting controls
- Media library integration
- Real-time preview of embedded content
- Mobile-responsive editing interface

**Content Processing Pipeline**:
```
bbp_get_topic_content() → autoembed() → do_blocks() → wp_kses_post() → output
```

## TinyMCE Editor Customization (Fallback)

### Visual Editor Integration
Traditional TinyMCE rich text editor integration for bbPress topic and reply forms with custom styling and functionality.

**Editor Features**:
- **Visual Editing**: WYSIWYG editor with formatting toolbar
- **Custom Styling**: Integrated CSS from `tinymce-editor.css` for consistent appearance
- **Paste Cleanup**: Automatic cleanup of pasted content with style removal
- **Image Support**: Native TinyMCE image insertion and upload support

**Toolbar Configuration:**
- **Available Buttons**: Bold, italic, image, blockquote, link/unlink, undo/redo, format select
- **Context Aware**: Enhanced toolbar only active on bbPress forms and artist profile pages
- **Simplified Interface**: Streamlined button set focused on essential formatting

### Autosave Functionality
Automatic draft saving with server-backed storage available for both editor types.

**Autosave Behavior**:
- **Trigger Delay**: 1.5 seconds after typing stops
- **Draft Storage**: Server-side storage with unique prefixes
- **Retention**: 30-day draft retention period
- **Cleanup**: Automatic draft removal on successful form submission

**Technical Implementation**:
- Custom autosave plugin integrated with bbPress draft system
- Debounced saving to prevent excessive storage operations
- Error handling for failed save operations
- Works with both Gutenberg and TinyMCE content types

### Image Upload Integration
Universal image upload functionality compatible with both editor systems.

**Upload Features**:
- **Drag & Drop**: Direct image drag-and-drop into editor
- **File Browser**: Integration with WordPress media library
- **Automatic Insertion**: Uploaded images automatically inserted at cursor position
- **Security**: Nonce-based upload verification
- **REST API**: Uses unified `/wp-json/extrachill/v1/media` endpoint

**Implementation Details**:
- Universal upload handling works with Gutenberg and TinyMCE
- File validation and security checks
- Integration with WordPress media handling functions
- Responsive image processing and optimization

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
- Providing modern block-based editing through Blocks Everywhere (Gutenberg) with TinyMCE fallback
- Ensuring content security through comprehensive filtering and block type restrictions
- Supporting multimedia content with universal image upload system
- Maintaining content integrity with server-backed autosave functionality
- Enabling seamless cross-site content integration
- Offering mobile-responsive editing experiences across all devices

These features work together to create a robust, user-friendly content creation environment that balances modern editing capabilities with security and performance requirements.</content>
<parameter name="filePath">docs/content-editor.md