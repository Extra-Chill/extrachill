# ExtraChill Contact

WordPress plugin providing contact form functionality with Sendy newsletter integration for the Extra Chill platform.

## Plugin Information

- **Name**: Extra Chill Contact
- **Version**: 2.0.1
- **Text Domain**: `extrachill-contact`
- **Author**: Chris Huber
- **Author URI**: https://chubes.net
- **License**: GPL v2 or later
- **Network**: false (site-activated)
- **Requires at least**: 6.0
- **Tested up to**: 6.4
- **Requires PHP**: 7.4

## Current Status

**Production Status**: Active contact form plugin
**Architecture**: Procedural WordPress pattern with Gutenberg block-based React form rendering
**Integration**: Sendy newsletter integration, Cloudflare Turnstile captcha

## Architecture

### Gutenberg Block-Based Form System

The plugin provides a complete contact form via Gutenberg block:

**Form Processing**: Uses REST API endpoints for secure form handling
**Headless Architecture**: React-based frontend with PHP backend
**Security**: WordPress REST API nonces + Cloudflare Turnstile captcha

### File Organization

```
extrachill-contact/
├── extrachill-contact.php          # Main plugin file
├── src/                            # TypeScript/React source
│   ├── ContactForm.tsx             # Main React component
│   ├── ContactForm.css             # Component styles (source)
│   ├── types.ts                    # TypeScript interfaces
│   ├── wordpress.tsx               # WordPress auto-mount entry
│   └── index.ts                    # Package exports
├── includes/
│   ├── email-functions.php         # Email handling functions
│   └── rest-api.php               # REST API endpoints
├── blocks/
│   └── contact-form/
│       ├── block.json             # Block registration
│       ├── render.php             # Frontend rendering
│       └── editor.js              # Block editor interface
├── assets/                         # Built output (from Vite)
│   ├── contact-form.css           # Compiled styles
│   └── contact-form.iife.js       # Compiled React component
├── docs/
│   └── CHANGELOG.md
├── package.json                    # Build tooling (Vite)
├── vite.config.ts                  # Vite configuration
└── tsconfig.json                   # TypeScript configuration
```

### Loading Pattern

**Conditional Assets**: CSS/JS loaded only on pages with contact form
**Shortcode Detection**: Plugin hooks into `wp_enqueue_scripts` to check for shortcode presence
**No Database**: Uses WordPress `wp_mail()` exclusively

## Contact Form Features

### Form Fields
- **Name** (required, text input)
- **Email** (required, email input)
- **Subject** (required, select dropdown):
  - General Inquiry
  - Press/Media
  - Festival Submission
  - Partnership
  - Technical Issue
  - Other
- **Message** (required, textarea)
- **Newsletter Subscription** (optional, checkbox)

### Email System

**HTML Email Templates**: Branded templates for admin and user notifications

**Admin Notification**:
- Recipient: WordPress admin email
- Subject: "New submission: [subject]"
- Content: Full contact details with reply-to header

**User Confirmation**:
- Recipient: Form submitter
- Subject: "Extra Chill Got Your Message"
- Content: Branded thank you message

### Newsletter Integration

**Sendy Integration**: Automatic detection of ExtraChill Newsletter plugin
**Subscription Flow**: Checkbox adds user to newsletter list on form submission
**Fallback**: Graceful degradation if newsletter plugin inactive

## Security Implementation

### Input Security
- **Nonce Verification**: WordPress nonces on all form submissions
- **Turnstile Captcha**: Cloudflare Turnstile prevents automated submissions
- **Input Sanitization**: `wp_unslash()` + WordPress sanitization functions
- **Email Validation**: Server-side email format validation

### Output Security
- **Email Escaping**: All user data properly escaped in emails
- **HTML Sanitization**: Template variables safely handled

## Asset Management

### Conditional Loading
Assets are loaded conditionally by the Gutenberg block system:
- Block CSS/JS only enqueued on pages containing the contact form block
- Uses WordPress block asset management via `register_block_type()`

### CSS Architecture
- Responsive design with mobile optimization
- Clean form styling with focus states
- Success/error message styling
- WordPress theme compatibility

## Form Processing Flow

### Submission Handling
1. **Security Checks**: Nonce and Turnstile verification
2. **Input Validation**: Required fields and email format
3. **Newsletter Subscription**: Optional Sendy integration
4. **Email Dispatch**: Admin notification + user confirmation
5. **Success Response**: User-friendly success message

### Error Handling
- **Validation Errors**: Field-specific error messages
- **Security Failures**: Generic error with logging
- **Email Failures**: Admin notification of delivery issues

## Integration Patterns

### Newsletter Plugin Detection
```php
// Graceful integration with optional dependency
if (function_exists('extrachill_newsletter_subscribe')) {
    // Add to newsletter
}
```

### Theme Compatibility
- Works with any theme supporting shortcodes
- No template overrides required
- Responsive CSS adapts to theme containers

## Development Standards

### Code Organization
- **Single Responsibility**: Core functionality in dedicated include file
- **WordPress Standards**: Full compliance with coding standards
- **Security First**: Input validation and output escaping throughout

### Build System
- **Build System**: Use `homeboy build extrachill-contact` for production builds
- **Vite Build**: TypeScript/React source compiled to IIFE bundle
- **Production Build**: Creates clean ZIP package (excludes `src/`, build configs)
- **File Exclusions**: Development files excluded via `.buildignore`

### Frontend Build
```bash
npm install        # Install dev dependencies
npm run build      # Compile src/ to assets/
npm run dev        # Watch mode for development
```

## Dependencies

### Required
- WordPress 5.0+
- PHP 7.4+

### Optional
- **ExtraChill Newsletter**: Sendy integration for subscriptions
- **Cloudflare Turnstile**: Captcha service (site key hardcoded)

## Troubleshooting

### Form Not Appearing
- Verify shortcode syntax: `[ec_custom_contact_form]`
- Check for plugin activation
- Review browser console for asset loading errors

### Emails Not Sending
- Verify WordPress email configuration
- Check spam folders
- Review server mail logs

### Newsletter Integration Issues
- Confirm ExtraChill Newsletter plugin is active
- Check Sendy configuration in newsletter plugin
- Verify subscription checkbox is checked

## Cross-References

**Platform Documentation**:
- [Root CLAUDE.md - Contact Forms](../../CLAUDE.md#extrachill-contact)
- [ExtraChill Newsletter CLAUDE.md](../extrachill-newsletter/CLAUDE.md) - Newsletter integration

**Related Files**:
- `/.github/build.sh` - Shared build script
- `package.json` - Frontend build tooling
- `.buildignore` - Build exclusions