# Promoter Authority

Promoter authority belongs to Extra Chill Events and is scoped to the current
site. A `promoter` taxonomy term remains descriptive until an administrator
explicitly verifies it. Verification may optionally bootstrap an existing
network user as the first active owner (`owner_user_id`); without one, the
organization is verified with no memberships and administrators add members
afterwards. Verification returns `membership: null` in that case.

Authority is stored separately from booking in `ec_promoter_organizations`,
and `promoter` rows of the shared `ec_team_members` table.
The schema is ready only
after its current-prefix tables, InnoDB engines,
required columns, and exact uniqueness contracts pass health checks. Its
failure state does not disable venue booking abilities.

Administrators may verify or revoke organizations but are never inserted as
members automatically. On an active verified organization, memberships may be
created, updated (including setting or clearing `is_owner`), revoked, or listed
by either:

- a site administrator (`manage_options`), without needing any membership; or
- an explicit active owner of that organization.

Active non-owner members cannot manage memberships. Every path, including the
administrator path, is reauthorized against lock-current rows at write time.
The administrator check is the same one used for verify and revoke
(`PromoterAuthorization::is_site_admin()`), so it composes the active
execution-principal ceiling: an agent acting for an administrator whose
ceiling lacks `manage_options` is denied. Revoked organizations accept no
membership changes from anyone.

Revoked rows are preserved and membership mutations require an expected
version. An owner cannot demote or revoke the final active owner, which
prevents owner self-lockout. Administrators are not bound by that rule and may
leave an organization ownerless, because they keep membership management
regardless of owner state. An ownerless organization stays verified and its
members keep promoter product access; only administrators can manage its team
until an owner is assigned. Organizations have a hard maximum of 100 preserved
membership rows; creation and listing fail explicitly if stored data exceeds
that supported bound.

Event attribution, promoter term assignment, `_promoter_type`, WordPress roles,
and team status grant no authority.

## Abilities

- `extrachill/verify-promoter-organization`
- `extrachill/revoke-promoter-organization`
- `extrachill/create-promoter-membership`
- `extrachill/update-promoter-membership`
- `extrachill/revoke-promoter-membership`
- `extrachill/list-promoter-memberships`
