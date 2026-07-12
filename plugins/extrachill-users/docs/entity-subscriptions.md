# Entity Subscriptions

Entity subscriptions are private, network-wide update preferences for canonical `festival`, `artist`, `venue`, and `location` taxonomies. They are not followers, newsletter lists, artist-email consent, or bbPress topic subscriptions. The system never returns subscriber counts.

## Consumer abilities

Authenticated users manage only their own records through:

- `extrachill/entity-subscribe`
- `extrachill/entity-unsubscribe`
- `extrachill/entity-subscription-status`

`extrachill/resolve-entity-subscription-recipients` is a non-REST ability restricted to network administrators. Runtime producers use the private PHP contract below instead.

Every input includes `entity_type`, `taxonomy`, and `slug`. The default canonical pairs are `festival/festival`, `artist/artist`, `venue/venue`, and `location/location`.

## Producer contract

Main, Wire, Events, and Community producers must register their own stable producer identifier through `extrachill_users_entity_subscription_producer_authorized`. They then call `extrachill_users_entity_subscription_recipients( $producer, $entity_type, $taxonomy, $slug )` and pass the returned IDs to `ec_users_notify()`.

Recipient resolution is a private PHP contract, not a REST ability, and only returns unique user IDs. It is denied until the producer authorizes itself through the filter. Producers must not log, render, or count recipients. For normal bell notifications, use the default `notification` delivery; the notification substrate applies the user's digest-email preference when it sends email. Producers that directly deliver email must pass `email`, which excludes users who disabled notification emails.
