# Shareable roundups

`wp extrachill events roundup --date-start=2026-10-30 --date-end=2026-11-01 --location=charleston --event-type=concert,dj-set,dance-party --cover --closing --background=auto`

Background accepts `auto`, one blog-1 attachment ID, or comma-separated IDs in rendered slide order (cover, lists, closing). Unavailable photos retain the flat background. No photographer credit is drawn. The `slides` result contains event coordinates, taxonomy IDs, Instagram `user_tags` (maximum 20), and photo credit fields for the caption step.

## Editorial safety prerequisite

On blog 1, set attachment meta `_extrachill_roundup_composition` to `room` for approved wide stage/crowd/venue images or `performer` for approved performer-only fallbacks. Never approve an attendee-subject photo. This gate also applies to explicit IDs: dimensions cannot establish whether a person is an attendee. Without approved media, auto intentionally falls back to a solid background. Room images rank before performer fallbacks; landscape ranks before portrait within each group.

Candidates come from the latest 40 published concerts-category (2669) posts with matching location. Recap text supplies photographer credit, then image EXIF copyright/credit, then recap author. Photo history excludes previous-calendar-week and current-build images; the current implementation does not yet enforce distinct venue/room rotation. History currently records selection rather than successful publication. Instagram artist priority uses the first returned artist term; explicit headliner ordering requires upstream data.

The CLI must run as a user with upload_files. Live multisite/GD rendering and downstream caption publication must be verified in staging. Versions and CHANGELOG are intentionally unchanged.
