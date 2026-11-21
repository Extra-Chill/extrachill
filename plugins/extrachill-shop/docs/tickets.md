# Seller-settled tickets

Tickets use direct Stripe Checkout charges with `stripe_account`. The connected
seller is merchant of record; Extra Chill receives only the application fee.
There is no Woo order, Stripe Tax, or Woo tax calculation. Prices include tax;
the seller is responsible for tax.

## Fees: the venue keeps the full ticket price

Ticket fees come from `extrachill_commerce_fee( 'extrachill_ticket', $subtotal, $seller, $quantity, $fee_payer, array( 'event_id' => … ) )`,
which delegates to `extrachill_commerce_ticket_fee()` with the rates
`ec_commerce_fee_settings()` resolves for that show (see
[Fee overrides](#fee-overrides)). The default is the network
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

### Fee overrides

A seller or a single event can override any of the fields above (#1698).
Rates resolve field by field: the network setting, then the seller override,
then the event override. A field an override does not set inherits; `0` is an
override (a memorial show with no fees at all). One filter,
`ec_commerce_fee_settings_resolved( $settings, $context, $layers )`, runs on the
result, and the result is sanitized again.

| Override | Stored in |
| --- | --- |
| Seller (`type:id`) | Shop blog option `extrachill_shop_fee_seller_{type}:{id}`, next to the seller's `extrachill_shop_stripe_seller_*` and `extrachill_shop_shipping_seller_*` records |
| Event | Registered post meta `_extrachill_commerce_fee_override` on the `data_machine_events` post (Events blog) |

Only network admins (`manage_network_options`) change them, through
`extrachill/get-|set-|clear-commerce-fee-override` or
`wp extrachill shop fees get|set|clear <event:ID|type:id>`. A checkout reads
the rates when it starts and the order stores the fee it charged
(`application_fee_minor`, `service_fee_minor`), so changing or clearing an
override never changes a placed order. Merch resolves with the seller (no
event).

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

The ticket order (see [Storage](#storage-woocommerce-orders)) stores the split
in `_extrachill_ticket_amounts`: `subtotal_minor`, `service_fee_minor`,
`total_minor`, `application_fee_minor` (the application fee actually charged),
`currency`, and `processing_payer` (`account` or `platform`, the payer that fee
assumed). The sale sent to Events' `record-checkout-ticket-sales` reports
`gross_minor` as the full amount charged, Service fee included, and `fees_minor`
as the application fee.

Refunds (`extrachill_shop_ticket_refund_payment()`, used for a single order, the
show-wide "refund everyone", and sold-out auto-refunds) send no `amount`, so the
buyer gets the whole charge back, Service fee included, and
`refund_application_fee` returns the application fee to the venue. Refunds never
recompute the fee: the refund metadata and `record-checkout-ticket-sales`
reversal use the stored `application_fee_minor`, i.e. whatever was charged. The
processor does not return its fee on a refund, so whoever paid processing is out
the original processing on a refunded order: the venue when the account pays
(as with merch), Extra Chill when the platform pays (about $0.94 on a refunded
$20 ticket; the venue nets $0). Disputes are unchanged; their amounts include
the Service fee, and a lost dispute records `fees_minor` 0 whichever payer the
order assumed.

## Storage: WooCommerce orders

Since #1597 each ticket sale is a WooCommerce order (HPOS), created with
`wc_create_order()` before the Checkout Session. There is no product and no
cart; the option rows (`extrachill_ticket_order_*`, `extrachill_ticket_payment_*`)
and the buyer user-meta index are gone. `inc/core/ticket-orders.php` documents
every field.

- **Lines:** one line item `Event tickets: <show>` with no product (quantity =
  tickets, total = ticket subtotal, item meta `event_id`), plus a `Service fee`
  fee line when the buyer pays one. Billing name and email come from checkout;
  `customer_id` is the logged-in buyer (0 for a guest). The payment method is
  `extrachill_ticket_checkout`, which has no gateway, so WooCommerce never
  refunds the payment itself.
- **Meta:** `_extrachill_order_kind` = `extrachill_ticket`, the seller index
  `_extrachill_seller` and `_extrachill_stripe_account` (as merch),
  `_extrachill_ticket_event`, `_extrachill_ticket_amounts`,
  `_extrachill_fee_payer`, `_extrachill_terms_accepted_at` /
  `_extrachill_terms_version`, `_extrachill_checkout_sessions`, and
  `_extrachill_payment_intent` once paid.
- **Order reference:** the Checkout Session ID, stored as
  `_extrachill_ticket_reference`. Events keys passes, sales and
  `get-ticket-order-status` by it, so nothing changed there. The Session's
  metadata carries `kind` and `order_id`.
- **Notes:** state changes, refunds and dispute outcomes are WooCommerce order
  notes.

| Ticket state | WooCommerce order |
|---|---|
| pending | `pending` while the Checkout Session is open |
| expired / failed | `cancelled` / `failed`: the Session expired or the async payment failed (the merch expiry path) |
| issued | `completed` (`payment_complete()` after Events issues the passes; nothing to ship, so it skips `processing`). `processing` also counts as issued. |
| refund_pending | issued, with `_extrachill_ticket_refund_pending`: passes revoked, Stripe refund not yet succeeded |
| refunded | `refunded`, with a WooCommerce refund record |
| disputed | `on-hold` with `_extrachill_dispute` (the #1503 flag) |

Refunds are recorded with `wc_create_refund()` (`refund_payment` false, so
Stripe isn't called again), tagged with the Stripe refund or dispute ID for
idempotency. The order goes to `refunded` once nothing is left to refund.

**Kept apart from merch.** Ticket orders have nothing to ship:

- No WooCommerce emails (`woocommerce_email_enabled_*` returns false for the
  admin new/cancelled/failed order emails and every customer order email).
  Events emails passes, refunds and dispute notices.
- No stock changes (`woocommerce_can_reduce_order_stock`).
- Not in the seller's Orders tab, the Needs Fulfillment count, or the shipping
  label list: those queries add a "kind is not ticket" meta clause. The merch
  tools (status changes, labels, merch refunds, seller order emails) refuse a
  ticket order because `extrachill_shop_order_has_seller()` is false for it.
- Not in My Account → Orders; they're under My Account → Tickets.

**Reads.** My Account → Tickets and `extrachill/list-my-ticket-orders` query
orders by `customer_id` and kind (issued, refunded and on-hold). Host refunds
query by `_extrachill_ticket_event` and status (`processing`, `completed`).
Webhooks find the order from the Session's `order_id` (checked against the
stored account) or by PaymentIntent and account. None of them scan.

## Deployment

- Configure the encrypted **Connect Webhook Secret** beside the existing platform
  webhook secret. Subscribe a connected-account endpoint at
  `/wp-json/extrachill/v1/shop/stripe-webhook` to the events listed on Network
  Admin → Payments. Ticket Sessions use `checkout.session.completed` and
  `checkout.session.async_payment_succeeded` (issue), `checkout.session.expired`
  and `checkout.session.async_payment_failed` (cancel or fail the pending ticket
  order; Events has recorded nothing yet), `charge.refunded`, `charge.dispute.created` and `charge.dispute.closed`.
- Configure `EXTRACHILL_SHOP_TICKET_SERVICE_ASSERTION_KEYS` (key-ID map) and
  `EXTRACHILL_SHOP_TICKET_SERVICE_ASSERTION_ACTIVE_KEY_ID`. Matching Events target
  grants must allow service `extrachill.events.tickets`, scope
  `extrachill/events:tickets`, and the exact POST routes for issue-ticket-passes,
  get-ticket-order-status, revoke-ticket-passes, restore-ticket-passes,
  record-checkout-ticket-sales, notify-commerce-seller, and
  resend-ticket-pass-emails. These are separate from
  priority-boost keys. Without Events and its matching grants, issuance fails
  and webhook delivery must be retried.
- Pilot seller: Lo-Fi Brewing. Ensure its canonical venue binding points to a
  charges-enabled connected account. Exercise test-mode checkout and webhook
  delivery before enabling a paid offer.

**Inventory holds.** Events counts only issued passes against capacity, so
Shop holds the tickets of every unfinished checkout. A pending ticket order
holds its quantity until `_extrachill_ticket_hold_until` (the Session's
`expires_at`, 30 minutes), and for as long as it is paid but not yet issued.
Checkout refuses with `tickets_held` (409) when Events' `remaining` minus the
held tickets can't cover the request. After the order is saved, the holds are
counted again with it included; if they exceed `remaining`, the order is
deleted before any Session exists. Two checkouts racing for the last tickets
then see each other's holds, so at worst both step back and neither oversells.
Shows without a capacity (`remaining` null) skip the hold count.

The ticket order binds the seller and connected account before the Session
exists. If Stripe refuses the Session, the pending order is deleted. Events
issuance uses the session ID as both idempotency key and order reference.
Sold-out payments are refunded on the stored account with application fees
returned; the reason is stored in `_extrachill_ticket_refund_reason`. Each Stripe
refund attempt gets its own idempotency key (`ticket-refund-<session>-<attempt>`,
counted in `_extrachill_ticket_refund_attempts`): Stripe keeps a refused request's
result under its key for 24 hours, so a retry under the old key would only replay
the refusal. A full refund can't be issued twice either way; Stripe refuses a
second refund of a refunded charge. Merch refunds do the same with
`_extrachill_refund_attempts`. Webhook failures return errors
for Stripe retries; the order is marked paid only after Events confirms issuance.

## Reconciliation: missed webhooks and undelivered pass emails

`inc/core/ticket-reconciliation.php` schedules a recurring Action Scheduler
job (`extrachill_shop_reconcile_ticket_orders`, group
`extrachill-shop-tickets`, every 15 minutes) that runs two passes of up to 50
orders each:

- **Missed payment webhooks.** Ticket orders still `pending` more than 10
  minutes and less than 7 days after checkout (Stripe retries a webhook for 3
  days) are checked against their Checkout Session on the stored connected
  account. A paid Session goes through `extrachill_shop_fulfil_ticket_session()`,
  the same idempotent path the webhook uses (store the PaymentIntent, record
  the sale, issue passes keyed by the Session ID, complete the order; refund a
  sold-out show). An expired Session cancels the order. An open Session, or a
  delayed payment still in flight, is left for its webhook. A Stripe or Events
  failure leaves the order `pending` for the next run. A reconciled order gets
  `_extrachill_ticket_reconciled` (the Session ID) and an order note; a webhook
  that arrives later is a no-op.
- **Undelivered pass emails.** `issue-ticket-passes` reports `emails_failed`,
  the pass emails Events could not queue. The order is still issued (the
  passes exist and show under My Account → Tickets) and is flagged with
  `_extrachill_ticket_pass_email_pending` = `1`. The job calls Events'
  `resend-ticket-pass-emails` with the order reference and billing email;
  Events emails each active pass again (revoked and checked-in passes are
  skipped). When nothing fails the flag is cleared with an order note. Each
  failure counts in `_extrachill_ticket_pass_email_attempts`; after 8 the flag
  becomes `exhausted` and an order note asks an operator to send the passes.
  A won dispute whose restore emailed passes clears the flag too.

## Refund ordering deviation

Seller refunds authorize against the stored seller and consult Events status.
After that check, passes are revoked **before** the Stripe refund: Events must
atomically refuse revocation if check-in has occurred. Refunding first, as in the
issue's proposed ordering, would permit a check-in race that refunds a redeemed
pass. A refund is never issued for an order with a checked-in pass. A failed Stripe call leaves `refund_pending`; retry the same ability to
complete it (passes stay revoked). This state requires operator attention if
Stripe continues failing. This is not a distributed transaction.

Refund webhooks also revoke passes (including partial refunds, conservatively).
A refund already initiated externally after check-in cannot be undone: log the
Events `ticket_already_redeemed` response and acknowledge the webhook. Payment
webhooks that arrive before the completion/index write return a retryable error.

## Disputes

`charge.dispute.created` revokes the order's passes in Events with reason
`dispute` (Events stores it per pass as `revoke_reason`, and emails the seller),
puts the order `on-hold` with the `_extrachill_dispute` flag (remembering the
prior status), and blocks refunds: Stripe refuses refunds on a disputed charge. A buyer who already checked in keeps the check-in. A replay, or
a `created` for a dispute that has already closed, is a no-op.

`charge.dispute.closed` records `_extrachill_dispute_outcome` (`<dispute>:won` or
`<dispute>:lost`) on the order, adds an order note, and emails the seller
through `notify-commerce-seller`:

- **Won** (`won`, or `warning_closed` for an inquiry): Shop calls
  `extrachill/restore-ticket-passes` with reason `dispute` and the order's
  `buyer_email`. Events reactivates only the passes that the dispute revoked,
  keeping their codes so the buyer's existing passes scan again, and emails each
  restored pass to the buyer. Passes revoked for a refund or removed by a host
  stay revoked. Capacity isn't re-checked. The order goes back to its prior
  status (`completed`), so seller refunds and the show-wide refund work again.
- **Lost**: the passes stay revoked. The returned amount is recorded as a
  WooCommerce refund tagged with the dispute ID; a full loss leaves the order
  `refunded`, a partial one leaves it `on-hold` and still unrefundable. It is
  also recorded with
  `record-checkout-ticket-sales` like a refund, keyed by the dispute ID, with
  `fees_minor` 0 (the platform fee isn't returned on a lost dispute) and all
  tickets reversed when the full amount went back.

A failed Events call returns an error so Stripe retries before anything is
recorded. Replays are no-ops, and the restore itself is idempotent, so the buyer
is emailed once. Only orders still on hold for the dispute are restored or recorded.

Tests: `phpunit -c plugins/extrachill-shop/phpunit.xml.dist` from workspace root.
Stripe and the sibling Events contracts are mocked; live Connect configuration
and Events target-grant deployment still require test-mode integration testing.
