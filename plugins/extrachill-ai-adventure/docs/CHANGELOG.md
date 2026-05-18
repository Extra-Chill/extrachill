# Changelog

All notable changes to this project will be documented in this file.

## [1.3.1] - 2026-05-18

### Fixed
- align with agents-api WP_Agent_Tool_* renamed identifiers (closes #4)

## [1.3.0] - 2026-05-18

### Added
- replace ChatOrchestrator with AgentConversationLoop and wp-ai-client turn runner
- register game-master agent declaratively via agents-api

### Changed
- replace BaseTool with RuntimeToolDeclaration for progress_story

### Fixed
- sync block.json versions on release so style/script changes bust caches

## [1.2.0] - 2026-03-28

### Added
- add progress_story ability + tool for game-master agent

## [1.1.1] - 2026-03-28

### Changed
- replace direct AI calls with Data Machine ChatOrchestrator

## [1.1.0] - 2026-03-27

### Added
- initial release - standalone AI adventure plugin

## [1.0.0] - 2026-03-27

### Added
- Initial release as standalone plugin, extracted from extrachill-content-blocks
- Three-block hierarchy: ai-adventure (container), ai-adventure-path, ai-adventure-step
- AI game master with branching narrative paths and semantic trigger progression
- Oregon Trail-inspired terminal UI with [SCENE] and [DIALOGUE] tag rendering
- Rate-limited REST API endpoint at /extrachill/v1/ai-adventure
- Self-registering BE/IBE allowlists (Studio only, excluded from bbPress)
- Data Machine AI integration with legacy chubes_ai_request fallback

### Fixed
- CSS class mismatch: selectors now target correct .wp-block-extrachill-ai-adventure
- Hardcoded "Wilson" persona name in conversation history builder
- Wasted AI calls: progression analysis now runs before narrative generation
- Removed dead edit.js orphan file
