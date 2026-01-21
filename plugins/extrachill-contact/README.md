# ExtraChill Contact

A WordPress plugin that provides contact form functionality with Sendy newsletter integration and HTML email templates for the ExtraChill platform.

## Development Status

- **Maintained**: The contact form plugin is stable; ongoing maintenance focuses on keeping the Sendy/Turnstile integrations synced with platform-wide credentials.
- **Security-first**: Nonce/Turnstile checks and escape/escaping logic are prioritized before any feature shifts.

## Features

- **Contact Form Shortcode**: `[ec_custom_contact_form]` provides a complete contact form
- **HTML Email Templates**: Branded email templates for admin notifications and user confirmations
- **Newsletter Integration**: Optional Sendy subscription via ExtraChill Newsletter plugin
- **Security Protection**: Cloudflare Turnstile captcha and WordPress nonce verification
- **Responsive Design**: Mobile-friendly styling with accessibility features
- **No Database Dependencies**: Uses WordPress `wp_mail` system exclusively

## Build + deployment

Build the production ZIP with `./build.sh` (symlinked to `/.github/build.sh`).

Deployments and remote operations run through **Homeboy** (`homeboy/` in this repo).

The build artifact is `build/extrachill-contact.zip`.
### Migration from Theme
If you previously used the ExtraChill theme's built-in contact form, you should manually drop the old database table:

```sql
DROP TABLE IF EXISTS wp_contact_submissions;
```

The plugin uses WordPress `wp_mail` exclusively and does not create or use any database tables.

### Local development

Local development runs in a WordPress environment with the plugin present (e.g., via a checkout or symlink). This repo’s build system is `./build.sh` and produces a ZIP under `/build/`.

```bash
# Create production ZIP package
./build.sh
```

## Usage

### Basic Contact Form
Add the contact form to any page or post using the Gutenberg block:

1. Edit a page or post in the WordPress block editor
2. Search for "Contact Form" in the block inserter
3. Insert the block and configure the settings

### Contact Page Setup
1. Create a page with slug `contact-us`
2. Add the Contact Form block to the page via the block editor
3. The plugin will automatically load assets on pages with the block

### Form Fields
- **Name** (required)
- **Email** (required)
- **Subject** (required dropdown):
  - General Inquiry
  - Press/Media
  - Festival Submission
  - Partnership
  - Technical Issue
  - Other
- **Message** (required textarea)
- **Newsletter Subscription** (optional checkbox)

## Configuration

### Required Setup
- **Admin Email**: Uses WordPress `get_option('admin_email')` for notifications
- **Cloudflare Turnstile**: Hardcoded site key `0x4AAAAAAAPvQsUv5Z6QBB5n`

### Newsletter Integration
To enable newsletter subscriptions, install the ExtraChill Newsletter plugin from the `extrachill-plugins/extrachill-newsletter/` directory. The contact form will automatically detect and integrate with it.

### Email Templates
The plugin includes two HTML email templates with Extra Chill branding:

#### Admin Notification
- **To**: WordPress admin email
- **Subject**: "New submission: [subject]"
- **Content**: Contact details with reply-to set to user email

#### User Confirmation
- **To**: Contact form user
- **Subject**: "Extra Chill Got Your Message"
- **Content**: Branded message with community forum promotion

## Security Features

- **WordPress Nonces**: All form submissions protected with nonce verification
- **Cloudflare Turnstile**: Captcha protection prevents automated submissions
- **Input Sanitization**: All user input sanitized with `wp_unslash()` and WordPress functions
- **Output Escaping**: Email content properly escaped to prevent XSS

## Styling

The plugin includes responsive CSS that works with most WordPress themes:
- Clean form styling with focus states
- Mobile-optimized with proper touch targets
- Success message styling
- Error state indicators

CSS is conditionally loaded only on pages containing the contact form.

## Development

### Build System
```bash
# Create production ZIP package
./build.sh

# Output: Only /build/extrachill-contact.zip file
```

### File Structure
```
extrachill-contact/
├── extrachill-contact.php          # Main plugin file
├── includes/
│   └── email-functions.php         # Email handling functions
├── blocks/
│   └── contact-form/
│       ├── block.json              # Block registration
│       └── render.php              # Frontend rendering
├── src/                            # TypeScript/React source
│   ├── ContactForm.tsx             # Main React component
│   └── ContactForm.css             # Component styles
├── assets/                         # Built output (from Vite)
│   ├── contact-form.css            # Compiled styles
│   └── contact-form.iife.js        # Compiled React component
├── build.sh                        # Build script
├── .buildignore                    # Build exclusions
├── CLAUDE.md                       # AI agent documentation
└── README.md                       # This file
```

### WordPress Hooks
- `init`: Block registration via `register_block_type()`
- REST API endpoint for form submission via extrachill-api

## Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **Optional**: ExtraChill Newsletter plugin for subscription integration

## Integration

### Theme Integration
Works with any WordPress theme that supports shortcodes. No template modifications required.

### Plugin Integration
- **ExtraChill Newsletter**: Automatic integration for newsletter subscriptions
- **Cloudflare**: Uses Turnstile service for captcha protection

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature-name`
3. Make your changes and test thoroughly
4. Commit with descriptive messages
5. Push to your fork and create a Pull Request

### Development Guidelines
- Follow WordPress coding standards
- Maintain security best practices
- Test email functionality thoroughly
- Ensure mobile responsiveness

## Changelog

See [docs/CHANGELOG.md](docs/CHANGELOG.md) for full version history.

## License

GPL v2 or later - see [LICENSE](LICENSE) file for details.

## Support

For support and issues, contact the development team or submit issues through the project documentation.

## Credits

Created by [Chris Huber](https://chubes.net) for the [Extra Chill](https://extrachill.com) platform.