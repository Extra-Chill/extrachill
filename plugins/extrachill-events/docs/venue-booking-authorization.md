# Venue Booking Authorization

Venue booking authority belongs to Extra Chill Events. Network user identity
and WordPress capabilities continue to belong to Extra Chill Users, while venue
identity remains the Events-site `venue` taxonomy term.

## Authorization

Venue authorization composes two existing platform concepts:

1. WordPress answers whether the user may operate the feature. The
   `extra_chill_team` role grants `access_events_admin`, and
   `ec_feature_available( 'venue_booking' )` keeps the initial rollout on the
   `team` rung.
2. Extra Chill Events answers which venue the user may operate through an
   active row in the site-scoped `ec_venue_members` table.

Both checks must pass. A team member without a venue relationship cannot access
that venue, and a venue relationship does not grant the WordPress capability.
Administrators retain an explicit `manage_options` override only for membership
administration so they can bootstrap an owner without receiving a synthetic
row. Ordinary venue operations still require the feature gate and an active
membership for the exact venue.

Membership has no operational role taxonomy. An active member may access the
venue booking feature. The structural `is_owner` flag only determines whether
that member may administer venue membership. Product permissions must not be
predicted before real workflow demonstrates a need.

Only `active` memberships authorize access. `invited` and `revoked` rows
authorize nothing. Invitation acceptance and first-owner claim verification
remain separate work; an administrator may bootstrap an unowned venue.

The final active owner cannot be demoted or revoked. Owner-removing mutations
lock all memberships for the venue before checking this invariant. Every
membership write also rechecks the actor's WordPress capability, rollout access,
and active owner relationship while those rows are locked.

Membership abilities are not registered until schema version 3 has been fully
verified. Installation migrates the unreleased role column to `is_owner`,
preserving only rows that were previously marked `owner`, and then removes the
obsolete column. Installation also verifies InnoDB because row locks and
transactions are part of the authorization contract.

## Abilities

- `extrachill/create-venue-membership`
- `extrachill/update-venue-membership`
- `extrachill/revoke-venue-membership`
- `extrachill/list-venue-memberships`

Every ability uses the same owner/administrator authorization service in its
permission callback and rechecks authorization during execution. Create adds an
existing network user as active. Update changes only structural ownership.
Revoke preserves the row and records its revoked timestamp. Update and revoke
require the caller's `expected_version`.
