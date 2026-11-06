# Users privacy lifecycle

## Policy and inventory

Users retains no custom-table rows after erasure. There is no legal retention
exception for these records. Every table uses the network base prefix, not the
currently selected site's prefix.

| Users-owned store | User reference / contents | Action |
| --- | --- | --- |
| ec_concert_tracking | user_id; attendance marks and private show ratings | Delete |
| ec_concert_import_runs | user_id; external_username, errors, progress | Delete |
| ec_entity_subscriptions | user_id; followed entity and delivery preference | Delete |
| ec_notifications | user_id recipient, actor_id actor; title, link, delivery receipts | Delete rows for either reference |
| Shared usermeta | Explicit keys in `extrachill_users_privacy_meta_keys()` | Delete by metadata ID; invalidate core caches |
| Shared usermeta | ec_concert_import_*_username, welcome_email_sent_* | Delete; includes retired import sources |

The metadata inventory covers registration attribution, activity timestamps,
onboarding and welcome receipts, profile fields and avatar references, local
scene and visibility preferences, team flags, points cache, lifetime membership,
moderation, dispatch access/audit/delivery state, newsletter registration receipt,
legacy notification blobs, notification preferences, OAuth identifiers/tokens,
and account claim state. No membership or moderation exception is retained by
this implementation. Erasure does not delete the still-existing core account.

Cross-owner boundaries: `_artist_profile_ids` and artist/venue subscriber and
consent records belong to their owning plugin. Auth token tables belong to
wp-native-auth. Core account/session/profile and site membership metadata remain
core's responsibility. Owners must register their own
`wp_privacy_personal_data_erasers` callbacks. On account deletion they can also
listen to `extrachill_users_erase_user_data( int $user_id )`, while the account
still exists. Callbacks must be idempotent, remove all owner references and stop
deletion on failure. Users performs no cross-owner table SQL.

## Operation

Core's eraser callback resolves the email to the current account and drains
100 rows per table plus 100 metadata rows per invocation. Pagination deliberately
never uses OFFSET. Replaying any page safely drains remaining rows. Missing
tables are empty, not installed as a side effect. Query failures report retained
items and failed operation counts. The batch helper leaves `done=false`; the
core eraser callback reports `done=true` with `items_retained` so core's UI does
not page forever, and the request is rerun after storage is repaired. The additional numeric
`removed`, `retained` (policy exceptions, always zero), and `failed` fields and
core messages distinguish deletion counts from failed operations; a failed query
cannot reliably count affected rows. Never include database error text in a
privacy response.

Account deletion (`wpmu_delete_user` on multisite, `delete_user` on single
site) synchronously drains the same policy before core removes the ID. On
multisite, `wp_delete_user()` only removes the user from one site, so its
`delete_user` hook is ignored. Storage failure stops deletion rather than leaving
an identifier that a later account could inherit. Removing membership from one
site does not erase the network account. Administrators must retry after fixing
the underlying database failure. This fail-closed behavior can leave an account
with partially erased data; there is no transaction across plugin owners.

`extrachill_users_privacy_orphan_counts()` returns only distinct orphan-ID counts
per table/reference, excluding zero sentinels. Missing tables return zero;
unavailable queries return null. It emits no personal data. Historical orphans
can be erased with the batch helper by known ID after investigating ownership;
the diagnostic itself does not expose IDs or delete anything.

## Known gaps

This change inventories all four current Users custom tables. A complete audit
of historical cross-site postmeta, avatar attachments, producer-specific
notification payloads not keyed by actor_id, external OAuth revocation, and
concurrent import/notification writers is not claimed. Scheduled import jobs
reference run IDs and become no-ops when their run is absent, but an already
running worker can race erasure. Operators should quiesce writers during
compliance erasure until a durable write barrier is implemented. Other owners'
erasers must be installed before claiming end-to-end network compliance.
