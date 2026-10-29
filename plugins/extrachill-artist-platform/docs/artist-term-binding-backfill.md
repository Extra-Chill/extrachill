# Canonical artist binding repair (#262)

Artist creation now synchronizes the canonical main-site `artist` term before reporting success. The ability resolves genres first so binding mirrors the saved vocabulary. Binding failures roll back the new profile and membership; incomplete rollback is reported for manual reconciliation. Platform provisioning also requires binding before storing its success option.

The existing `admin_init` migration advances `extrachill_artist_platform_term_binding_integrity_backfill` from `2.0.0` to `3.0.0`. After deployment, an admin request runs the canonical sync for existing profiles, creating missing terms and reciprocal metadata rather than merely looking up slug matches. Valid bindings are preserved. The version is recorded only when every sync succeeds, allowing later requests to retry failures.

To run explicitly with WordPress loaded, call `ec_backfill_artist_term_bindings()` (for example with WP-CLI `wp eval 'ec_backfill_artist_term_bindings();'` on the artist site). Check the migration option for `3.0.0` afterward. This repository change does not itself execute the repair against production.

Binding mutations require MySQL advisory locks; SQLite runs skip the corresponding regression tests.
