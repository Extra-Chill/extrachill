# Changelog

## [0.2.0] - 2026-03-23

### Added
- add breadcrumb integration with network dropdown for studio site
- tag-based publish routing with cross-site post transfer
- Compose tab with isolated block editor for frontend publishing
- social draft system with admin approval workflow
- switch tabs to canonical Tabs component with ec-studio prefix
- add Chat tab with @extrachill/chat integration

### Changed
- single getPlatforms() call for socials tab

### Fixed
- use card-background instead of background-color for studio card and shell
- remove wp-components from frontend viewScript dependencies
- socials tab crash + chat CSS not loading
- rename webpack output from build/ to dist/ to avoid homeboy collision

## [0.1.3] - 2026-03-11

### Changed
- migrate Studio to shared React tabs

## [0.1.2] - 2026-03-11

### Changed
- add Studio Instagram comment reply interface
- add contextual Studio homepage notices
- move Studio homepage auth flow outside block

### Fixed
- fix Studio PHP lint cleanup issues

## [0.1.1] - 2026-03-11

### Changed
- refactor Studio app into nested tab modules
- add Homeboy component config for Studio
- Add @extrachill/tokens as dependency
- refactor Studio Instagram tab into modular Socials area
- use api client for Studio social workflows
- add initial Instagram publishing flow to Studio
- add README for Studio plugin scaffold
- add QR code generation to Studio workspace
- add initial studio block shell for team workflows

## 0.1.0

- add initial `extrachill/studio` block scaffold
- add team-gated Studio app shell with shared tabs and React-powered pane content
