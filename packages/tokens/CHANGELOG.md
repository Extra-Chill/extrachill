# Changelog

## [0.6.0]

### Added
- add bridges system for mapping EC tokens to plugin-namespaced CSS custom properties
- add Data Machine Frontend Chat bridge (--datamachine-* properties)

## [0.6.0] - 2026-04-02

### Added
- add interactive state, motion, font-weight, warning, and info tokens

## [0.5.0] - 2026-03-31

### Added
- add block-editor.css — shared Gutenberg theming via EC tokens

### Changed
- Add Queens location badge
- slim block-editor.css to EC-specific branding only

### Fixed
- use correct slug for Washington D.C. badge token

## [0.4.1] - 2026-03-23

### Fixed
- split duplicate location-washington into location-washington-state and location-washington-dc

## [0.4.0] - 2026-03-23

### Added
- add badge colors for 96 new cities

## [0.3.3] - 2026-03-22

### Fixed
- rename location-new-york badge to location-new-york-city

## [0.2.0] - 2026-03-06

### Changed
- Add badge color tokens, root.css generator, and cssRef support
- Remove private flag, fix theme migration guidance in README
- Initial scaffold: design tokens extracted from theme root.css

## [0.1.0] - 2026-03-05

- Initial release
- 57 design tokens across 7 categories: color, typography, font-size, border-radius, layout, line-height, spacing
- CSS custom properties (light, dark, combined)
- TypeScript constants with full type safety
- JSON source of truth (tokens.json)
- Helper functions: cssVar(), getAllCssVarNames()
- Validation script for JSON/CSS sync
