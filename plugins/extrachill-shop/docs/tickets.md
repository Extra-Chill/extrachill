# Seller-settled tickets

Tickets use direct Stripe Checkout charges with `stripe_account`. The connected
seller is merchant of record; Extra Chill receives only the application fee.
There is no Woo order, Stripe Tax, or Woo tax calculation. Prices include tax;
the seller is responsible for tax.

## Fees: the venue keeps the full ticket price

Ticket fees come from `extrachill_commerce_fee( 'extrachill_ticket', $subtotal, $seller, $quantity, $fee_payer )`,
which delegates to `extrachill_commerce_ticket_fee()` and reads the network
`extrachill_commerce_fees` setting (Network Admin → Payments → Fees):

| Key | Default | Meaning |
| --- | --- | --- |
| `ticket_buyer_fee_per_ticket_minor` | `100` | Buyer service fee per ticket, cents |
| `ticket_buyer_fee_flat_minor` | `0` | Buyer service fee per order, cents |
| `ticket_buyer_fee_percent` | `0` | Buyer service fee, % of the ticket subtotal |
| `processing_percent` | `2.9` | Card processing estimate, % (capped at 50). Shared with merch (#1563); read from the old `ticket_processing_percent` key when missing |
| `processing_fixed_minor` | `30` | Card processing estimate per payment, cents. Shared with merch; old key `ticket_processing_fixed_minor` |
| `ticket_percent` | `0` | Fee taken from the seller, % of the subtotal (keep 0) |

Checkout is a direct charge on the venue's account. The Service fee is grossed
up to pay card processing on the whole charge:

```
base        = subtotal + per_ticket × qty + per_order + buyer_percent × subtotal + processing_fixed
total       = ceil( base / (1 − processing_percent) )
service fee = total − subtotal                      (the "Service fee" line, quantity 1)
processing  = ceil( total × processing_percent ) + processing_fixed   (the estimate)
```

The buyer's total and Service fee never depend on who pays processing. The
application fee does, because Stripe debits processing from whichever side
the account's `controller.fees.payer` names:

| Payer (`controller.fees.payer`) | Accounts | Application fee | Venue nets | Extra Chill nets |
| --- | --- | --- | --- | --- |
| `account` | Standard (e.g. `acct_1Sf0Gm3smWpwetfq`) | `total − subtotal − processing (est) + seller fee` | `subtotal − seller fee` at the estimate | the per-ticket fee (+ seller fee) |
| `application`, `application_express`, `application_custom` | Express (every account `extrachill_shop_create_seller_stripe_account()` creates) | `total − subtotal + seller fee` | `subtotal − seller fee`, exactly | the per-ticket fee (+ seller fee) after it pays the real processing |
| unknown (`''` or anything else) | not stored and the lookup failed | as `account` (fail-safe) | at least `subtotal − seller fee` | the per-ticket fee if the account really pays; only the rounding (a few cents) if the platform does |

`extrachill_commerce_processing_payer()` maps the payer to `account` or
`platform`. **Unknown is treated as `account`** on purpose: that is the only
choice that never leaves the venue below the subtotal. Treating unknown as
`platform` would charge the whole Service fee as the application fee, and if
the account actually paid processing the venue would net `subtotal −
processing` ($19.06 on a $20 ticket). Guessing wrong the safe way costs Extra
Chill its share of that one sale, never the venue.

With the default `ticket_percent` of 0 the venue nets exactly the subtotal.
The estimate rounds up and the total is raised a cent at a time until the
`account` application fee is not negative, so an `account`-pays venue never
nets less than that at the estimate; when the processor rounds to the nearest
cent it can net one cent more. Real processing can be higher than the estimate
(international or currency-converted cards). When the account pays, that
difference comes out of the venue's payout; when the platform pays, it comes out
of Extra Chill's share. Set the estimate conservatively if that matters. Free
tickets never reach checkout (`invalid_ticket_price`), and a zero subtotal or a
platform-owned offer has no fee. With every fee and the estimate at 0 there is no
Service fee line and the application fee is 0.

Worked example at the defaults (US card, 2.9% + 30¢ actual processing):

| Order | Payer | Service fee | Buyer pays | Processing | Who pays it | Application fee | Venue nets | Extra Chill nets |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| $20 × 1 | `account` | $1.94 | $21.94 | $0.94 | venue | $1.00 | $20.00 | $1.00 |
| $20 × 1 | `application_express` | $1.94 | $21.94 | $0.94 | Extra Chill | $1.94 | $20.00 | $1.00 |
| $20 × 4 | `account` | $6.82 | $86.82 | $2.82 | venue | $4.00 | $80.00 | $4.00 |
| $20 × 4 | `application_express` | $6.82 | $86.82 | $2.82 | Extra Chill | $6.82 | $80.00 | $4.00 |

### Where the payer is stored

The seller's Stripe account record (`extrachill_shop_stripe_seller_<type>:<id>`,
read with `extrachill_shop_get_seller_stripe_account_record()`) has a
`fee_payer` field: Stripe's `controller.fees.payer` as reported, or `''` when it
isn't known yet. It is written:

- at account creation, from the `\Stripe\Account::create()` response
  (`extrachill_shop_new_stripe_account_record()`);
- on every `account.updated` webhook (`extrachill_shop_handle_account_updated()`
  → `extrachill_shop_update_account_status_cache()`);
- on status refreshes that read the account live
  (`extrachill_shop_get_account_status()` returns `fee_payer`; the Stripe status
  ability and product publish pass it through).

A save that doesn't report a payer keeps the stored one. Records saved before
the field existed are backfilled lazily: ticket and merch checkout call
`extrachill_shop_get_seller_fee_payer()`, which retrieves the account once,
saves the payer, and remembers a failed lookup for
`EXTRACHILL_SHOP_STRIPE_LIVE_STATUS_TTL` (2 minutes) so a busy checkout does not
ask Stripe on every attempt. `extrachill_shop_stripe_fee_payer()` reads the
field from an Account object, a webhook payload, or an array. Merch checkout
uses the payer too since #1563 (see
[ecommerce-integration.md](ecommerce-integration.md#4-merch-fee-sellers-pay-card-processing-1563)).

The ticket order row (`extrachill_ticket_order_<session>`) stores the split under
`amounts`: `subtotal_minor`, `service_fee_minor`, `total_minor`,
`application_fee_minor` (the application fee actually charged), `currency`, and
`processing_payer` (`account` or `platform`, the payer that fee assumed).
`sales.gross_minor` (sent to Events'
`record-checkout-ticket-sales`) is the full amount charged, Service fee included,
and `sales.fees_minor` is the application fee.

Refunds (`extrachill_shop_ticket_refund_payment()`, used for a single order, the
show-wide "refund everyone", and sold-out auto-refunds) send no `amount`, so the
buyer gets the whole charge back, Service fee included, and
`refund_application_fee` returns the application fee to the venue. Refunds never
recompute the fee: the refund metadata and `record-checkout-ticket-sales`
reversal use the stored `sales.fees_minor`, i.e. whatever was charged. The
processor does not return its fee on a refund, so whoever paid processing is out
the original processing on a refunded order: the venue when the account pays
(as with merch), Extra Chill when the platform pays (about $0.94 on a refunded
$20 ticket; the venue nets $0). Disputes are unchanged; their amounts include
the Service fee, and a lost dispute records `fees_minor` 0 whichever payer the
order assumed.

## Deployment

- Configure the encrypted **Connect Webhook Secret** beside the existing platform
  webhook secret. Subscribe a connected-account endpoint at
  `/wp-json/extrachill/v1/shop/stripe-webhook` to the events listed on Network
  Admin → Payments. Ticket Sessions use `checkout.session.completed` and
  `checkout.session.async_payment_succeeded` (issue), `checkout.session.expired`
  and `checkout.session.async_payment_failed` (close the pending Shop order row;
  Events has recorded nothing yet), `charge.refunded`, `charge.dispute.created` and `charge.dispute.closed`.
- Configure `EXTRACHILL_SHOP_TICKET_SERVICE_ASSERTION_KEYS` (key-ID map) and
  `EXTRACHILL_SHOP_TICKET_SERVICE_ASSERTION_ACTIVE_KEY_ID`. Matching Events target
  grants must allow service `extrachill.events.tickets`, scope
  `extrachill/events:tickets`, and the exact POST routes for issue-ticket-passes,
  get-ticket-order-status, revoke-ticket-passes, restore-ticket-passes,
  record-checkout-ticket-sales, and notify-commerce-seller. These are separate from
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

## Disputes

`charge.dispute.created` revokes the order's passes in Events with reason
`dispute` (Events stores it per pass as `revoke_reason`, and emails the seller),
marks the order `disputed`, and blocks refunds: Stripe refuses refunds on a
disputed charge. A buyer who already checked in keeps the check-in. A replay, or
a `created` for a dispute that has already closed, is a no-op.

`charge.dispute.closed` records `dispute_outcome` (`won` or `lost`) on the order,
appends a `{ at, note }` entry to the order's `notes`, and emails the seller
through `notify-commerce-seller`:

- **Won** (`won`, or `warning_closed` for an inquiry): Shop calls
  `extrachill/restore-ticket-passes` with reason `dispute` and the order's
  `buyer_email`. Events reactivates only the passes that the dispute revoked,
  keeping their codes so the buyer's existing passes scan again, and emails each
  restored pass to the buyer. Passes revoked for a refund or removed by a host
  stay revoked. Capacity isn't re-checked. The order goes back to `issued`, so
  seller refunds and the show-wide refund work again.
- **Lost**: the passes stay revoked and the order stays `disputed`, so it still
  can't be refunded. The returned amount is recorded with
  `record-checkout-ticket-sales` like a refund, keyed by the dispute ID, with
  `fees_minor` 0 (the platform fee isn't returned on a lost dispute) and all
  tickets reversed when the full amount went back.

A failed Events call returns an error so Stripe retries before anything is
recorded. Replays are no-ops, and the restore itself is idempotent, so the buyer
is emailed once. Only orders still `disputed` are restored or recorded.

Tests: `phpunit -c plugins/extrachill-shop/phpunit.xml.dist` from workspace root.
Stripe and the sibling Events contracts are mocked; live Connect configuration
and Events target-grant deployment still require test-mode integration testing.
