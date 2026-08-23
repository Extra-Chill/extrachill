# Changelog

## [0.41.5] - 2026-08-23

### Changed
- account for malformed identity cache notice

### Fixed
- use canonical avatar profile URLs
- require newsletter consent during registration
- restore clean release lint
- send profile setup links to the editor

## [0.41.4] - 2026-08-22

### Fixed
- Fix first-session onboarding and registration feedback
- stabilize queued email tests
- authorize hourly digest queue
- handle moderation email queue errors
- harden moderation cleanup failures
- resume attendance intent after authentication
- remove redundant auth block heading

## [0.41.3] - 2026-08-10

### Changed
- require registered subscription identities

### Fixed
- provide Homeboy database test environment

## [0.41.2] - 2026-08-04

### Changed
- retire account email-sharing identities

## [0.41.1] - 2026-08-04

### Fixed
- clear publish notification lint blockers
- send immediate publication emails

## [0.41.0] - 2026-07-29

### Changed
- remove legacy notification compatibility
- restore managed WordPress release harness

## [0.40.0] - 2026-07-29

### Added
- support producer-owned notification email

### Changed
- canonicalize entity email consent

## [0.39.1] - 2026-07-26

### Changed
- Grant Studio team private Intelligence reads
- isolate auth fuzz rate limit phases
- persist auth fuzz requester identity
- isolate auth fuzz credential failures
- preserve auth fuzz limiter diagnostics

### Fixed
- test network plugin in multisite MySQL
- restore reproducible dependency installs
- restore auth fuzz rate limit seams

## [0.39.0] - 2026-07-25

### Added
- add idempotent notification receipts
- welcome new users into the clubhouse

### Fixed
- persist artist email consent identity
- remove follower language from artist email consent

## [0.38.0] - 2026-07-24

### Added
- make progressive registration method agnostic
- make Google registration progressive

### Changed
- provision event dates fixture

## [0.37.2] - 2026-07-24

### Fixed
- preserve registration counter ttl at rollover
- make auth rate counters atomic
- revoke native sessions during moderation
- enforce password policy for authenticated changes
- make browser handoff lock portable
- make browser handoff consumption atomic
- make registration admission atomic
- harden password registration against automation

## [0.37.1] - 2026-07-23

### Changed
- assert moderated login denial
- establish canonical moderated persona
- adversarially probe authentication invariants
- strengthen auth identity oracles
- diagnose auth cookie continuity
- keep auth redirects inside sandbox
- provision auth pages explicitly
- route auth browser cases through WordPress
- correct network auth fuzz oracles
- add multisite auth fuzz campaign

### Fixed
- throttle account login aliases together
- reject non-HTTPS browser handoffs
- rehydrate user after onboarding rename
- clear unclaimed state after password claim

## [0.37.0] - 2026-07-22

### Added
- track onboarding artist access grants ([#234](https://github.com/Extra-Chill/extrachill-users/pull/234)) (by Chris Huber)

### Fixed
- close core multisite signup surface ([#241](https://github.com/Extra-Chill/extrachill-users/pull/241)) (by Chris Huber)
- secure artist access administration ([#232](https://github.com/Extra-Chill/extrachill-users/pull/232)) (by Chris Huber)
- enforce reciprocal membership authorization ([#231](https://github.com/Extra-Chill/extrachill-users/pull/231)) (by Chris Huber)

## [0.36.0] - 2026-07-20

### Added
- add concert privacy controls
- add idempotent concert attendance contract

### Fixed
- bound public concert queries
- align concert history with canonical timing
- validate and serialize attendance writes
- hide non-public events from concert histories

## [0.35.0] - 2026-07-18

### Added
- add audited Artist Dispatch access lifecycle

### Fixed
- declare compiled block release scope
- honor request login continuations
- preserve safe login continuations
- keep onboarding views server-owned
- make onboarding resilient to browser failures
- revoke sessions when banning users
- require onboarding before profile writes

## [0.34.0] - 2026-07-13

### Added
- persist Local Scene prompt state

## [0.33.0] - 2026-07-13

### Added
- collect Local Scene during onboarding

## [0.32.0] - 2026-07-12

### Added
- list public Local Scene members

## [0.31.0] - 2026-07-12

### Added
- migrate deterministic Local Scenes
- add private entity subscriptions

### Fixed
- avoid newsletter ability collision (#187)

## [0.30.0] - 2026-07-12

### Added
- establish network Local Scene preference

## [0.29.1] - 2026-07-12

### Fixed
- own event market resolution

## [0.29.0] - 2026-07-12

### Added
- add default event location preference

## [0.28.0] - 2026-07-12

### Added
- own user administration abilities

### Fixed
- use ability existence predicate

## [0.27.0] - 2026-07-12

### Added
- add auto-subscribe-to-replies notification preference

### Changed
- migrate to extrachill-network names (#171)

## [0.26.0] - 2026-07-06

### Added
- add ec_get_network_bot_user_id() config helper + role/unclaimed create-user inputs (#207)

### Changed
- promote points/rank engine from community + add dated-contributions seam

## [0.25.0] - 2026-07-04

### Added
- make attendance + import-nudge renderers timing/variant aware

## [0.24.0] - 2026-07-03

### Added
- notify a submitter when their submission is published
- send team welcome + set-password email on first role grant (#159)

## [0.23.0] - 2026-06-28

### Added
- add durable last_login primitive + expose on user abilities

## [0.22.0] - 2026-06-27

### Added
- grant datamachine_view_analytics to extra_chill_team

### Fixed
- harden attendee strip CSS against .entry-content style leak

## [0.21.2] - 2026-06-27

### Fixed
- compute event timing cross-site instead of silently returning 'past'

## [0.21.1] - 2026-06-27

### Changed
- align assignment operators to satisfy phpcs MultipleStatementAlignment
- Stop emailing stale show reminders + auto-mark them read after the event passes

## [0.21.0] - 2026-06-20

### Added
- capture external referrer and UTM on registration for source attribution

### Fixed
- authorize notification click-to-read via HMAC token instead of REST cookie auth

## [0.20.0] - 2026-06-19

### Added
- per-reason customizable ban-notice email copy (#134)
- add per-person brand-socials access grant
- add opt-in hard-delete (purge) path for moderation

### Changed
- read online-users count from NetworkStats primitive, delete duplicate function

## [0.19.0] - 2026-06-18

### Added
- gate shared brand socials behind admin-only brand_socials feature

## [0.18.0] - 2026-06-17

### Added
- instrument team + artist-funnel events and add cohort read abilities

### Changed
- reference canonical analytics event-name constants (users#129)

## [0.17.3] - 2026-06-15

### Fixed
- guard ability category registration against double-fire _doing_it_wrong notice
- verify block.json version targets against shipped build/ path on deploy

## [0.17.2] - 2026-06-15

### Fixed
- defer concert-import auth provider requires until Data Machine base class is loaded

## [0.17.1] - 2026-06-12

### Fixed
- guard WP_Error before array access in password reset email path

## [0.17.0] - 2026-06-06

### Added
- group digest notification preview lines by target

### Changed
- cache per-user unread notification count in object cache

## [0.16.0] - 2026-06-06

### Added
- click-to-read redirect + mark-all-read control

## [0.15.2] - 2026-06-06

### Changed
- align assignment operators in emailed_at backfill

### Fixed
- nudge digest email once per notification, not daily forever
- send registration emails in authenticated context

## [0.15.1] - 2026-06-04

### Changed
- pin ajv ^8 to fix wp-scripts webpack build on Node 25
- refactor(concert-tracking): converge single-event toggle on shared React hook

### Fixed
- scope team role to own posts only, drop edit_others_posts

## [0.15.0] - 2026-06-01

### Added
- render notification bell network-wide (refs #104)
- digest email opt-out toggle + one-click unsubscribe
- feat(concert-tracking): award rank points for shows attended
- unread-notification email digest channel
- feat(concert-tracking): show reminders + milestone notifications
- network notification table + entry point + abilities
- feat(concert-tracking): enrich shows payload with cross-site term urls
- cross-site stat links + attendee 'who's going' surface
- feat(concert-import): route event creation through canonical upsert-post (fixes #81)
- add Feature Rollout network-admin page (closes #66)

### Changed
- remove dead bp_ follow-system from subscription abilities
- queue digest sends + schedule via DM RecurringScheduler
- delete duplicated token primitives, consolidate onto wp-native-auth (refs #76)
- consume theme tokens + clear phpcs lint debt

### Fixed
- expose user-facing abilities over Abilities REST /run with per-ability auth
- hash browser-handoff token cache key (closes #73)
- generate refresh tokens with random_bytes base64url (closes #72)

## [0.14.0] - 2026-05-30

### Added
- add Snowflake, Icicle, and Iceberg rank tiers
- data-driven, filterable rank tier registry with progress helpers
- capability gate + live-staging feature rollout primitives

### Changed
- rename Snowflake rank tier to Powder

### Fixed
- read requested_at key in artist access request list (closes #61)

## [0.13.2] - 2026-05-27

### Fixed
- fix(concert-tracking): empty search query returns empty results

## [0.13.1] - 2026-05-27

### Fixed
- surface and log ec_send_email failures (closes #56)
- fix(concert-import): scope sources to configured-only, create missing events, migrate to Data Machine auth

## [0.13.0] - 2026-05-27

### Added
- feat(concert-import): import framework + setlist.fm + phish.net adapters (Extra-Chill/extrachill-events#112)
- feat(concert-tracking): add search-events-for-marking ability

## [0.12.0] - 2026-05-23

### Added
- single-origin Google sign-in via canonical community redirect (closes #26)

## [0.11.1] - 2026-05-23

### Changed
- unit tests for password reset rate-limiter trio

### Fixed
- fix(bearer-auth): use narrow Authorization header sanitizer instead of sanitize_text_field

## [0.11.0] - 2026-05-23

### Added
- feat(team-role): make extra_chill_team WP role the source of truth, retire user_meta (#45)

### Fixed
- route wp-core password reset emails to /reset-password/ (closes #46)

## [0.10.2] - 2026-05-18

### Fixed
- sync block.json versions on release so style/script changes bust caches

## [0.10.1] - 2026-05-18

### Changed
- migrate user emails to datamachine/send-email (closes #40)

## [0.10.0] - 2026-05-18

### Added
- wire Cloudflare Turnstile into login panel

## [0.9.0] - 2026-05-18

### Added
- harden password reset with hooks, logging, and rate limiting

### Changed
- migrate both registration paths to ec_turnstile_check_request() helper

## [0.8.3] - 2026-05-12

### Changed
- clean up PHPCS warnings to restore release gate

### Fixed
- clean up ESLint errors to restore release gate
- resolve remaining PHPStan type errors
- drop dead permission_callback branches in users-search ability
- cast wp_json_encode() output to string before base64url encoding
- cast filemtime() to string for wp_enqueue_*() $ver argument
- narrow array|WP_Post union with instanceof checks before property access
- guard WP_User|false from get_userdata() in admin notification email
- annotate EC_Redirect_Handler terminating methods with @phpstan-return never

## [0.8.2] - 2026-05-11

### Fixed
- accept username or email in password reset form

## [0.8.1] - 2026-05-10

### Fixed
- content sweep silently misses bbPress and per-site CPTs

## [0.8.0] - 2026-05-10

### Added
- register user-domain abilities (#21)
- feat(wp-native-bridge): hook pre_register and after_register
- feat(wp-native-bridge): consume wp-native-auth filters

### Fixed
- hide content by default for abuse and fraud bans

## [0.7.20] - 2026-04-26

### Fixed
- harden Google sign-in render race and consolidate from_join source

## [0.7.19] - 2026-04-26

### Fixed
- auto-link Google OAuth on verified email match instead of returning a dead-end 409 error users could not recover from

## [0.7.18] - 2026-04-09

### Changed
- Clean up PHPCS, PHPStan, and integration test issues

## [0.7.17] - 2026-04-09

### Changed
- Pass redirect_to from login form through to 2FA flow

### Fixed
- Fix 2FA login flow: clear attempts and honor redirect_to

## [0.7.16] - 2026-04-03

### Changed
- Add .homeboy-build-meta.json to gitignore

### Fixed
- Fix login-register block incompatibility with Two-Factor Authentication

## [0.7.15] - 2026-04-02

### Changed
- optimize custom avatar rendering with static cache, size-aware images, and lazy loading
- delegate ec_users_get_event_timing() to core primitive

### Fixed
- whitelist two-factor 2FA challenge actions in wp-login redirect

## [0.7.14] - 2026-03-29

### Changed
- Add defer strategy to avatar-menu and auth-utils scripts
- Use button-medium for attendance button
- Add attendance button UI with time-derived labels and optimistic toggle
- Add concert tracking data layer: DB schema, CRUD, stats queries, and abilities
- Auto-create login pages on new network sites

### Fixed
- fallback to EventDatesTable class when convenience function unavailable
- use theme button classes and DM events date API
- Fix concert tracking queries to use datamachine_event_dates table

## [0.7.13] - 2026-03-27

### Fixed
- allow Google sign-in button to shrink below 360px

## [0.7.12] - 2026-03-27

### Changed
- align login-register block with standard pattern
- align login register tabs with shared shell defaults
- update shared components for edge shell rename

### Fixed
- remove dead classes and padding reset from login-register

## [0.7.11] - 2026-03-26

### Changed
- harden headless login register turnstile rendering

## [0.7.10] - 2026-03-26

### Changed
- migrate login register block to headless React

## [0.7.9] - 2026-03-26

### Changed
- fully align login register block with shared shell tabs

## [0.7.8] - 2026-03-26

### Changed
- restore login register google button rendering

## [0.7.7] - 2026-03-26

### Changed
- align login register shell with shared mobile edges

## [0.7.6] - 2026-03-26

### Changed
- update components dependency to 0.4.24
- migrate login block off theme shared tabs

## [0.7.5] - 2026-03-25

### Changed
- Remove login surface styling now owned by shared shells

## [0.7.4] - 2026-03-25

### Changed
- Add shell-contained header to login-register block structure

## [0.7.3] - 2026-03-25

### Fixed
- fix online users footer icons

## [0.7.2] - 2026-03-25

### Fixed
- fix team member meta check

## [0.7.1] - 2026-03-25

### Changed
- Add user settings, profile, and subscription abilities

### Fixed
- Fix three bugs found in cleanup audit

## [0.7.0] - 2026-03-19

### Added
- send approval email directly from ability instead of depending on artist-platform
- add artist access abilities (list, approve, reject)

### Changed
- reconcile workspace with production state
- Move moderation internals into core module
- Moderate owned artist content without CPT registry checks
- Include owned artist content in spam moderation
- Refactor user bans into moderation policies
- Move user ban commands into extrachill CLI
- Add reusable user ban system with abilities and CLI
- Update login register block for iframe editor compatibility
- Refactor user lifecycle to abilities-first architecture

### Fixed
- Fix ghost URLs in admin registration email

## [0.6.4] - 2026-02-12

### Fixed
- Fix fatal crash: rename ec_get_user_profile_url to extrachill_get_user_profile_url

## [0.6.3] - 2026-02-12

### Changed
- Revert disabled submit button that blocks registration when Turnstile PAT fails

## [0.6.2] - 2026-02-12

### Fixed
- Fix intermittent captcha error by resetting Turnstile widget after failed registration and disabling submit button until captcha loads

## [0.6.1] - 2026-02-06

### Changed
- Add forgot password link and register prompt to login form

## [0.6.0] - 2026-01-27

### Changed
- Add Abilities API integration for welcome email timing fix

## [0.5.15] - 2026-01-25

### Fixed
- Fixed analytics tracking with correct WordPress 6.9 Abilities API

## [0.5.14] - 2026-01-25

### Fixed
- Fixed analytics tracking timing issue with Abilities API

## [0.5.13] - 2026-01-23

- Add direct analytics tracking via Abilities API

## [0.5.11] - 2026-01-22

- Fixed shared-tabs assets not loading for login-register block on non-singular pages

## [0.5.10] - 2026-01-19

### Added
 - Added user badge CSS with color variables (--artist-badge-color, --team-badge-color, --professional-badge-color)
 - Added online users stats footer component
- Added PHPUnit test suite with bearer auth, user creation, password validation, tokens, and username generation tests
- Improved rank tier system with expanded level structure
- Refactored avatar display, menu items, and menu rendering
- Improved auth token service and bearer authentication
- Enhanced Google OAuth integration and JWT RS256 handling
- Improved admin access control and shop permissions
- Refactored registration emails and comment auto-approval
- Replaced AGENTS.md with CLAUDE.md documentation

## [0.5.9] - 2026-01-06

### Changed
- **Shop Management Permissions**: Restricted `ec_can_manage_shop()` to users with `manage_options` capability until public release.
- **Shop Product Count**: Replaced dummy count with a native cross-site `WP_Query` to the shop blog (Blog ID 3) for accurate user-artist product tracking.

### Documentation
- Updated `CLAUDE.md` with current cross-site artist linking architecture details.

## [0.5.8] - 2026-01-05

### Changed
- **Decommissioned Profile URL Resolution**: Removed the `inc/author-links.php` logic as profile resolution has been migrated to the `extrachill-multisite` plugin for better architectural consolidation.
- **Artist Profile Lookup**: Migrated `ec_get_artist_profile_by_slug()` to `extrachill-multisite` plugin; added a pointer comment in `inc/artist-profiles.php`.

## [0.5.7] - 2026-01-04

### Changed
- **Decommissioned Legacy Membership UI**: Removed the legacy `network-memberships.php` administrative interface.
- **Admin Tools Migration**: Lifetime Membership management is now handled exclusively by the `extrachill-admin-tools` plugin's React-based interface via REST API.

## [0.5.6] - 2025-12-22

### Added
- Registration source and method tracking across all authentication flows (standard, Google OAuth)
- User meta storage for `registration_source` (web, extrachill-app) and `registration_method` (standard, google)
- Enhanced admin notification emails to include registration source and method details

### Changed
- Browser handoff system now uses site transients for network-wide compatibility
- Browser handoff handler updated to use admin_post hooks with proper redirect security
- Registration data processing enhanced with new metadata fields and app client defaults

### Technical
- Added `registration_source` and `registration_method` parameters to user creation workflow
- Improved browser handoff token security with allowed_redirect_hosts filtering
- Enhanced payload validation and sanitization for registration metadata

## [0.5.5] - 2025-12-22

### Added
- Browser handoff token system for mobile app authentication integration
- Single-use tokens with 60-second expiration for secure cookie bootstrapping
- Browser handoff handler with URL validation and automatic redirect

### Changed
- Updated site references in documentation: Blog ID 11 designated as "wire" and Blog ID 12 designated as "horoscope"

## [0.5.4] - 2025-12-21

### Added
- Avatar menu items builder function `extrachill_users_get_avatar_menu_items()` for centralized menu construction with priority-based sorting
- Shop permission helpers: `ec_can_manage_shop()` and `ec_get_shop_product_count_for_user()` for unified shop access control
- Mediavine ad blocklist output with global ad-free checking and site-specific override via `extrachill_should_block_ads` filter

### Changed
- Refactored avatar menu display to use new canonical menu items builder instead of inline logic
- Improved REST root URL detection in auth-utils.js with fallback chain and URL normalization for robust multisite support
- Added REST request detection guards to login handlers to prevent interference with REST API authentication endpoints
- Simplified admin notification emails by removing obsolete onboarding status tracking fields

### Technical
- New `inc/avatar-menu-items.php` file with centralized menu item builder logic
- New `inc/shop-permissions.php` file with shop permission checking functions
- Enhanced auth-utils.js with `normalizeRestRoot()` helper for proper URL handling across different multisite configurations

## [0.5.3] - 2025-12-20

### Added
- Minimum password length requirement (8 characters) across registration forms
- Turnstile captcha bypass for mobile app clients and local development environments
- Auto-login functionality after onboarding completion

### Changed
- Standardized CSS variable usage in onboarding block styles
- Refined Google sign-in button styling (fixed width, improved centering)

### Fixed
- Enhanced turnstile widget validation with existence checks
- Added minlength attribute to password input fields

### Documentation
- Updated CLAUDE.md with comprehensive Google OAuth and onboarding system documentation
- Enhanced README.md feature descriptions
- Updated implementation status and added user management documentation

## [0.5.2] - 2025-12-19

### Fixed
- Removed redundant "Sign up here" link from login tab in login/register block
- Added editor preview check in onboarding block to prevent rendering in admin/REST contexts
- Corrected Google OAuth API endpoint path in authentication utilities
- Added fallback refresh token table creation for existing plugin installations

## [0.5.1] - 2025-12-19

### Changed
- Refactored onboarding block to use WordPress build system with ES modules instead of legacy inline registration
- Updated build process to compile onboarding block and generate build assets
- Added automatic onboarding page creation during plugin activation on community site

## [0.5.0] - 2025-12-19

### Added
- Google OAuth authentication system with Sign-In buttons and RS256 ID token verification
- User onboarding system with post-registration username and artist/professional flag setup
- Onboarding Gutenberg block for streamlined user setup flow
- Shared authentication utilities (auth-utils.js) for common auth functions
- Enhanced avatar menu with artist management, link pages, and shop options
- OAuth infrastructure with RS256 ID token verification and Google service integration

### Changed
- Simplified registration form to email/password only (username and artist flags moved to onboarding)
- Registration flow now redirects to onboarding page after account creation
- Auto-generated usernames from email addresses
- Updated admin notification emails to reflect onboarding status

### Technical
- Added `inc/oauth/` directory with Google OAuth services
- Added `inc/onboarding/` directory with onboarding handlers
- Added `blocks/onboarding/` Gutenberg block
- New JavaScript files: `assets/js/auth-utils.js`, `assets/js/google-signin.js`
- Documentation: `docs/PLAN-onboarding-system.md`

## [0.4.3] - 2025-12-18

### Added
- `ec_get_artist_profile_by_slug()` function for network-wide artist profile lookup by taxonomy term slug
- Enhanced artist profile resolution with proper multisite blog switching and input validation

### Technical
- Improved artist profile relationship functions with canonical slug-to-post mapping

## [0.4.1] - 2025-12-17

### Added
- Network-wide automatic login page creation on plugin activation
- Fallback login page creation for new sites added after initial activation

### Changed
- Refactored plugin activation logic into dedicated `inc/core/activation.php` file
- Replaced BuddyPress artist invitation functions with native ExtraChill functions (`ec_get_pending_invitations`, `ec_add_artist_membership`, `ec_remove_pending_invitation`)
- Updated invitation URL parameter from `bp_accept_invite` to `ec_accept_invite`

### Technical
- Improved code organization by extracting activation logic to separate file
- Removed BuddyPress dependency for artist invitation handling
- Enhanced multisite setup automation with automatic login page creation

## [0.4.0] - 2025-12-16

### Added
- Auth token system with access tokens (15min TTL) and refresh tokens (30 days TTL)
- Device-based authentication with UUID v4 device tracking
- Network-wide refresh token storage in dedicated database table
- User badges system for artists, professionals, and team members
- Point-based rank system with 22 tiers from "Dew" to "Frozen Deep Space"
- REST API integration for login, register, and token refresh endpoints
- Mobile app authentication foundation with device management
- "Remember me" checkbox in login form
- Centralized notice system for authentication forms
- Refresh token table auto-installation on plugin activation

### Changed
- Login and registration now use REST API instead of admin-post forms
- Password reset auto-login now sets persistent auth cookie (matches registration)
- Profile URL resolution now prioritizes community profiles over author archives
- Authentication JavaScript completely rewritten for token-based auth
- CSS cleaned up to rely on theme form tokens
- Password reset error handling uses centralized notices and redirects

### Technical
- Added `inc/auth-tokens/` directory with database, service, and token helpers
- Added `inc/badges/user-badges.php` for structured user badge resolution
- Added `inc/rank-system/rank-tiers.php` for point-based ranking
- Refactored profile URL functions with explicit community and author archive helpers
- Updated plugin dependencies to include extrachill-api

## [0.3.4] - 2025-12-10

### Added
- Validation requiring artist or professional selection in join flow registration

### Changed
- Simplified avatar menu management URLs by removing pre-selection of latest artist

## [0.3.5] - 2025-12-11

### Added
- `ec_can_manage_artist()` function for network-wide artist profile permission checking

### Changed
- Updated avatar menu "Create Artist Profile" link to use dynamic site URL resolution
- Enhanced welcome email with improved link organization and added help resources (Contact Us, Tech Support)

### Technical
- Improved URL maintainability in avatar menu system
- Enhanced user onboarding experience in registration emails

## [0.3.3] - 2025-12-08

### Changed
- Replaced all hardcoded site URLs with dynamic `ec_get_site_url()` function calls for improved maintainability
- Updated CSS to use CSS variables for font sizes instead of hardcoded values
- Enhanced avatar display system with null safety checks for community blog ID
- Simplified avatar menu icon rendering by removing unused CSS classes

### Added
- New user management system overview documentation (`docs/user-management.md`)

### Technical
- Refactored URL handling in: login-register block, password reset system, author links, avatar menu, and registration emails
- Improved CSS maintainability with variable-based font sizing

## [0.3.2] - 2025-12-08

### Added
- `extrachill_new_user_registered` action hook fired after successful user creation for plugin extensibility

### Changed
- Removed fallback handling for `ec_icon()` function in avatar menu system - now always uses `ec_icon('user', 'avatar-default-icon')`
- Eliminated redundant fallback code following architectural principles

### Fixed
- PHP syntax error in avatar-display.php (removed unmatched closing brace)

## [0.3.1] - 2025-12-08

### Changed
- Replaced hardcoded blog IDs with dynamic `ec_get_blog_id()` lookups across all multisite operations for improved maintainability
- Enhanced security in redirect handler with proper `wp_unslash()` usage on REQUEST_URI
- Added validation for registration source URL with user-friendly error messaging
- Improved admin notification emails with better artist/professional flag handling
- Reordered user meta updates to prioritize registration page tracking

### Technical
- Refactored blog switching in: artist-profiles.php, author-links.php, avatar-display.php, online-users.php, user-creation.php, team-members.php
- Added fallback handling for missing `ec_get_blog_id()` function to maintain backward compatibility

## [0.3.0] - 2025-12-07

### Added
- Avatar menu now displays for logged-out users with login/register links
- Comprehensive accessibility improvements to avatar menu (ARIA attributes, keyboard navigation, screen reader support)
- Default user icon display for logged-out state using ec_icon() function

### Changed
- Refactored avatar menu from link-based to button-based toggle with proper semantic markup
- Removed redundant headings and descriptions from login/register block forms
- Reduced form container margins for better spacing (30px → 10px)
- Updated CLAUDE.md to reflect 9 active sites (Blog IDs 1–5, 7–11) with docs at Blog ID 10

### Accessibility
- Added aria-expanded attribute to avatar menu toggle button
- Implemented keyboard support (Enter/Space) for menu activation
- Added screen reader text for menu toggle
- Enhanced focus management and visual focus indicators
- Added role="menu" to dropdown container

## [0.2.4] - 2025-12-05

### Changed
- Relocated avatar upload UI functionality to extrachill-community plugin for better architectural separation of concerns
- Removed `inc/avatar-upload.php` file (avatar upload UI now provided by extrachill-community plugin)
- Removed `assets/js/avatar-upload.js` (avatar upload JavaScript now provided by extrachill-community plugin)
- Updated plugin initialization to remove require_once for avatar-upload.php
- Refactored include file loading order in main plugin file to prioritize authentication handlers

### Refactoring
- Consolidated avatar handling: extrachill-users provides network-wide display logic, extrachill-community provides upload UI
- Both plugins now use unified REST API endpoint `/wp-json/extrachill/v1/media` from extrachill-api for all avatar operations
- Cleaner separation of concerns between plugins following KISS (Keep It Simple, Stupid) principle
- Reduced modular organization from 18 to 17 include files

### Documentation
- Updated CLAUDE.md to reflect consolidated avatar system architecture
- Updated plugin loading order documentation to reflect include file reorganization
- Clarified that avatar upload interface now lives exclusively in extrachill-community plugin
- Added note that both plugins use centralized REST API endpoint for upload operations

## [0.2.3] - 2025-12-05

### Changed
- Refactored avatar upload system to use unified REST media endpoint (`/wp-json/extrachill/v1/media`) instead of dedicated user avatar endpoint
- Removed `extrachill_process_avatar_upload()` function from `inc/avatar-upload.php` - processing logic now handled by unified media endpoint in extrachill-api plugin
- Updated avatar upload JavaScript to include context and target_id parameters for unified endpoint
- Enhanced error handling in avatar upload script with additional safety checks

### Technical
- Consolidated media upload handling across plugins for better maintainability
- Updated `inc/avatar-upload.php` to focus solely on UI rendering and asset loading

## [0.2.2] - 2025-12-05

### Fixed
- Improved avatar upload nonce handling by using dedicated REST nonce instead of wpApiSettings
- Removed unnecessary wp-api script enqueue from avatar upload assets

## [0.2.1] - 2025-12-05

### Added
- `ec_get_latest_artist_for_user()` function to determine most recently active artist profile based on link page modification times
- `ec_get_link_page_count_for_user()` function to count link pages across all user artist profiles
- Enhanced logged-in user display in login/register block with avatar card and improved action buttons
- Comprehensive CLAUDE.md documentation file consolidating all architectural and development information
- Dynamic avatar menu labels that adapt based on number of artists and link pages

### Changed
- Improved error handling in avatar upload JavaScript with better fetch API response processing
- Refactored avatar menu system to leverage new artist utility functions for cleaner code
- Enhanced EC_Redirect_Handler integration with theme notice system for consistent messaging
- Simplified registration form by removing redundant explanatory text from checkboxes
- Updated README.md to reference new CLAUDE.md documentation and highlight v0.2.0 features

### Fixed
- Removed redundant message displays from password reset forms to prevent duplicate notifications
- Streamlined login/register block by removing unused notice rendering code

### Documentation
- Migrated from CLAUDE.md to comprehensive CLAUDE.md with complete plugin architecture documentation

## [0.2.0] - 2025-12-02

### Version Reset & Strategy
**Version Reset**: Intentionally reset from 1.1.1 to 0.1.1 to establish proper semantic versioning foundation. Previous versioning was inconsistent with semantic versioning principles. Starting fresh allows us to properly track architectural improvements and feature additions going forward.

### Added
- EC_Redirect_Handler class for centralized authentication flow management
- Direct block rendering for login/register and password reset forms
- Enhanced roster invitation token validation and artist profile integration
- REST API-compatible avatar upload system with vanilla JavaScript
- Improved mobile responsiveness with proper icon sprite handling

### Changed
- Major architectural refactor: moved authentication files to inc/auth/ directory
- Moved core business logic to inc/core/ directory for better organization
- Replaced jQuery AJAX with native fetch API for avatar uploads
- Removed CSS variable fallback values throughout stylesheets
- Enhanced error handling with centralized message system
- Improved nonce verification and input sanitization security

### Deprecated
- Flat file structure for authentication files (now organized in auth/ and core/ subdirectories)

### Fixed
- Improved mobile icon display using proper sprite system
- Enhanced form validation and error messaging consistency

### Security
- Enhanced CSRF protection with improved nonce verification
- Strengthened input sanitization across authentication forms
- Better security headers in REST API avatar upload endpoint

### Architecture
- **OOP Implementation**: Began implementing Object-Oriented Programming patterns with EC_Redirect_Handler class
- **Single Responsibility**: Improved code organization by separating authentication handlers from core business logic
- **Maintainability**: Enhanced code structure for better long-term maintenance and extensibility

## [0.1.1] - 2025-11-29

### Added
- Registration page and timestamp tracking for user analytics
- REST API-compatible avatar upload function for extrachill-api integration
- Team member access to wp-admin and admin bar

### Changed
- Refactored avatar upload system to use WordPress REST API instead of AJAX
- Updated plugin description to emphasize single source of truth role
- Enhanced admin notification emails with registration page information
- Improved CSS to use CSS variables without fallbacks
- Added consistent button styling across login/registration forms

### Fixed
- Removed jQuery dependency from avatar upload JavaScript
- Updated site count references to the active multisite sites (docs at Blog ID 10; wire at Blog ID 11; horoscope at Blog ID 12) in documentation

### Dependencies
- Added extrachill-api as required plugin
