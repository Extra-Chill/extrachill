# Venue Booking Authorization

Venue booking authority belongs to Extra Chill Events. Network user identity
continues to belong to Extra Chill Users, while venue identity remains the
Events-site `venue` taxonomy term.

Memberships are stored in the site-scoped `ec_venue_members` table. A network
user may belong to multiple venues, but authority never crosses venue IDs.

## Roles

| Action | owner | booking_manager | marketing | finance | viewer |
|---|---:|---:|---:|---:|---:|
| `view_bookings` | Yes | Yes | Yes | Yes | Yes |
| `manage_inquiries` | Yes | Yes | No | No | No |
| `manage_holds` | Yes | Yes | No | No | No |
| `send_communication` | Yes | Yes | No | No | No |
| `manage_marketing` | Yes | No | Yes | No | No |
| `view_sales` | Yes | No | No | Yes | No |
| `finalize_settlements` | Yes | No | No | Yes | No |
| `manage_members` | Yes | No | No | No | No |

Only `active` memberships authorize actions. `invited` and `revoked` rows
authorize nothing. Invitation acceptance and first-owner claim verification
are separate work; issue #291 permits only an administrator to bootstrap an
unowned venue.

Administrators receive an explicit capability-based override without a
synthetic membership row. Global Extra Chill team status and artist membership
do not imply venue authority.

The final active owner cannot be demoted or revoked. Owner-removing mutations
lock all memberships for the venue before checking this invariant.
Every membership write also rechecks the actor's owner or administrator
authority while those rows are locked, preventing a revoked owner from
finishing an operation authorized before revocation.

Membership abilities are not registered until schema version 2 has been fully
verified. Internal authorization also fails closed with a service-unavailable
error if invoked before readiness. Installation verifies the membership table
uses InnoDB because row locks and transactions are part of the authorization
contract.

## Abilities

- `extrachill/create-venue-membership`
- `extrachill/update-venue-membership`
- `extrachill/revoke-venue-membership`
- `extrachill/list-venue-memberships`

Every ability uses the same `manage_members` authorization service in its
permission callback and rechecks authorization during execution. Create adds
an existing network user as active. Update changes only the bounded role.
Revoke preserves the row and records its revoked timestamp. Update and revoke
require the caller's `expected_version`.
