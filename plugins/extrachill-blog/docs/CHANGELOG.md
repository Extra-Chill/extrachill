# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2025-12-05

### Added
- `/all` archive route for main blog with AI content exclusion
- Archive query modifications to exclude author ID 39 (AI-generated content)
- Archive template routing to theme's archive.php
- Query variable registration for `extrachill_blog_archive`

### Removed
- Blog button from homepage hero section

### Changed
- Updated archive routing @since tags to reflect 0.2.0

## [0.1.0] - 2025-11-XX

### Added
- Initial plugin release
- Secondary header navigation with category links (Latest, Interviews, Reviews)
- Homepage hero section with personalized welcome messages and site navigation buttons
- 3x3 grid layout for interviews and live music reviews
- Recent posts section with content exclusion logic
- Extrachill.link promotional section
- About section with community links
- Complete homepage styling with responsive design
- Conditional CSS loading for front page only
- Proper WordPress hooks integration