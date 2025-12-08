# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v1.0.1
- Improved error handling: Replaced URL parameter-based error messages with proper notice system using `extrachill_set_notice()`
- Streamlined contact form subject options: Removed Press/Media, Artist Platform Support, Community Forum Support, and Account/Login Support options
- Updated CSS styling: Changed submit button colors from CSS variables to hardcoded values for better compatibility
- Documentation improvements: Restructured README with development status section and moved changelog to dedicated docs directory
- Code cleanup: Removed deprecated `display_contact_success_message()` function that displayed inline success/error messages
- Build system updates: Updated build output documentation to reflect current process

## v1.0.0
- Initial release
- Contact form with shortcode support
- HTML email templates with Extra Chill branding
- Sendy newsletter integration
- Cloudflare Turnstile protection
- Responsive CSS styling
- Build system for production deployment