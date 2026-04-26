# Changelog

## [0.9.0] - 2026-04-26

### Added
- enable fullscreen mode in compose editor via IBE More Menu, add CSS to hide out-of-skeleton chrome (title, toolbar, actions, sidebar) when fullscreen is active

### Fixed
- register Studio ability category

## [0.8.0] - 2026-04-26

### Added
- add MediaPicker for main-site media library access in Socials publish pane

## [0.7.2] - 2026-04-26

### Added
- rename Compose tab to Blog

## [0.7.1] - 2026-04-26

### Added
- pin Instagram to top of socials sidebar via datamachine_socials_platform_priority filter

## [0.7.0] - 2026-04-26

### Added
- consume new /platforms array contract, drop client-side normalization and filtering

## [0.6.0] - 2026-04-21

### Added
- migrate GiveawayTask to executeTask() contract

## [0.5.1] - 2026-04-03

### Added
- data-driven Socials tab with sidebar navigation and standalone comments view

### Changed
- update socials API paths to datamachine/v1/socials namespace

## [0.5.0] - 2026-04-02

### Added
- add giveaway abilities and task for server-side execution + scheduling
- filterable social platform allowlist via extrachill_studio_social_platforms
- unified publish targets in Compose tab
- wire up Media tab by providing allowedMimeTypes to editor settings
- add persistent compose sidebar shell

### Changed
- simplify Giveaway — accept Instagram URLs, remove exclude/keyword fields
- move Giveaway into Socials tab as a view, add Toolbar view switcher
- Remove .homeboy-build-meta.json — homeboy no longer generates this file
- Decouple Compose from social publishing, platform-agnostic Socials tab
- Add Giveaway tab for automated Instagram winner picking
- Bump studio block apiVersion 2 → 3 for Gutenberg 22.8 iframe editor compatibility
- replace synchronous cross-post with async DM Task System
- align studio block with standard pattern
- remove empty PanelHeaders
- align studio with layout contract
- rely on shared studio shell defaults

### Fixed
- align block.json version with plugin version
- platform switcher uses design system tokens for all interactive states
- Socials tab only shows authenticated publish-capable platforms
- remove publish target routing from Compose tab entirely
- inline draft picker and new button on mobile, use theme select styles
- hide empty IBE header on mobile when detached sidebar is active
- prevent compose columns from stretching
- simplify compose sidebar shell

## [0.4.0] - 2026-03-26

### Added
- add detached compose sidebar

## [0.3.2] - 2026-03-26

### Fixed
- suppress duplicate studio page titles
- center studio shell inner width

## [0.3.1] - 2026-03-26

### Changed
- clarify studio compose header

## [0.3.0] - 2026-03-26

### Added
- register compose context for shared chat clients

### Changed
- simplify studio compose layout
- remove studio panel header titles

### Fixed
- restore green studio typecheck

## [0.2.46] - 2026-03-26

### Changed
- update shared components for edge shell rename

## [0.2.45] - 2026-03-26

### Changed
- remove redundant studio shell overrides

## [0.2.44] - 2026-03-26

### Changed
- update studio components dependency to 0.4.31

## [0.2.43] - 2026-03-26

### Changed
- update studio components dependency to 0.4.30

## [0.2.42] - 2026-03-26

### Changed
- update studio components dependency to 0.4.29

## [0.2.41] - 2026-03-26

### Changed
- update studio components dependency to 0.4.28

## [0.2.40] - 2026-03-26

### Changed
- update studio components dependency to 0.4.27

## [0.2.39] - 2026-03-26

### Changed
- use shared inner wrapper in studio layout
- use shared block inner wrapper in studio

## [0.2.38] - 2026-03-26

### Changed
- track studio block metadata version

## [0.2.37] - 2026-03-26

### Changed
- remove studio tab wrapper overrides

## [0.2.36] - 2026-03-26

### Changed
- update studio lockfile for components 0.4.25
- consume shared tab layout in studio

## [0.2.35] - 2026-03-26

### Changed
- remove legacy studio wrapper and shell overrides

## [0.2.34] - 2026-03-26

### Changed
- update components dependency to 0.4.24
- standardize studio block tabs and shell

## [0.2.33] - 2026-03-25

### Changed
- Remove Studio breakout and defer mobile shell styling to shared components

## [0.2.32] - 2026-03-25

### Changed
- Update lockfile for shared shell header rollout
- Add shell-contained header and deeper nesting to Studio

## [0.2.31] - 2026-03-25

### Changed
- Update shared components and opt Studio into generic breakout utility
- Opt Studio block into generic full-width breakout utility

## [0.2.30] - 2026-03-25

### Fixed
- Fix Studio outer shell mobile breakout

## [0.2.29] - 2026-03-25

### Changed
- Improve Studio mobile edge-to-edge panel behavior

## [0.2.28] - 2026-03-25

### Changed
- Update shared components dependency to 0.4.2

## [0.2.27] - 2026-03-25

### Changed
- reuse shared badges in studio

## [0.2.26] - 2026-03-25

### Changed
- reuse shared media field in studio

## [0.2.25] - 2026-03-25

### Changed
- adopt shared studio UI primitives

## [0.2.24] - 2026-03-25

### Changed
- cleanup studio panel list spacing

## [0.2.23] - 2026-03-25

### Changed
- Decouple Roadie chat from Studio block

### Fixed
- fix studio team access gating

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
