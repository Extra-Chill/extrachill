# Breadcrumb dropdown experiment

`network-dropdown-everywhere` ships inactive (definition version 1). No lifecycle
options are written on deployment. Existing homepage/root archive dropdowns are
unchanged and excluded from the experiment.

Operators can inspect and activate it using the existing CLI:

```sh
wp extrachill experiments list
wp extrachill experiments state network-dropdown-everywhere active
wp extrachill experiments report network-dropdown-everywhere --outcome-event-types=cta_click,network_click
```

Cached markup contains a hidden, inert control candidate, never a server-side
visitor assignment. The shared assignment runtime resolves the variant; the
presentation listener reveals only treatment. The shared IntersectionObserver
then measures the revealed wrapper. Hidden control wrappers have no rendered
box and cannot intersect, so do not generate exposures.

The theme's `extrachill_breadcrumbs_after_root` action allows the component to
render its own escaped interactive markup without post-HTML sanitization
stripping button or SVG markup. Eligibility is checked against the same exact
trail predicate as the existing dropdown. Site labels come from the filtered
network dropdown list, matched to the current site's canonical URL.

Validation: `composer test` includes standalone PHP definition/rendering/list
checks and the treatment/control JavaScript test. A live WP-CLI invocation also
requires a configured WordPress/Studio site; the isolated workspace has none.
