# Chris Gardner Persona Contract

Extra Chill Users owns the canonical network identity and access contract for the Chris Gardner reference persona. Gardner represents a nontechnical power user who knows the operation well, notices small inconsistencies, expects direct outcomes, and may reload, backtrack, double-submit, or change his mind when state is unclear.

The machine-readable contract is `tests/personas/gardner.v1.json`. Its stable persona ID is `extra-chill-users/chris-gardner`, and its current contract version is `1.0.0`. The fixture uses an `example.invalid` email and a visibly test-only display name; it is not Chris Gardner's production account or contact record.

## Users Ownership

The contract records only concerns owned by Extra Chill Users:

- Canonical identity and the `extra_chill_team` team role.
- Membership with that role on every active network site, matching the network-wide team grant behavior.
- Baseline WordPress and Extra Chill capabilities supplied by the team role.
- The explicit per-user `manage_brand_socials` grant on every active network site.
- Reusable behavioral traits and stable oracle vocabulary.
- Safety constraints for test recipes.

The fixture and its validation live under `tests/`, which `.buildignore` excludes from production packages. There is no runtime persona endpoint, runner, or orchestration layer.

## Consumer Boundary

Product repositories own their actions, setup, assertions, and capability-gap expectations. Broad examples include Studio social operations; Artist Platform profile, roster, ownership, link, and commerce journeys; Events booking, promoter, My Shows, event, venue, location, and artist-archive journeys; Community journeys; and future product scenarios.

Those scenarios may use the stable oracle IDs from this contract, but product actions do not belong in Extra Chill Users. Extra Chill Network remains product-agnostic and does not need to know which products consume the persona.

## Pinning A Recipe

A product-owned recipe should pin both identity and version in its own metadata, then load the matching fixture from Extra Chill Users during test setup:

```json
{
	"persona_contract": {
		"id": "extra-chill-users/chris-gardner",
		"version": "1.0.0"
	}
}
```

Consumers must reject an unavailable or incompatible version rather than silently using the latest contract. They should reference oracle IDs such as `safe-retry` and `reload-persistence`; they should not copy the oracle definitions or add product behavior to this fixture.

Contract changes that alter required identity, access, traits, or oracle semantics require a new semantic contract version and a correspondingly named fixture. Compatible documentation clarifications do not require a version change.

## Safety

Persona recipes must never contain or request production credentials, tokens, personal contact data, or live external writes. Product scenarios must use isolated test identities, fake destinations, and stubbed or sandboxed integrations.
