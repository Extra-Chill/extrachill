# Artist Local Scene (phase 1 backend)

Implements the backend portion of #1104.

- `local_scene` is a canonical slug accepted by create/update artist abilities and returned by artist-get, get-artist-data, and artists-list.
- artists-list accepts `local_scene` and uses an exact meta query after resolving the slug through `extrachill_users_resolve_local_scene()`.
- The single storage key remains `_local_support_scene`. Judgment call: retain and promote the existing key instead of renaming it; the existing row needs no migration, and local support and discovery cannot diverge.
- Unknown or empty scene slugs are rejected. Omitting `local_scene` leaves the scene unchanged. There is no clear-scene operation in this backend increment.
- Local support uses the artist scene before the manager's default, and disabling availability preserves the hometown.
- REST create/update and CLI create/update pass the slug through (`--local-scene=<slug>`).
- `wp extrachill artists backfill-local-scenes` lists matched and unresolved legacy labels without writing. Add `--apply` to persist matched scenes. Run on the artist site using `--url`. Existing scenes are never overwritten. Mapping is owned by `extrachill_users_resolve_legacy_local_city()`.

## Validation

Run `php tests/fixtures/artist-local-scene-smoke.php` for isolated executable backend contracts. Real WordPress integration coverage is also included in `LocalSupportAvailabilityTest.php` for the managed WordPress PHPUnit harness.

## Remaining work

The artist-manager canonical scene picker, legacy form-save integration, and canonical scene display labels remain follow-up work. The existing free-text UI continues to edit `_local_city`; it does not change the canonical scene. No network taxonomy policy, taxonomy registration, cross-site link, release version, or changelog changes are included.
