# Ability-backed route contracts

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
