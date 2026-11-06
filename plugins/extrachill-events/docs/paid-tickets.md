# Paid tickets

Events owns offers and passes; Shop owns checkout, fee calculation, refunds, and seller settlement. The venue or promoter is seller of record, prices are tax-inclusive, and the seller is responsible for tax.

## Abilities

- `extrachill/get-event-ticket-offer`: public offer lookup by event slug or numeric ID.
- `extrachill/issue-ticket-passes`: service-only, quantity 1–10, name, email, idempotency key, Checkout Session `order_reference`, and `payment_reference`. Returns `issued` with code/URL pairs, or `sold_out` with no passes. Replaying a successful key returns the original passes without another email. Conflicting reuse returns 409.
- `extrachill/get-ticket-order-status`: service-only lookup by `order_reference`, with per-pass status, `any_redeemed`, and `refundable`.
- `extrachill/revoke-ticket-passes`: service-only, `order_reference` and `reason`. Revokes active passes atomically, or returns `ticket_already_redeemed` (409) without revoking any when a pass has been checked in.

Offer settings are exposed by set/get-event-perk and the host panel. Changing the seller requires both event-management authority and the existing commerce seller finance check. Quantity zero uses perk capacity; a positive quantity additionally caps paid passes. Active and redeemed passes consume capacity; revoked passes do not.

## Deployment

Configure `EXTRACHILL_EVENTS_TICKET_SERVICE_ASSERTION_KEYS` (key ID to secret map), or the `extrachill_events_ticket_service_assertion_keys` filter. Network target grants are restricted to POST on the three exact service ability run routes, service ID `extrachill.events.tickets`, and scope `extrachill/events:tickets`. Shop must register matching source grants and use Network-signed assertions. Normal users, including administrators, cannot invoke fulfillment directly.

Schema version 7 adds nullable payment references and indexes through the existing dbDelta installer. The schema version is independent of release versions. Paid issuance uses the existing event-capacity named lock plus a key-specific lock and an InnoDB transaction. Refund revocation locks order rows so a concurrent check-in cannot slip between the redeemed guard and revocation.

## Tests

Run PHPUnit with `phpunit-tickets-unit.xml.dist` and `phpunit-rsvp-perks-unit.xml.dist`. These are plain-PHP tests with query-shape database fakes; actual MySQL lock scheduling and deployed cross-site signatures still require integration verification.
