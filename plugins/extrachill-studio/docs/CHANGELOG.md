# Changelog

## [0.2.22] - 2026-03-25

### Changed
- rewrite floating chat: conditional render, flat CSS classes, theme vars

## [0.2.21] - 2026-03-25

### Fixed
- fix floating chat positioning and mount timing

## [0.2.20] - 2026-03-25

### Fixed
- fix floating chat: move to viewport overlay, restore compose sidebar

## [0.2.19] - 2026-03-25

### Fixed
- rename onDraftChange select handler to avoid collision with prop

## [0.2.18] - 2026-03-24

### Changed
- replace Chat tab with floating Roadie panel

## [0.2.17] - 2026-03-24

### Changed
- use ContentBridge API for draft switching instead of editor remount

## [0.2.16] - 2026-03-24

### Fixed
- fix draft content loading with editorKey remount pattern

## [0.2.15] - 2026-03-24

### Changed
- remove all panel eyebrow elements and CSS

## [0.2.14] - 2026-03-24

### Changed
- add 2s debounced autosave to compose tab
- add draft picker and auto-restore to compose tab

## [0.2.13] - 2026-03-24

### Changed
- simplify QR code tab: remove size field, paste-and-generate

## [0.2.12] - 2026-03-24

### Changed
- migrate studio block from JavaScript to TypeScript
- remove overview tab, Phase 0 badge, and user status bar; clean up help text

### Fixed
- remove double border on compose editor — container already has one
- add .gutenberg-support body class for toolbar dark mode

## [0.2.11] - 2026-03-24

### Fixed
- use inline host-page styles for editor dark mode instead of iframe injection

## [0.2.10] - 2026-03-24

### Fixed
- inject theme CSS into studio editor iframe for dark mode and EC tokens

## [0.2.9] - 2026-03-24

### Changed
- use blocks_everywhere_contexts API instead of Frontend handler

## [0.2.8] - 2026-03-23

### Fixed
- prevent jQuery dequeue when compose editor is active

## [0.2.7] - 2026-03-23

### Fixed
- use Frontend handler instead of bbPress, add wp_enqueue_editor for Gutenberg deps

## [0.2.6] - 2026-03-23

### Fixed
- enqueue Gutenberg editor deps during wp_enqueue_scripts for Compose tab

## [0.2.5] - 2026-03-23

### Fixed
- poll for blocksEverywhereCreateEditor instead of checking synchronously

## [0.2.4] - 2026-03-23

### Changed
- remove blocks-everywhere dep injection and footer group hack

## [0.2.3] - 2026-03-23

### Fixed
- move viewScript to footer so it prints when enqueued during do_blocks()

## [0.2.2] - 2026-03-23

### Fixed
- explicitly enqueue viewScript in render.php for template-rendered blocks

## [0.2.1] - 2026-03-23

### Fixed
- add blocks-everywhere as view script dependency to resolve race condition

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
