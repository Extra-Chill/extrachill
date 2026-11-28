# Breadcrumb dropdown experiment

`network-dropdown-everywhere` ships inactive (definition version 3). No lifecycle
options are written on deployment. Existing homepage/root archive dropdowns are
unchanged and excluded from the experiment.

Operators can inspect and activate it using the existing CLI:

```sh
wp extrachill experiments list
wp extrachill experiments state network-dropdown-everywhere active
wp extrachill experiments report network-dropdown-everywhere --outcome-event-types=cta_click,network_click
```

Cached markup contains both arms and never a server-side visitor assignment.
A late `extrachill_breadcrumbs_root` filter splits the root into the shared
prefix (`Extra Chill`) and, when present, the trailing link to the current
site's home URL (`› Community`). It renders:

- the measured wrapper (experiment attributes) around the shared prefix,
  visible in both arms;
- the control segment: the existing site crumb, visible by default;
- the treatment segment (` › [Community ▾]`), hidden and inert, emitted via
  the theme's `extrachill_breadcrumbs_after_root` action because post-HTML
  sanitization of the root strips button and SVG markup.

After assignment the presentation listener shows exactly one segment, so
treatment reads `Extra Chill › [Community ▾] › …` with no duplicate label and
control reads exactly today's breadcrumb. The main site root has no site crumb,
so treatment appends `› Blog ▾` there. The shared IntersectionObserver measures
the same always-visible wrapper in both arms, so exposure fires under an
identical condition. Site plugins are not special-cased; the site crumb is
recognized only by its href matching `home_url()`.

History: v1 never rendered treatment. v2 appended a duplicate site crumb on
sites whose root already names the site, and only exposed treatment (the hidden
control had no box). Neither dataset may be mixed with v3. Activate v3 only
after experiment bot filtering (#1778) lands.

Eligibility is checked against the same exact trail predicate as the existing
dropdown. Site labels come from the filtered network dropdown list, matched to
the current site's canonical URL.

Validation: `composer test` includes standalone PHP definition/rendering/list
checks and the treatment/control JavaScript test. A live WP-CLI invocation also
requires a configured WordPress/Studio site; the isolated workspace has none.
