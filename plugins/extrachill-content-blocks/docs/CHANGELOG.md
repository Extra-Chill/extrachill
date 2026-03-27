# Changelog

All notable changes to this project will be documented in this file.

## [1.1.0] - 2026-03-27

### Added
- Standalone cross-site content blocks plugin extracted from extrachill-blog
- Homeboy component configuration for build/deploy pipeline
- Block source files moved to `src/blocks/` subdirectory

### Changed
- Renamed plugin from "Extra Chill Blocks" to "Extra Chill Content Blocks"
- Block namespace standardized to `extrachill/*`
- Renamed `ExtraChill_Blog_Prompt_Builder` to `ExtraChill_Content_Blocks_Prompt_Builder`
- Updated API paths from `/blog/` to `/content-blocks/` namespace
- Switched from glob-based to explicit block registration
- Minimum WordPress version raised to 6.4

### Removed
- Old `extrachill-blocks/*` block namespace
- Shared CSS enqueue and inline style injection
- Legacy render callback complexity

## [1.0.0] - 2025-10-15

### Added
- Initial release as Extra Chill Blocks
- Trivia block with multiple-choice questions and scoring
- Image voting block with email capture and newsletter integration
- Band name generator block with genre selection
- Rapper name generator block with style categories
- AI adventure block with branching narrative paths
