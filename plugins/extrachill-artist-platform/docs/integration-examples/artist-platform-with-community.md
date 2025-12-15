# Artist Platform + Community (Out of Scope)

This plugin does not implement community forum provisioning or roster/forum synchronization.

If another plugin (e.g. a community/forums plugin on the same multisite network) wants to integrate with artist profiles and rosters, it should do so by consuming artist platform data and using WordPress hooks and REST APIs.

## Recommended Integration Points

### Artist Profile Creation

When an artist profile is created via the platform, integrators can attach their own behavior using WordPress actions (when available) or by reacting to the create-artist flow on their own side.

### Roster Membership Changes

Roster invitations and acceptance are stored and processed on the artist profile:

- Pending invitations are stored in artist profile post meta under `_pending_invitations`.
- Accepted roster membership is stored in artist profile post meta (see `inc/artist-profiles/roster/`).

Integrators should read these values (or expose their own REST endpoints) rather than relying on blog switching or bbPress-specific behavior from this plugin.

## Notes

- Cross-domain authentication details for `extrachill.link` are documented in `docs/authentication/cross-domain-authentication.md`.
- Community/forum-specific logic (bbPress, BuddyPress, blog switching) should live in the community plugin, not in this artist platform plugin.
