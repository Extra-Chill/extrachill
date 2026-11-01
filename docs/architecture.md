# Cross-component architecture

This is a navigation guide, not a second source of component contracts. Use the [documentation map](README.md) for all component references.

## Network and ownership

Extra Chill is a WordPress multisite network with a shared theme, network-activated infrastructure and site-owned feature plugins. The authoritative [component activation map](../MONOREPO.md#layout-and-where-each-component-runs) shows what runs where; the [network rig](../rigs/extrachill-network/components.json) supplies that map and boots this checkout for journeys.

[`extrachill-network`](../plugins/extrachill-network/README.md) owns network administration and shared configuration. Use its [blog ID helpers](../plugins/extrachill-network/docs/blog-id-helpers.md) for site identity and its [cross-site routing contract](../plugins/extrachill-network/docs/cross-site-routing.md) for route/ability ownership and dispatch. Shared identity and authentication belong to [`extrachill-users`](../plugins/extrachill-users/docs/README.md), not to individual site plugins.

## Domain logic and access surfaces

Feature plugins own their domain logic and register abilities. Access surfaces expose those capabilities without creating a separate business-logic layer:

- [`extrachill-api`](../plugins/extrachill-api/README.md) centralizes custom REST routes under `extrachill/v1` for web, mobile and other clients; its [route reference](../plugins/extrachill-api/docs/routes/) describes individual transports.
- [`extrachill-cli`](../plugins/extrachill-cli/README.md#architecture) is the sibling `wp extrachill` command surface, delegating to feature abilities and query functions. For site-owned abilities, use the owning site's `--url` bootstrap as described in its [owner-context guidance](../plugins/extrachill-cli/README.md#multisite-owner-context).
- [`extrachill-mcp`](../plugins/extrachill-mcp/README.md) exposes ability search and execution to authenticated AI clients. The underlying abilities retain their own authorization checks; MCP access does not grant blanket domain permissions.

For cross-site calls, follow the network routing contract rather than loading another site's feature plugin into the current request.

## Presentation and shared packages

The [network theme](../themes/extrachill/docs/README.md) provides shared presentation, routing, hooks and assets. Feature plugins integrate through its [integration patterns](../themes/extrachill/docs/integration-patterns.md) and [data contracts](../themes/extrachill/docs/data-contracts.md). Shared build-time packages live under [`packages/`](../packages/); the monorepo activation map lists their package names.

The [documentation publishing pipeline](README.md#how-help-reaches-docsextrachillcom) is a separate content surface: component-owned help ships with deploys and is published by the docs component, while developer references stay in Git.

## CI, deploys and releases

The [monorepo guide](../MONOREPO.md#ci) owns the CI model: changed components run their own Homeboy lint/tests, site infrastructure has separate checks, and network journeys boot the checkout. Its [deploy model](../MONOREPO.md#deploy-model) describes commit-based deployment from `main` and rollback by deploying an older commit. [Component releases](../MONOREPO.md#releases-and-changelogs) record versions and publish artifacts independently of deploys; changelogs remain release-owned.

For server configuration, start at the [nginx guide](nginx/README.md), including its Cloudflare and canonical routing references.
