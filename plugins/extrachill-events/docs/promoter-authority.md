# Promoter Authority

Promoter authority belongs to Extra Chill Events and is scoped to the current
site. A `promoter` taxonomy term remains descriptive until an administrator
explicitly verifies it and bootstraps an existing network user as its first
active owner.

Authority is stored separately from booking in `ec_promoter_organizations`,
`ec_promoter_members`, and the append-only `ec_promoter_authority_activity`
table. The schema is ready only after its current-prefix tables, InnoDB engines,
required columns, and exact uniqueness contracts pass health checks. Its
failure state does not disable venue booking abilities.

Administrators may verify or revoke organizations but are never inserted as
members automatically. Only an explicit active owner of an active verified
organization may create, update, revoke, or list memberships. Revoked rows are
preserved, membership mutations require an expected version, and the final
active owner cannot be demoted or revoked. Organizations have a hard maximum of
100 preserved membership rows; creation and listing fail explicitly if stored
data exceeds that supported bound.

Event attribution, promoter term assignment, `_promoter_type`, WordPress roles,
and team status grant no authority.

## Abilities

- `extrachill/verify-promoter-organization`
- `extrachill/revoke-promoter-organization`
- `extrachill/create-promoter-membership`
- `extrachill/update-promoter-membership`
- `extrachill/revoke-promoter-membership`
- `extrachill/list-promoter-memberships`
