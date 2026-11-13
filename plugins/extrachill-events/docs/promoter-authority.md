# Promoter Authority

Promoter authority belongs to Extra Chill Events and is scoped to the current
site. A `promoter` taxonomy term remains descriptive until an administrator
explicitly verifies it. Verification may optionally bootstrap an existing
network user as the first active owner (`owner_user_id`); without one, the
organization is verified with no memberships and administrators add members
afterwards. Verification returns `membership: null` in that case.

Verification is stored on the promoter term in `_ec_promoter_status` and
`_ec_promoter_verification`. Memberships remain `promoter` rows of the network
`ec_team_members` table. Mutations lock the current site's term row before
reading verification or changing authority; optimistic versions are preserved.
There is no promoter schema installer or readiness gate.

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

## Term-meta migration

The CLI-only Data Machine migration `extrachill-events.promoter-verification`
moves legacy verification to promoter term state on the Events blog:

- `_ec_promoter_status`: scalar `active` or `revoked`, for SQL projections.
- `_ec_promoter_verification`: audit array containing `version`,
  `verified_by_user_id`, `verified_at`, `updated_at`, `revoked_by_user_id`,
  and `revoked_at`.

Inspection is read-only and reports legacy-table existence and the number of
organizations without status metadata. Apply copies audit data before publishing
status, skips already migrated terms, and drops the legacy table only after no
unmigrated rows remain. Failed copies preserve the table and can be retried.

Until migration runs, exact organization reads fall back read-only to the
legacy table only when status metadata is absent. SQL discovery joins use term
metadata immediately. Run the migration through WP-CLI, never a web request.

## Abilities

- `extrachill/verify-promoter-organization`
- `extrachill/revoke-promoter-organization`
- `extrachill/create-promoter-membership`
- `extrachill/update-promoter-membership`
- `extrachill/revoke-promoter-membership`
- `extrachill/list-promoter-memberships`

## Managed events

`extrachill/list-managed-events` and `extrachill/update-managed-event`
(`inc/Abilities/ManagedEventAbilities.php`) let promoters and venue managers
find and edit their own shows without staff capabilities, including from an
agent connected through the Extra Chill MCP.

- Scope (`ManagedEventScope`): published events at a venue where the user is
  an active venue-team member, or tagged with a promoter where they are an
  active promoter-team member. Holders of `edit_others_posts` may pass
  `scope: all`. Under an agent principal, team scope needs `read` in the
  ceiling and a principal that represents this user; `scope: all` needs
  `edit_others_posts` in the ceiling.
- List input: `when` (`upcoming` default, `past`, `any`), `date_from`,
  `date_to`, `venue_term_id`, `promoter_term_id`, `limit` (max 100), `page`.
  Filters narrow the user's scope and never widen it.
- Update accepts only `startDate`, `startTime`, `endDate`, `endTime`,
  `description`, `price`, `ticketUrl`, `eventStatus`, and `eventType`, and only
  on published events. Venue, promoter, organizer, title, artists, image, and
  post status are refused, so an edit cannot move an event out of scope or
  publish it. It runs Data Machine Events' own update path and marks the event
  human-owned, so later imports only fill empty fields.
- Both are `show_in_rest: true`, because the MCP reaches the Events site
  through `/wp-abilities/v1`. Data Machine Events' generic `update-event` stays
  staff-only.
