# Changelog

Maintained by Homeboy. Do not edit by hand.

## [0.2.3] - 2026-10-19

### Fixed
- only dispatch abilities exposed via show_in_rest or mcp.public

## [0.2.2] - 2026-10-18

### Fixed
- route network ability search and ambiguous calls to the owning site's real registry

## [0.2.1] - 2026-09-22

### Fixed
- grant id-token so the shared release workflow can start

## [0.2.0] - 2026-09-21

### Added
- record MCP usage, carrying the resolved ability name

### Changed
- adopt the shared Homeboy release train
- fix NetworkStub namespace bug and wire it into real coverage

## [0.1.4] - 2026-09-18

### Fixed
- send WWW-Authenticate on a 401 from the MCP endpoint

## [0.1.3] - 2026-09-18

### Fixed
- declare WP_MCP_AUTOLOAD so the bundled adapter boots

## [0.1.2] - 2026-09-18

### Fixed
- boot after Data Machine, not alongside it

## [0.1.1] - 2026-09-18

### Changed
- Network-wide MCP endpoint: route abilities across sites, reuse platform gating
- wire managed Homeboy gate (review lint + review test)
- Phase 0: scaffold the MCP context server
- Initial commit

### Fixed
- point the access fallback at access_studio
