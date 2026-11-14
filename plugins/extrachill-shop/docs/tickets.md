# Seller-settled tickets

Tickets use direct Stripe Checkout charges with `stripe_account`. The connected
seller is merchant of record; Extra Chill receives only the application fee.
There is no Woo order, Stripe Tax, or Woo tax calculation. Prices include tax;
the seller is responsible for tax. Merch checkout is unchanged.

Ticket fees come from `extrachill_commerce_fee( 'extrachill_ticket', … )`, which
reads the network `extrachill_commerce_fees` setting (Network Admin → Payments → Fees):
`ticket_buyer_fee_flat_minor` (per checkout), `ticket_buyer_fee_percent` and
`ticket_percent` (paid by the seller). Until that page is saved, the values come
from the older `extrachill_ticket_fees` option (all zero). Percent fees are
rounded to the nearest minor unit on the ticket subtotal, independently. A
nonzero buyer fee is a separate Service fee line. Zero fees omit that line and
use a zero application fee.

## Deployment

- Configure the encrypted **Connect Webhook Secret** beside the existing platform
  webhook secret. Subscribe a connected-account endpoint at
  `/wp-json/extrachill/v1/shop/stripe-webhook` to the events listed on Network
  Admin → Payments. Ticket Sessions use `checkout.session.completed` and
  `checkout.session.async_payment_succeeded` (issue), `checkout.session.expired`
  and `checkout.session.async_payment_failed` (close the pending Shop order row;
  Events has recorded nothing yet), `charge.refunded` and `charge.dispute.created`.
- Configure `EXTRACHILL_SHOP_TICKET_SERVICE_ASSERTION_KEYS` (key-ID map) and
  `EXTRACHILL_SHOP_TICKET_SERVICE_ASSERTION_ACTIVE_KEY_ID`. Matching Events target
  grants must allow service `extrachill.events.tickets`, scope
  `extrachill/events:tickets`, and the exact POST routes for issue-ticket-passes,
  get-ticket-order-status, and revoke-ticket-passes. These are separate from
  priority-boost keys. Without Events and its matching grants, issuance fails
  and webhook delivery must be retried.
- Pilot seller: Lo-Fi Brewing. Ensure its canonical venue binding points to a
  charges-enabled connected account. Exercise test-mode checkout and webhook
  delivery before enabling a paid offer.

Shop stores immutable seller/account bindings per Checkout Session in non-autoloaded
options and a payment-intent index. Events issuance uses the session ID as both
idempotency key and order reference. Sold-out payments are refunded on the stored
account with application fees returned; the reason is persisted. Stripe refund
requests use a stable per-session idempotency key. Webhook failures return errors
for Stripe retries; order state is marked issued only after Events confirms it.

## Refund ordering deviation

Seller refunds authorize against the stored seller and consult Events status.
After that check, passes are revoked **before** the Stripe refund: Events must
atomically refuse revocation if check-in has occurred. Refunding first, as in the
issue's proposed ordering, would permit a check-in race that refunds a redeemed
pass. A failed Stripe call leaves `refund_pending`; retry the same ability to
complete it (passes stay revoked). This state requires operator attention if
Stripe continues failing. This is not a distributed transaction.

Refund webhooks also revoke passes (including partial refunds, conservatively).
A refund already initiated externally after check-in cannot be undone: log the
Events `ticket_already_redeemed` response and acknowledge the webhook. Payment
webhooks that arrive before the completion/index write return a retryable error.

Tests: `phpunit -c plugins/extrachill-shop/phpunit.xml.dist` from workspace root.
Stripe and the sibling Events contracts are mocked; live Connect configuration
and Events target-grant deployment still require test-mode integration testing.
