# Changelog

## [2.33.4] - 2026-10-23

### Fixed
- scope the pinned gate Homeboy binary so the cache-purge hook runs

## [2.33.3] - 2026-10-22

### Fixed
- pass a 50m recipe-run --timeout to WP Codebox for network journeys

## [2.33.2] - 2026-10-22

### Fixed
- default optional ability object inputs to empty arrays

## [2.33.1] - 2026-10-20

### Fixed
- fire generic action on Turnstile rejection

## [2.33.0] - 2026-10-20

### Added
- declare wp-coding-agents host opt-ins (WordPress chat agent)

## [2.32.0] - 2026-10-16

### Added
- supply QR images to the Data Machine poster template

## [2.31.0] - 2026-10-13

### Added
- route MCP transport requests on extrachill.com/mcp to the auth MCP endpoint

## [2.30.1] - 2026-10-11

### Changed
- drop removed homeboy-action inputs

## [2.30.0] - 2026-10-11

### Added
- light-ink logo and dark-mode palette for dark renders

## [2.29.6] - 2026-10-07

### Fixed
- stop /login -> /login/ redirect loop on subsites

## [2.29.5] - 2026-10-04

### Fixed
- require UPCOMING shows for events archive candidates

## [2.29.4] - 2026-10-03

### Fixed
- normalize every cross-site URL to its target blog's trailing-slash rule

## [2.29.3] - 2026-10-03

### Fixed
- Fix canonical URLs and relevance of cross-site linking candidates

## [2.29.2] - 2026-10-03

### Fixed
- replace O(keyspace) group flush with O(1) generation-key invalidation

## [2.29.1] - 2026-10-02

### Fixed
- journeys render the styled theme, and demo renders encode

## [2.29.0] - 2026-10-02

### Added
- dark-mode, paced Channel Bluff calendar demo with a cover frame

## [2.28.0] - 2026-10-02

### Added
- journey demo mode, rendering instructional walkthrough videos from the journey contract

## [2.27.6] - 2026-10-01

### Fixed
- keep upload URLs on the switched blog's host

## [2.27.5] - 2026-10-01

### Fixed
- block events crawlers and reconcile live server block

## [2.27.4] - 2026-09-30

### Fixed
- purge the page cache once after every deploy

## [2.27.3] - 2026-09-29

### Fixed
- send all platform mail as the system via PermissionHelper::run_as_system

## [2.27.2] - 2026-09-27

### Changed
- anonymous event submission journey (extrachill-events#910)

## [2.27.1] - 2026-09-27

### Fixed
- return a refused queued fallback instead of fataling

## [2.27.0] - 2026-09-27

### Added
- returning-artist journey — reach and edit an existing Link Page from anywhere

## [2.26.1] - 2026-09-27

### Fixed
- isolate journeys so one journey's crash can't discard the others

## [2.26.0] - 2026-09-27

### Added
- route /join by Link Page kind — artist, venue, or promoter

## [2.25.0] - 2026-09-27

### Added
- musician Link Page onboarding journey as a nightly regression test

## [2.24.1] - 2026-09-27

### Fixed
- deliver principal-less transactional mail (password resets) via system flag + queued fallback

## [2.24.0] - 2026-09-26

### Added
- journey contract + Gardner Oct 21 event-RSVP journey on the full network

## [2.23.2] - 2026-09-26

### Changed
- assert the real deploy gate and its fixed bugs, not a stale marker

## [2.23.1] - 2026-09-26

### Fixed
- rewrite migrate_post image URLs host-agnostically, gate --delete-source on unrewritten URLs

## [2.23.0] - 2026-09-25

### Added
- core-based image optimization (WebP on upload, serve existing WebP siblings) to replace Imagify

## [2.22.0] - 2026-09-25

### Added
- send /join onboarding to the right link page destination

## [2.21.0] - 2026-09-25

### Added
- answer Link Pages /edit endpoints

## [2.20.0] - 2026-09-25

### Added
- answer Link Pages public host and root page settings

## [2.19.8] - 2026-09-25

### Fixed
- route extrachill.link/join to artist signup from the host integration

## [2.19.7] - 2026-09-24

### Fixed
- bail cleanly from bootstrap when loaded outside multisite

## [2.19.6] - 2026-09-23

### Fixed
- post link page click beacons to the serving site after the cutover

## [2.19.5] - 2026-09-23

### Changed
- install the pinned Chromium before the deploy gate boots the rig

## [2.19.4] - 2026-09-23

### Changed
- use homeboy-action's WP Codebox in the deploy gate

## [2.19.3] - 2026-09-23

### Changed
- count planned results so the deploy gate actually runs

## [2.19.2] - 2026-09-23

### Changed
- always pass --dry-run to the deploy gate's resolve step

## [2.19.1] - 2026-09-23

### Changed
- run the extrachill-network rig as a pre-deploy gate
- wire managed Homeboy CI gate

## [2.19.0] - 2026-09-23

### Added
- answer Link Pages subscribe and edit endpoints for artist owners

## [2.18.0] - 2026-09-23

### Added
- Extra Chill host integration for the owner-neutral Link Pages runtime

## [2.17.6] - 2026-09-23

### Changed
- move extrachill-network Homeboy rig from Extra-Chill/.github

### Fixed
- exclude rigs/ from the release package-completeness check

## [2.17.5] - 2026-09-22

### Fixed
- move the deploy schedule off the peak hour boundaries

## [2.17.4] - 2026-09-22

### Changed
- seed the logo fixture instead of depending on a real main-site asset

## [2.17.3] - 2026-09-22

### Fixed
- ability contracts are untestable and orphaned test files run nowhere

## [2.17.2] - 2026-09-22

### Fixed
- return next_offset as 0, not null, when there is no next page

## [2.17.1] - 2026-09-22

### Fixed
- grant id-token so the shared release workflow can start

## [2.17.0] - 2026-09-22

### Added
- add extrachill/regenerate-og-card ability

## [2.16.1] - 2026-09-22

### Fixed
- report deploy config faults without blaming the component

## [2.16.0] - 2026-09-21

### Added
- supply a logo brand token for the event OG card

## [2.15.2] - 2026-09-21

### Fixed
- content-address OG card cache keys so regeneration busts the CDN cache

## [2.15.1] - 2026-09-21

### Fixed
- stop hiding components that never deployed

## [2.15.0] - 2026-09-21

### Added
- report real deploys and failures to Discord, stay silent on no-ops
- poll for tagged releases as the primary trigger; gate unattended runs on DEPLOY_AUTOMATION
- push-based network deploy workflow with checked-in Homeboy config

### Changed
- adopt the shared Homeboy release train

### Fixed
- do not report a workflow failure as a failed deployment
- export a github.com token so checkout-less resolution works
- runner.temp is step-scoped — set HOMEBOY_OUTPUT_DIR from a step
- move deploy.yml env into the job — runner/github.workspace are job-scoped

## [2.14.0] - 2026-09-19

### Added
- gate the extrachill.link Link Pages site cutover behind a network option

### Fixed
- guard extrachill-root style dependency for non-EC themes

## [2.13.0] - 2026-09-19

### Added
- let sites veto automatic term classification

## [2.12.0] - 2026-09-19

### Added
- add an ability-permission audit tool that does not run under WP-CLI

### Fixed
- stop paying for classification on no-op publish transitions

## [2.11.0] - 2026-09-17

### Added
- register auth.extrachill.com as a network site key
- track the live nginx server block as a deployable artifact
- add ability-to-site affinity index and routing docs

### Fixed
- make the QRCode permission test runnable in the sandbox
- migrate standalone core-stubbing tests to the smoke-file convention
- exclude stub files from PHPStan analysis
- clear the lint findings the release gate caught in ability-site-affinity
- allow /.well-known/ through the dotfile-deny nginx rule

## [2.10.0] - 2026-09-08

### Added
- register closed-vocabulary genre taxonomy with alias map and enforcement
- register dedicated Link Pages site

### Changed
- own network taxonomy registration instead of the theme
- isolate runtime probes from deployed plugin

### Fixed
- document the configurable blog-id guard for phpstan
- clear release preflight findings
- remove dead Breeze role-cookie wiring
- preserve cross-site retry metadata
- expose canonical profile edit URL
- purge site cache after ad delivery changes
- route site-only abilities through target bootstrap

## [2.9.0] - 2026-08-10

### Added
- add bounded cross-site service assertions

### Changed
- separate network foundation providers

### Fixed
- clear release lint blockers
- retry target-owned REST routes via loopback
- restore Composer validation compatibility

## [2.8.0] - 2026-07-28

### Added
- add approved network term classification

### Fixed
- clean stale artist term inverses
- clean deleted artist term bindings

## [2.7.0] - 2026-07-18

### Added
- complete experiment lifecycle primitive
- add cache-safe experiment assignment
- add Community composer fallback to empty bridges
- add artist profile discussions

### Fixed
- ignore hidden bridge impressions
- align bridge exposure and CTR semantics

## [2.6.0] - 2026-07-15

### Added
- measure homepage bridge destinations

## [2.5.0] - 2026-07-14

### Added
- add authoritative network ad policy

## [2.4.1] - 2026-07-14

### Fixed
- allow full resolver batches to complete

## [2.4.0] - 2026-07-14

### Added
- resolve canonical network frontend paths

## [2.3.0] - 2026-07-13

### Added
- route artist archives to Community

## [2.2.1] - 2026-07-12

### Fixed
- count Community taxonomy topics (#116)

## [2.2.0] - 2026-07-12

### Added
- route festival archives to Community (#114)

## [2.1.2] - 2026-07-12

### Fixed
- cover QR ability registration lifecycle (#110)

## [2.1.1] - 2026-07-12

### Fixed
- check QR ability registration safely

## [2.1.0] - 2026-07-12

### Added
- move QR generation to network ownership
- network menu landing page and DMB integrations settings page (#100, #101)

## [2.0.2] - 2026-07-12

### Fixed
- surface network manifesto in actual footer nav

## [2.0.1] - 2026-07-12

### Changed
- drop EXTRACHILL_MULTISITE_MENU_SLUG back-compat alias (#96)

## [2.0.0] - 2026-07-12

### Changed
- rename plugin extrachill-multisite → extrachill-network

## [1.22.1] - 2026-07-05

### Fixed
- move commerce auth providers to network layer so save works in network-admin

## [1.22.0] - 2026-07-04

### Added
- consolidate + harden network commerce credentials (Stripe + Shippo)

### Changed
- write network commerce credentials via encrypted auth providers
- retire artist-profile Coverage button-row section

### Fixed
- rewrite inline image src URLs via prefix swap during cross-site migration

## [1.21.0] - 2026-07-03

### Added
- add generic cross-site post migration primitive + ability

### Fixed
- harden Breeze role cookie to prevent auth users served cached anon pages

## [1.20.2] - 2026-06-30

### Fixed
- skip in-process community-stats fast-path off-blog to stop _doing_it_wrong notice #82

## [1.20.1] - 2026-06-30

### Changed
- extract single-post network bridge into shared multisite primitive

### Fixed
- route community-stats loopback through canonical extrachill/v1

## [1.20.0] - 2026-06-22

### Fixed
- clear phpstan always-true findings via direct array access and casts
- prefer entity matches over geography, link past-show artist archives, and skip thin stubs

## [1.19.2] - 2026-06-22

### Fixed
- emit one impression per rendered card with dest_site so CTR can pair impressions to destinations

## [1.19.1] - 2026-06-20

### Fixed
- events_count counts UPCOMING events, not all-time published total
- silence deprecated print_emoji_styles notice network-wide

## [1.19.0] - 2026-06-19

### Added
- add composable NetworkStats metric-provider registry + get-network-stats ability
- route internal links toward forward surfaces via cross-site candidates

### Changed
- own the online-users query in OnlineUsersProvider + add metric cache invalidation

## [1.18.1] - 2026-06-17

### Changed
- move bridge beacon receiver to canonical extrachill-api routes

## [1.18.0] - 2026-06-16

### Added
- feat(cross-site): instrument bridge click + impression events in shared engine

## [1.17.1] - 2026-06-15

### Fixed
- guard ability category registration against double-fire _doing_it_wrong notice

## [1.17.0] - 2026-06-15

### Added
- register cross-site Coverage section on artist profile hub

### Changed
- clear pre-existing phpcs errors in legacy-path-redirects

### Fixed
- validate festival-wire slug before forwarding to wire subsite

## [1.16.1] - 2026-06-15

### Fixed
- resolve cross-site term counts in-process to stop nginx loopback rate-limiting

## [1.16.0] - 2026-06-06

### Added
- render Turnstile widgets explicitly per-widget so one bad widget cannot break siblings

### Changed
- add cross-widget Turnstile browser smoke
- add PHPUnit coverage for Cloudflare Turnstile integration
- clear phpcs lint debt (release preflight)

### Fixed
- remove dead 'stream' and 'chat' entries from site labels

## [1.15.0] - 2026-05-30

### Added
- add comment editor abilities and editor helpers (#33)

### Changed
- cache cross-site term links in the object cache

## [1.14.1] - 2026-05-18

### Fixed
- single owner for extrachill-multisite ability category (closes #31)

## [1.14.0] - 2026-05-18

### Added
- EC email templates + ec_send_email wrapper (closes #26)

## [1.13.2] - 2026-05-18

### Fixed
- anchor README.md exclusion to repo root only

## [1.13.1] - 2026-05-18

### Fixed
- graceful activation in non-interactive contexts (Playground, CLI)

## [1.13.0] - 2026-05-17

### Added
- add reusable request-check + permission_callback helpers

## [1.12.4] - 2026-05-12

### Fixed
- guard admin-only function calls in activation hook for Playground bootstrap
- unblock the VPS's own IPv6 + CF origin path

## [1.12.3] - 2026-05-10

### Fixed
- make ec_cross_site_rest_request a universal cross-site REST primitive

## [1.12.2] - 2026-05-10

### Fixed
- fix(og-cards): consume data-machine-events public integration API

## [1.12.1] - 2026-05-10

### Changed
- dispatch cross-site REST in-process via switch_to_blog

## [1.12.0] - 2026-04-26

### Added
- add network-media-list and network-media-upload abilities for cross-site main media library access

## [1.11.0] - 2026-04-25

### Added
- feat(og-cards): generate branded OG cards for posts without featured images

### Changed
- Remove stream.extrachill.com (Blog ID 8) from network config

## [1.10.0] - 2026-04-04

### Added
- add blog/main route affinity to cross-site map

## [1.9.0] - 2026-04-03

### Added
- add universal cross-site REST request helper

## [1.8.5] - 2026-04-02

### Changed
- align TaxonomyCountAbilities with core WP_Ability contract

## [1.8.4] - 2026-03-29

### Changed
- Remove duplicate taxonomy-badges.css — theme already provides all badge styles

## [1.8.3] - 2026-03-28

### Changed
- Add ec-surface-card class to community activity sidebar widget
- Document why delete-after-create is used for default content
- Remove default WordPress content from new network sites
- Add ec_get_all_site_ids() for dynamic site discovery

## [1.8.2] - 2026-03-25

### Changed
- remove chat.extrachill.com (blog 5) from network registry

## [1.8.1] - 2026-03-24

### Changed
- show Posts menu in wp-admin on studio site

## [1.8.0] - 2026-03-23

### Added
- team-gated Studio in network dropdown and footer, add filter hooks

### Fixed
- sync taxonomy-badges.css with @extrachill/tokens@0.4.1 (232 badges, fix washington split)

## [1.7.0] - 2026-03-23

### Added
- sync taxonomy badge CSS from @extrachill/tokens (99 → 134 badges)

## [1.6.0] - 2026-03-23

### Added
- add taxonomy count ability and badge count cron warmer
- add minimum threshold for cross-site link count display

### Changed
- remove Studio from public multisite navigation
- add Studio as native multisite platform site
- replace hardcoded badge colors with CSS custom properties

### Fixed
- Fix Festival Wire footer link to point to wire.extrachill.com instead of extrachill.com/festival-wire
- remove double underline on sidebar activity card links
- enqueue community activity CSS on singular pages
- Restore original community activity CSS styling 1:1

## [1.5.0] - 2026-02-22

### Changed
- Move community activity from theme to multisite plugin

## [1.4.11] - 2026-01-28

### Changed
- Update cross-site link label to Festival Wire

## [1.4.10] - 2026-01-28

### Changed
- Migrate cross-site-links functions from ec_ to extrachill_ prefix

## [1.4.9] - 2026-01-28

### Changed
- Add community site to location taxonomy cross-site linking

## [1.4.8] - 2026-01-21

- Add descriptive cross-site taxonomy link button labels with term name and content type

## [1.4.7] - 2026-01-21

- Register social links data via extrachill_social_links_data filter for footer icons

## [1.4.5] - 2026-01-19

### Added
- Added theme integration hooks for 404 content, admin menu, DNS prefetch, footer main menu, network dropdown, and site title
- Updated cross-site links and canonical authority system
- Improved admin settings pages (OAuth, payments, security, shipping)
- Updated blog IDs and Turnstile integration
- Removed vendor directory from git tracking (now gitignored)

## [1.4.4] - 2026-01-19

### Added
- Added music-specific taxonomy badge colors (festivals, locations, venues, artists)
- Added artist dropdown filter for song-meanings and music-history categories
- Added EC footer bottom menu links via theme filter

## [1.4.3] - 2026-01-08
### Added
- Object Cache Pro configuration via `objectcache_config` filter to mark `co-authors-plus` as a non-prefetchable group.
- Canonical authority resolution for shared taxonomy archives across the multisite network.

### Changed
- Plugin initialization now loads the Object Cache Pro config module and canonical authority resolver.

## [1.4.2] - 2026-01-05
### Added
- Dynamic lookup for `EC_PLATFORM_ARTIST_ID` from network options with fallback to production ID.
- User-managed artist profile links in cross-site user link resolution via `ec_get_artists_for_user()`.

### Changed
- Refined cross-site user link resolution to include links to published artist profiles managed by the user.

## [1.4.1] - 2026-01-05
### Added
- REST API integration for cross-site taxonomy counts (Main, Events, Shop, Wire)
- Internal REST API calls via `rest_do_request()` for zero-overhead data retrieval
- Support for `events` site in artist taxonomy mapping

### Changed
- Refactored `ec_get_cross_site_artist_links` to use REST API for upcoming events and shop products
- Optimized `ec_get_cross_site_term_links` to use REST APIs for accurate cross-site content counts
- Removed `extrachill_archive_header_actions` hook for artist archive profile links (now handled via REST-backed resolution)
- Updated artist profile link resolution to use CPT matching on artist site instead of taxonomy

## [1.4.0] - 2026-01-05
### Added
- Unified Cross-Site Links system (`inc/cross-site-links/`) for network-wide navigation
- Taxonomy archive cross-linking with content existence verification
- User profile and artist profile cross-site link resolution
- Support for Blog ID 12 (Horoscope) as an active site
- Enhanced documentation for cross-domain authentication and REST nonces

### Changed
- Updated network site count to 11 active sites (IDs 1-5, 7-12)
- Refined Blog ID helper documentation with recommended patterns

## [1.3.1] - 2025-12-23
### Added
- `ec_get_allowed_redirect_hosts()` function to retrieve all network domains as allowed redirect hosts from domain map
- `ec_filter_allowed_redirect_hosts()` function to register network domains as allowed redirect targets for wp_safe_redirect()
- Automatic registration of all network subdomains as allowed redirect hosts via allowed_redirect_hosts filter
- `ec_allowed_redirect_hosts` filter for extensibility of allowed redirect hosts list

## [1.3.0] - 2025-12-22
### Added
- Support for wire.extrachill.com site (Blog ID 11) with EC_BLOG_ID_WIRE constant
- Legacy path redirects module (`inc/core/legacy-path-redirects.php`) for `/festival-wire` URLs to wire.extrachill.com
- Updated site count and blog ID mappings in documentation

### Changed
- Moved EC_BLOG_ID_HOROSCOPE from 11 to 12 to accommodate wire site
- Updated blog ID arrays and helper functions to include wire site
- Updated README.md site count description (9 active sites, IDs 1-5,7-11)
- Updated docs/blog-id-helpers.md with wire site documentation and horoscope ID correction

## [1.2.1] - 2025-12-20
### Added
- Network Shipping Settings admin page for Shippo API key configuration and shipping label integration
- `EC_PLATFORM_ARTIST_ID` constant (value: 12114) for internal artist profile identification
- Automatic Turnstile verification bypass for local development environments (WP_ENVIRONMENT_TYPE = 'local')

### Changed
- Enhanced `ec_verify_turnstile_response()` with environment-aware bypass logic for streamlined local testing

## [1.2.0] - 2025-12-20
### Added
- iOS OAuth Client ID field in network OAuth settings page for native iOS app authentication
- Android OAuth Client ID field in network OAuth settings page for native Android app authentication
- `ec_get_google_ios_client_id()` helper function to retrieve iOS OAuth client identifier
- `ec_get_google_android_client_id()` helper function to retrieve Android OAuth client identifier
- Setup instructions for iOS and Android OAuth client creation in network settings

### Changed
- Updated README.md to document OAuth and Payment Provider Settings features
- Enhanced network OAuth settings page with iOS and Android configuration fields
- Improved OAuth settings form layout with aligned variable declarations

## [1.1.1] - 2025-12-19
### Changed
- Refactored OAuth helper functions to dedicated `inc/core/oauth-helpers.php` module for better code organization
- Moved `ec_is_google_oauth_configured()` and `ec_is_apple_oauth_configured()` from `admin/network-oauth-settings.php` to core module

## [1.1.0] - 2025-12-19
### Added
- Network OAuth settings page for Google Sign-In and Apple Sign-In configuration
- Network Payments settings page for Stripe Connect configuration
- Documentation for blog ID helper functions and usage patterns
- Documentation for cross-domain authentication patterns
- Documentation for Cloudflare Turnstile integration in registration forms

## [1.0.3] - 2025-12-11
### Added
- `ec_site_url_override` filter hook in `ec_get_site_url()` for development environment URL overrides

## [1.0.2] - 2025-12-08
### Added
- `ec_get_site_url()` helper function for logical site URL resolution

### Changed
- Corrected extrachill.link domain mapping to artist.extrachill.com
- Fixed Turnstile verification failures affecting registration and contact forms
- Added avatar menu for cross-platform reusability

### Technical
- Updated composer vendor package references

## [1.0.1] - 2025-12-08
### Added
- Blog ID mapping system (`inc/core/blog-ids.php`) with comprehensive site routing
- Domain mapping support for extrachill.link → artist.extrachill.com
- Helper functions for cross-site blog ID resolution

### Changed
- Updated plugin description to reflect network administration focus
- Improved README with current architecture and site count
- Fixed plugin reference in security settings (community → users)

### Technical
- Added blog-ids.php include to plugin initialization
- Updated composer dependencies
