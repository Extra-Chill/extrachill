# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

The **ExtraChill Contact Plugin** is a WordPress plugin that provides contact form functionality with Sendy newsletter integration and HTML email templates. Extracted from the ExtraChill theme to create a modular, reusable contact system.

## Common Development Commands

### Building and Deployment
```bash
# Create production-ready ZIP package
./build.sh

# Output: dist/extrachill-contact-{version}.zip
```

The build script automatically:
- Extracts version from main plugin file
- Copies files excluding items in `.buildignore`
- Validates plugin structure and required files
- Creates optimized ZIP for WordPress deployment

## Plugin Architecture

### Modular Structure
The plugin follows a clean, focused architecture:

- **extrachill-contact.php**: Main plugin file with constants, asset loading, and initialization
- **includes/contact-form-core.php**: Complete contact form functionality including email templates
- **assets/contact-form.css**: Contact form styling that integrates with theme styles

### Key Features

#### Contact Form System
- **Shortcode**: `[ec_custom_contact_form]` provides complete contact form
- **Form Fields**: Name, email, subject dropdown, message, newsletter consent checkbox
- **Security**: WordPress nonce verification and Cloudflare Turnstile captcha protection
- **Responsive Design**: Mobile-friendly form styling with accessibility features

#### Email Templates
Two complete HTML email templates with Extra Chill branding:

1. **Admin Notification Email**
   - Clean HTML format with contact details
   - Reply-To header set to user email
   - Subject format: "New submission: [subject]"

2. **User Confirmation Email**
   - Branded Extra Chill messaging
   - Community forum promotion and links
   - Personalized greeting and message summary
   - Subject: "Extra Chill Got Your Message"

#### Sendy Newsletter Integration
- **Optional Subscription**: Newsletter consent checkbox
- **Integration**: Uses existing `subscribe_email_to_sendy()` function from ExtraChill Newsletter plugin
- **List Management**: Subscribes to 'contact' list in Sendy
- **Error Handling**: Graceful fallback if newsletter plugin unavailable

#### Security Implementation
- **Nonce Verification**: All form submissions use WordPress nonce system
- **Cloudflare Turnstile**: Captcha protection with hardcoded site/secret keys
- **Input Sanitization**: All user input sanitized with `wp_unslash()` and appropriate functions
- **XSS Protection**: Email templates use `htmlspecialchars()` for user content

### Asset Loading Strategy
- **Conditional CSS Loading**: Only on contact-us page via `is_page('contact-us')`
- **Turnstile Script**: Loaded conditionally on contact-us page
- **File-based Versioning**: Uses `filemtime()` for cache busting
- **Theme Integration**: CSS designed to work with existing theme styles

### Form Processing Flow
1. **Form Submission**: Standard WordPress `admin_post` handler
2. **Security Validation**: Nonce and Turnstile verification
3. **Data Sanitization**: Input cleaning and validation
4. **Email Sending**: Admin notification and user confirmation emails
5. **Newsletter Sync**: Optional Sendy subscription if consented
6. **Redirect**: Back to contact page with success message

### Database Architecture
**No Database Tables**: Plugin uses wp_mail system only (contact submissions table removed as requested)
- Eliminates database complexity
- Relies on proven WordPress email system
- Reduces maintenance overhead

## Integration Patterns

### Theme Integration
- **Shortcode System**: Works with any WordPress theme
- **Template Compatibility**: Contact page template uses `the_content()` to process shortcode
- **Style Integration**: CSS classes designed to inherit theme styles
- **No Template Modifications**: Plugin shortcode automatically works

### Newsletter Plugin Integration
- **Loose Coupling**: Checks for function existence before calling
- **API Contract**: Uses existing `subscribe_email_to_sendy($email, 'contact')` interface
- **Error Handling**: Silent failure if newsletter plugin not available
- **List Management**: Maintains 'contact' list separation

### WordPress Standards Compliance
- **Hook Integration**: Proper use of WordPress actions and filters
- **Sanitization**: All input/output properly sanitized and escaped
- **Capability Checks**: No admin functionality reduces security surface
- **Translation Ready**: All strings properly internationalized

## Development Guidelines

### File Organization
- Main plugin file handles WordPress integration and asset loading
- Core functionality isolated in includes directory
- Assets directory contains production-ready CSS
- Build system creates clean distribution packages

### Security Practices
- Never modify database table creation (removed per requirements)
- Maintain existing Turnstile key structure
- Use WordPress sanitization functions exclusively
- Escape all output in email templates

### Email Template Development
When modifying email templates:
- Preserve Extra Chill branding and messaging
- Maintain community forum promotion links
- Keep HTML structure for proper rendering
- Test with various email clients

### Form Customization
When extending form functionality:
- Add new fields to shortcode HTML
- Include proper sanitization in processing function
- Update email templates to include new data
- Maintain accessibility and responsive design

## Build Process

The build process creates production-ready WordPress plugin packages:

1. **Version Extraction**: Automatically reads version from plugin header
2. **File Exclusion**: Uses `.buildignore` patterns to exclude development files
3. **Structure Validation**: Ensures all required plugin files are present
4. **ZIP Creation**: Generates versioned ZIP file for WordPress deployment

Essential files for plugin functionality:
- Main plugin file with proper WordPress headers
- `/includes/contact-form-core.php` with all contact functionality
- `/assets/contact-form.css` with form styling

## Migration from Theme

This plugin was extracted from the ExtraChill theme with the following changes:
- **Database Table Removal**: Contact submissions table eliminated (wp_mail only)
- **Modular Architecture**: Self-contained plugin structure
- **Asset Integration**: CSS extracted and optimized for conditional loading
- **Security Preservation**: All existing security measures maintained
- **Email Template Preservation**: Complete HTML email templates with branding maintained

The migration maintains 100% functionality while removing database complexity and creating a reusable, modular contact system.

## User Info

- Name: Chris Huber
- Dev website: https://chubes.net
- GitHub: https://github.com/chubes4
- Founder & Editor: https://extrachill.com
- Creator: https://saraichinwag.com