# Link Page preview ability

`extrachill/preview-link-page` is read-only and uses the same artist permission
callback as `save-link-page-styles`. Supply `artist_id` and optionally
`viewport: {width: 390, height: 844}`. It only resolves that artist's page.

The response includes `public_url`, `viewport`, `resolved_styles`,
`contrast_report`, and `screenshot`. Styles come from the canonical read
operation, including persistence defaults, forced card background and derived
hover text, with the public artist font processor applied when loaded.

Contrast uses WCAG sRGB luminance, alpha compositing, and an unrounded 4.5
threshold. It checks button text, page text on background/card, and icons on
background/card. These are color-based estimates, not browser-computed pixel
measurements. Images, gradients, and unsupported CSS colors produce `unknown`
with null ratio/flag rather than a false pass. An opaque button can still be
measured against its text when the surrounding background is unknown.

## Screenshot gap

Inspected Automattic/wp-codebox `main`:

- `packages/wordpress-plugin/src/class-wp-codebox-browser-ability-descriptors.php`
- `packages/wordpress-plugin/src/class-wp-codebox-browser-viewport-replay.php`

`wp-codebox/replay-browser-viewport` requires a Playground ZIP (`archive_base64`)
and a safe relative `route`. It imports that archive into Playground; it does
not accept a live public URL. It returns PNG base64, not a public artifact URL.
We do not reconstruct/export a production site or introduce a second browser
stack. The response explicitly returns screenshot status `unavailable`, URL
null, and the reason. Upstream live capture request:
https://github.com/Automattic/wp-codebox/issues/2585

## Standalone test

From the monorepo root:

```sh
php plugins/extrachill-artist-platform/tests/support/preview-link-page-smoke.php
```

This runs the real registration, handler, permission-enforcing read handler,
and canonical persistence/default derivation using minimal WordPress stubs.
