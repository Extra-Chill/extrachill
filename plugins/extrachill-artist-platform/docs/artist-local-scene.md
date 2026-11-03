# Artist Local Scene (phase 1)

Implements #1104.

- `local_scene` is a canonical slug accepted by create/update artist abilities and returned by artist-get, get-artist-data, and artists-list.
- artists-list accepts `local_scene` and uses an exact meta query after resolving the slug through `extrachill_users_resolve_local_scene()`.
- The single storage key remains `_local_support_scene`. Judgment call: retain and promote the existing key instead of renaming it; the existing row needs no migration, and local support and discovery cannot diverge.
- Unknown or empty scene slugs are rejected. Omitting `local_scene` leaves the scene unchanged. There is no clear-scene operation in phase 1.
- Local support uses the artist scene before the manager's default, and disabling availability preserves the hometown.
- REST create/update and CLI create/update pass the slug through (`--local-scene=<slug>`).
- `wp extrachill artists backfill-local-scenes` lists matched and unresolved legacy labels without writing. Add `--apply` to persist matched scenes. Run on the artist site using `--url`. Existing scenes are never overwritten. Mapping is owned by `extrachill_users_resolve_legacy_local_city()`.

## Validation

Run `php tests/fixtures/artist-local-scene-smoke.php` for isolated executable backend contracts. Real WordPress integration coverage is also included in `LocalSupportAvailabilityTest.php` for the managed WordPress PHPUnit harness.

## Artist manager and display

- Info has the single hometown picker, using Users' `extrachill/user-event-locations` search ability (the same contract as onboarding and settings). A search result must be selected before saving edited search text; existing scenes cannot be cleared. `_local_city` remains an optional API/legacy display label with no editor UI.
- Local support displays the shared hometown instead of offering a second picker. When there is no artist scene, opting in retains the manager-scene default. Opt-out preserves the hometown.
- Legacy form saves use `extrachill_artist_platform_set_local_scene()`, the same validated write path as the update ability. Invalid slugs return errors without replacing the scene.
- Artist cards and default profile sections prefer the resolver's hierarchy label (or name), falling back to `_local_city` if no scene can be resolved.
- No network taxonomy policy, taxonomy registration, cross-site link, release version, or changelog changes are included.

Validation for the completion: `homeboy review test extrachill-artist-platform --path <checkout> --placement local --skip-lint` passed (190 passed, 117 skipped, including 11 standalone PHP smokes). `npm run test:js -- --watch=false` passed (34 tests); `npm run build` passed.
