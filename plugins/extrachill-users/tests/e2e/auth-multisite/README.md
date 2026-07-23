# Extra Chill Authentication Multisite Fuzz Campaign

This consumer-owned campaign composes the generic `wordpress-multisite-e2e`
Homeboy rig with real Extra Chill authentication components. It generates a
replayable case plan from `AUTH_FUZZ_SEED`, runs backend mutation invariants,
registers and onboards a user through the rendered block, verifies path-based
cross-site cookie continuity, captures evidence, and destroys the runtime.

```bash
AUTH_FUZZ_COMPONENTS_FILE=/absolute/path/components.json \
AUTH_FUZZ_WORDPRESS_VERSION=nightly \
AUTH_FUZZ_PHP_VERSION=8.4 \
AUTH_FUZZ_SEED=auth-campaign-001 \
AUTH_FUZZ_ARTIFACT_ROOT=/absolute/path/artifacts \
node tests/e2e/auth-multisite/run.mjs
```

The component manifest must point to clean Git checkouts at exact full commit
revisions. The Users checkout must have ignored release assets generated with
`npm run build`; mounted-byte SHA-256 provenance binds those assets to the run.

Turnstile and email use documented local/test seams. Google OAuth, real email,
mapped subdomains, TLS, and `.extrachill.com` cookie-domain behavior are outside
this localhost path-multisite proof and require deployed smoke coverage.
