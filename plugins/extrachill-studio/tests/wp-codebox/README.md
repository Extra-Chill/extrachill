# Studio WP Codebox Personas

## Smoke Coverage

`chris-gardner-persona.json` is the short Studio browser smoke recipe. It uses deterministic local REST fixtures to confirm basic tab visibility, media selection, review submission, comments, and analytics navigation. It does not claim durable social delivery coverage.

## Stateful Social Operator

`chris-gardner-social-operator.json` is the portable, stateful Studio social-operations scenario. It consumes canonical identity contract `extra-chill-users/chris-gardner` version `1.0.0`, pinned to `Extra-Chill/extrachill-users` commit `627533b541ebdedd7107d543edfef186c07cb48e`. Studio owns only the social-operation scenario and does not redefine Gardner's identity, oracle set, persona registry, or shared user substrate.

Real sandbox runtime:

- Data Machine `0.175.18`, SHA-256 `5402b37795dfe1a88b0b26044935b949fa518f5eeb271d72520226b29af3c3e6`
- Data Machine Socials `0.20.1`, SHA-256 `f6281174dc9486dea757e62971948a2f16683d639f1f8ccba76b8d613dd3e8bb`
- Extra Chill Analytics `0.36.3`, SHA-256 `fd2d22f02a9acc98200227a767936f73cbbe80a846ab5b868b3652890cd58a63` (registers production build dependencies)
- Extra Chill Network `2.9.0`, SHA-256 `6b2efcbb648fb0a15ac92e0ef83f9e791da115f5c6093ce5aef835cac05b911a` (required by Users)
- Extra Chill API `0.31.1`, SHA-256 `d1b6d24c5753997fbee336a1b2819802736a6ce8abe3474f13c0ce7972169d42` (required by Users)
- Extra Chill Users `0.41.5`, SHA-256 `ffc8925a01a4bb07a277def39559a0bd8f42f78832327e51acc7f0be236c3808`
- Mounted Studio PHP and production browser build
- WordPress REST, Core future-post cron, post/meta persistence, Abilities, Data Machine delegated jobs/retries, provider abilities, and SocialShareTracker

The recipe declares `WP_AGENT_RUNTIME=1` through Codebox `runtimeEnv`, then converts the disposable WordPress runtime to multisite before network-activating Network, API, and Users. The runtime declaration loads Data Machine's supported full operator surface before plugin bootstrap without manual ability registration.

Stubbed boundary:

- Only final outbound provider HTTP is intercepted by `chris-gardner-social-operator-provider-stub.php` through `pre_http_request`.
- The fixture removes only WordPress's named update-check callbacks and uses Core's ping-environment filter, so update and Pingomatic traffic never enters the provider-effect ledger.
- The stub records method, host, path, provider-call classification, and optional payload hashes. It never records tokens, Authorization headers, request bodies, or secrets.
- Unexpected external hosts, provider paths, and provider methods fail closed. No live social write can leave the sandbox.
- No replacement `datamachine/v1/socials/*` route is registered.

Build, validate, and replay:

```bash
npm run build
node --test tests/wp-codebox/chris-gardner-social-operator-contract.test.mjs
WP_CODEBOX_ALLOW_NETWORK_DOWNLOADS=1 WP_CODEBOX_ALLOWED_DOWNLOAD_HOSTS=github.com,release-assets.githubusercontent.com node /var/www/extrachill.com/wp-content/plugins/wp-codebox/vendor/wp-codebox-cli/packages/cli/dist/index.js recipe validate --recipe tests/wp-codebox/chris-gardner-social-operator.json --json
WP_CODEBOX_ALLOW_NETWORK_DOWNLOADS=1 WP_CODEBOX_ALLOWED_DOWNLOAD_HOSTS=github.com,release-assets.githubusercontent.com node /var/www/extrachill.com/wp-content/plugins/wp-codebox/vendor/wp-codebox-cli/packages/cli/dist/index.js recipe-run --recipe tests/wp-codebox/chris-gardner-social-operator.json --json
WP_CODEBOX_ALLOW_NETWORK_DOWNLOADS=1 WP_CODEBOX_ALLOWED_DOWNLOAD_HOSTS=github.com,release-assets.githubusercontent.com node /var/www/extrachill.com/wp-content/plugins/wp-codebox/vendor/wp-codebox-cli/packages/cli/dist/index.js adversarial run --recipe tests/wp-codebox/chris-gardner-social-operator.json --json
```

The deterministic workflow completes before the adaptive campaign. Capability gaps and confusion remain structured findings and do not become fake passes. Provider-call, transition (state-transition evidence), capability-gap, and oracle ledgers are required verified artifacts.
