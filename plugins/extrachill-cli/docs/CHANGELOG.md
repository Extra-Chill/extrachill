# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.32.0] - 2026-07-18

### Added
- expose platform experiment operations
- expose first-party route transitions
- expose conversion maturity and outcome coverage

### Fixed
- align outbound source coverage contract

## [0.31.4] - 2026-07-17

### Fixed
- preserve conversion outcome envelope
- preserve analytics summary detail

## [0.31.3] - 2026-07-16

### Fixed
- preserve retention referrer details

## [0.31.2] - 2026-07-16

### Fixed
- preserve conversion machine output

## [0.31.1] - 2026-07-14

### Fixed
- accept documented revenue filters

## [0.31.0] - 2026-07-13

### Added
- add ability-backed revenue commands

### Fixed
- satisfy revenue command coding standards

## [0.30.0] - 2026-07-12

### Added
- manage Local Scene preferences

### Fixed
- align crosslink targets with inbound contract

## [0.29.1] - 2026-07-12

### Fixed
- pass explicit fputcsv escape argument (#86)

## [0.29.0] - 2026-07-12

### Added
- add content quiz CLI wrapping trivia abilities

### Fixed
- use QR ability existence predicate
- repoint QR command ownership to network

## [0.28.0] - 2026-07-11

### Added
- surface SERP-captured definition-box rows in GSC opportunities

## [0.27.1] - 2026-07-11

### Fixed
- auto-target events blog for events report/ops subcommands (#79)

## [0.27.0] - 2026-07-03

### Added
- add `wp extrachill network migrate-post` command

## [0.26.0] - 2026-06-30

### Added
- add analytics bot-filter-impact CLI command

### Fixed
- label crosslink-targets CLI orphan count as zero-inbound

## [0.25.0] - 2026-06-29

### Added
- add `wp extrachill roadie chat` to talk to Roadie from the CLI

## [0.24.0] - 2026-06-22

### Fixed
- yoda condition in growth float formatting to clear release lint gate
- render demand-drill decliner/riser rows in table format
- guard against redeclare when two plugin copies load

## [0.23.0] - 2026-06-22

### Added
- wp extrachill analytics crosslink-targets CLI
- wp extrachill analytics demand-drill CLI

### Fixed
- type ability params as WP_Ability and drop dead all-time branch to clear phpstan findings
- prefer active-window error rate so platform health Errors/day reflects current firing
- read zero_result_total in compose_search_gaps so platform health reports real search-gap counts

## [0.22.0] - 2026-06-21

### Added
- wp extrachill analytics outbound CLI
- wp extrachill analytics gsc-opportunities
- wp extrachill analytics revenue fetch (Mediavine)

## [0.21.0] - 2026-06-21

### Added
- wp extrachill seo redirects opportunities
- wp extrachill analytics revenue CLI
- platform-surface stickiness instrument
- add redirects stats conversion subcommand
- surface content-flags confidence + query-intent caveat
- add conversion CLI wrapping get-conversion-map

## [0.20.0] - 2026-06-21

### Added
- surface category coverage ratio in content-audit
- content-flags command

### Changed
- align content-flags command with outcome-first ability

## [0.19.0] - 2026-06-21

### Added
- add content-audit command to rank category posts by engagement

### Fixed
- guard fuzzy redirect import against archive-breaking redirects

## [0.18.0] - 2026-06-20

### Added
- surface flow-fallback count in audit/fix-locations output

## [0.17.0] - 2026-06-20

### Added
- add analytics growth command wrapping get-surface-growth ability
- add --purge flag to users ban for hard-delete content (#135)
- surface community engagement in a single composed read

### Fixed
- document analytics reads take no acting user to stop ambiguous --user warning
- run artists CLI in artist platform site context (#75)

## [0.16.0] - 2026-06-18

### Added
- composed 'platform health' scorecard CLI command
- add wp extrachill analytics search-gaps command

## [0.15.0] - 2026-06-17

### Added
- add wp extrachill analytics retention command

### Fixed
- display exact UTC window bound in analytics summary output

## [0.14.0] - 2026-06-17

### Added
- measurability CLI wrappers (artists count, access/team stats)
- add `wp extrachill artists stats` measurability command

## [0.13.0] - 2026-06-16

### Added
- add `extrachill analytics errors` CLI command

## [0.12.3] - 2026-06-13

### Fixed
- fix(agents-md): generate Extra Chill CLI section from real command tree (#28)

## [0.12.2] - 2026-06-13

### Changed
- repoint CLI community notifications to network substrate abilities
- fix phpcs lint debt to pass release preflight

## [0.12.1] - 2026-05-30

### Changed
- migrate leaderboard command to canonical users-leaderboard ability

## [0.12.0] - 2026-05-24

### Added
- add --format flag to create-reply and create-topic

### Changed
- refactor(cli/users): invoke abilities (#19)
- refactor(cli/shop): invoke abilities (#19)
- refactor(cli/artists): invoke abilities (#19)
- refactor(cli/events): invoke abilities (#19)

## [0.11.0] - 2026-05-01

### Added
- add 'wp extrachill analytics attacks' command
- add 'subscribers' subcommand to newsletter CLI
- expose roundup scope option

### Changed
- 404 CLI wraps abilities, add top-ips subcommand

## [0.10.0] - 2026-04-25

### Added
- add wp extrachill events roundup command
- register Extra Chill CLI section in AGENTS.md composable file

## [0.9.1] - 2026-04-09

### Changed
- Add CLI commands for campaign management and subscriber status
- Add newsletter CLI commands wrapping abilities

## [0.9.0] - 2026-04-02

### Added
- add giveaway CLI commands (run, schedule, resolve)

### Changed
- Align CLI with ability contract: WP_Error returns, remove function_exists guards

## [0.8.3] - 2026-03-29

### Changed
- Add audit-times and fix-times CLI commands for event timezone diagnostics
- Add concert tracking CLI: wp extrachill shows

### Fixed
- add @subcommand annotation for list command

## [0.8.2] - 2026-03-28

### Fixed
- Fix venues add default interval: daily, not twicedaily
- set admin user context before ability execute in CLI

## [0.8.1] - 2026-03-25

### Changed
- Add CLI commands for user settings and profile management

## [0.8.0] - 2026-03-23

### Added
- add cache warm and status CLI commands

### Changed
- Add topic/reply CLI commands for community management
- Add market-report CLI command for events calendar

### Fixed
- use positional arg syntax in discover output to avoid --url conflict (fixes #48)

## [0.7.1] - 2026-03-20

### Changed
- Add wp extrachill community CLI commands

## [0.7.0] - 2026-03-19

### Added
- add artist access CLI commands (list, approve, reject)

## [0.6.2] - 2026-03-18

### Fixed
- update qualify CLI output for scraper-based results

## [0.6.1] - 2026-03-18

### Fixed
- rename --url to --events-url to avoid WP-CLI --url conflict

## [0.6.0] - 2026-03-18

### Added
- add subcommand for venues CLI

## [0.5.0] - 2026-03-18

### Added
- add qualify subcommand to venues CLI

## [0.4.0] - 2026-03-18

### Added
- add venue discovery CLI command
- add move-link command for reordering links within/across sections
- add save-styles and save-settings CLI commands for artist link pages
- add save-links, add-link, and remove-link CLI commands for artist link pages

## [0.3.0] - 2026-03-17

### Added
- add artist CLI commands wrapping abilities

### Changed
- add fuzzy title matching to redirects import command

## [0.2.3] - 2026-03-17

### Changed
- add build/ to gitignore
- add NetworkAwareTrait for multisite-aware CLI commands

### Fixed
- fix PHPCS lint issues across all command files

## [0.2.2] - 2026-03-16

### Added
- add `wp extrachill events audit-locations` and `wp extrachill events fix-locations` wrappers for event location reconciliation
- add `homeboy.json` component config for clean Homeboy registration and deployment

### Changed
- add event location reconciliation CLI commands

## [0.2.1] - 2026-03-13

### Added
- Add QR code generation command under wp extrachill tools qr.

### Changed
- Support moderation policies in user CLI
- Add user ban commands to extrachill CLI
- Add wp extrachill analytics summary command

## [0.2.0] - 2026-03-05

### Added
- Add `wp extrachill tools qr generate` command for QR PNG generation from URLs.
- Register new `extrachill tools qr` command namespace.
- Document QR CLI usage in README.

## [0.1.0] - 2026-03-03

### Added
- Initial release with `wp extrachill analytics 404` command group.
- Added `wp extrachill seo redirects` command group.
- Added WP-CLI plugin bootstrap and command registration architecture.
