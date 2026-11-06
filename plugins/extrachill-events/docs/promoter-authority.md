# Promoter Authority

Promoter authority belongs to Extra Chill Events and is scoped to the current
site. A `promoter` taxonomy term remains descriptive until an administrator
explicitly verifies it. Verification may optionally bootstrap an existing
network user as the first active owner (`owner_user_id`); without one, the
organization is verified with no memberships and administrators add members
afterwards. Verification returns `membership: null` in that case.

Authority is stored separately from booking in `ec_promoter_organizations`,
`ec_promoter_members`, exact action grants in `ec_promoter_venue_grants`, and the
append-only `ec_promoter_authority_activity` table. The schema is ready only
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

## Delegated Venue Actions

Delegated promoter authority is separate from direct venue membership. A grant
never satisfies `access_venue`, `manage_members`, or `manage_finances`, and it
never inserts promoter members into `ec_venue_members`. The only supported
delegated action is currently `organize_local_support`.
This branch defines and proves that venue-scoped grant only. Its event-level
Local Support consumer and event attachment arrive separately in issue #740.

Effective access requires all of the following at request time: the exact
current-site promoter term, an active verified organization, an active explicit
membership for the acting network user, the exact current-site venue term, an
active natural-key grant for that promoter, venue, and action, the established
venue-booking capability and feature gate, and a supported delegated action.
Taxonomy attachment, team status, email domain, and administrator status do not
replace any term in this formula. Administrator membership management does not
extend to delegated venue actions.

Only an explicit active direct venue owner with current feature access may issue
or reactivate a grant. Either that direct owner or an active owner of the exact
promoter may revoke it. Promoter owners cannot self-issue or reactivate grants.
Revoked rows remain stored and reactivation requires the expected version.

## Abilities

- `extrachill/verify-promoter-organization`
- `extrachill/revoke-promoter-organization`
- `extrachill/create-promoter-membership`
- `extrachill/update-promoter-membership`
- `extrachill/revoke-promoter-membership`
- `extrachill/list-promoter-memberships`
- `extrachill/create-promoter-venue-grant`
- `extrachill/revoke-promoter-venue-grant`
- `extrachill/reactivate-promoter-venue-grant`
- `extrachill/list-promoter-venue-grants`
