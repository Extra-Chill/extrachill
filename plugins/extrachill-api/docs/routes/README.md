# REST route reference

Paths in this reference use the `/wp-json/extrachill/v1` namespace. Route-level admission and ability-level authorization are separate checks. `EDITABLE` registrations accept POST, PUT and PATCH.

## Route index

### Events

- [Calendar, filters, geocoding and venues](events/calendar-discovery.md)
- [Guest digest signup and ticket refunds](events/guest-digest-and-refunds.md)
- [Internal attachment handoff and delivery](events/internal-attachment-callbacks.md)
- [Booking transport](events/booking-transport.md)
- [Booking availability and ticket redirect](events/availability-and-ticket-redirect.md)
- [Event source moderation](events/event-source.md)
- [Event submissions](events/event-submissions.md)
- [Guest RSVP](events/guest-rsvp.md)
- [Guest list pass](events/guest-list-pass.md)
- [Digest unsubscribe](events/local-scene-digest-unsubscribe.md)
- [Ticket checkout](events/ticket-checkout.md)
- [Upcoming counts](events/upcoming-counts.md)
- [Partner events (keyed venue feed)](partner/events.md)

### Community and accounts

- [Forums, stats, taxonomy counts and notifications](community/discovery-and-notifications.md)
- [Topics and replies](community/topics-and-replies.md)
- [Topic and reply editors](community/editors.md)
- [Drafts](community/drafts.md)
- [Upvotes](community/upvote.md)
- [Current-user profile, settings, email, password, subscriptions and artist access](users/account.md)
- [Concert tracking](concert-tracking/tracking.md)
- [Concert import](concert-import/import.md)
- [Comment editor](comments/editor.md)
- [Link Page editor configuration](link-pages/editor-configuration.md)

### Content and operations

- [Content-block generators and image voting](content-blocks/generators-and-image-voting.md)
- [Photo contest](content-blocks/photo-contest.md)
- [Analytics reports](analytics/reports.md)
- [Bridge impressions](analytics/impression.md)
- [Apple authentication](auth/apple.md)
- [Newsletter management](newsletter/management.md)
- [Admin artist relationships](admin/artist-relationships.md)
- [Giveaway execution and scheduling](giveaway/giveaway.md)
- [Instagram media resolution](instagram/resolve.md)

Other existing per-route references are organized in the sibling segment directories (artists, auth, blog, config, contact, docs, media, newsletter, SEO, shop, tools, users and wire).

## Ability-backed route contracts

Every REST callback that executes a WordPress ability is an adapter. Its route arguments must remain aligned with the backing ability's `input_schema`.

## Core primitive

Use `rest_get_endpoint_args_for_schema()` when projecting an ability schema for comparison. WordPress core already owns JSON Schema keyword projection, REST validation, and ability input validation. Core does not compare independently registered routes with abilities and does not model transport-only fields, so `extrachill_api_rest_ability_schema_findings()` covers only that gap.

## Parity rules

- Compare the exact HTTP method set declared by the adapter contract.
- Compare all ability properties and route arguments in both directions.
- Compare required fields, types, enums, numeric/string/array bounds, nested object and item schemas, defaults, and `additionalProperties`.
- Ignore descriptions and REST callback implementation details because they do not change accepted input.
- Treat a missing ability or malformed object schema as a finding, never as an empty schema.

## Exceptions

Deliberate differences must be supplied to the checker as `ability_only`, `transport_only`, or dotted `constraints` entries. Every exception value must be a non-empty reason. Typical legitimate cases are an authenticated user ID resolved server-side, a path parameter translated to a differently named ability field, or anti-abuse metadata consumed before ability execution.

Do not add broad plugin or product allowlists. Exceptions belong to one route/ability contract and one concrete field or constraint.

## Discovery and audit

`extrachill_api_rest_ability_adapter_manifest()` inspects only registered `extrachill/v1` callbacks defined under this repository's `inc/routes/` directory. A literal ability name in the executing callback is the binding convention. A callback that resolves a dynamic constant must declare `_extrachill_abilities` in its endpoint definition.

`extrachill_api_rest_ability_adapter_audit()` compares every discovered adapter against the active ability registry and returns structured findings. Site-scoped abilities are reported as absent when audited on a site where their owner is inactive; run the audit in the route's affinity context when deciding whether absence is a defect.

Use `extrachill_api_rest_ability_adapter_contract` to attach documented field-level exceptions to one discovered contract. The filter must not suppress an entire plugin, product, or namespace.
