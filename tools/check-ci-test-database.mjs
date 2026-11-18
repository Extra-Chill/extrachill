#!/usr/bin/env node
// Fail when a component's homeboy.json asks WP Codebox for an external MySQL
// service with secret_env. The reusable homeboy-action ci.yml never maps
// repository secrets such as WP_CODEBOX_DB_* into the job environment, so
// `review test` stops before PHPUnit starts and the differential gate reports
// the job as "inconclusive" instead of failing it (#454, #473). Use the
// disposable Docker database instead:
//   "wp_codebox_database_service": { "provider": "docker", "engine": "mysql" }
// Run from any directory: node tools/check-ci-test-database.mjs
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

// Components still on the external service because their suites fail once they
// actually run (#1622). Each one is reported as a CI warning. Remove the entry
// together with the switch to the Docker provider.
const PENDING_EXTERNAL_DB = {
  'plugins/extrachill-events/homeboy.json': '#1622',
};

const errors = [];
const warnings = [];
let checked = 0;
for (const group of ['plugins', 'themes', 'packages']) {
  for (const entry of readdirSync(path.join(root, group), { withFileTypes: true })) {
    if (!entry.isDirectory()) continue;
    const file = `${group}/${entry.name}/homeboy.json`;
    if (!existsSync(path.join(root, file))) continue;
    checked++;
    const config = JSON.parse(readFileSync(path.join(root, file), 'utf8'));
    for (const [extension, extensionConfig] of Object.entries(config.extensions ?? {})) {
      const service = extensionConfig?.settings?.wp_codebox_database_service;
      if (!service) continue;
      if (service.secret_env || service.provider === 'external') {
        if (PENDING_EXTERNAL_DB[file]) {
          warnings.push(
            `${file}: PHPUnit does not run in monorepo CI (external WP Codebox database service; tracked in ` +
              `${PENDING_EXTERNAL_DB[file]}).`,
          );
          continue;
        }
        errors.push(
          `${file}: extensions.${extension}.settings.wp_codebox_database_service uses provider ` +
            `"${service.provider}"${service.secret_env ? ' with secret_env' : ''}. Monorepo CI cannot ` +
            'supply those secrets, so PHPUnit never runs. Use {"provider": "docker", "engine": "mysql"}.',
        );
      }
    }
  }
}
for (const [file, issue] of Object.entries(PENDING_EXTERNAL_DB)) {
  if (!warnings.some((warning) => warning.startsWith(`${file}:`))) {
    errors.push(`${file}: no longer uses an external database service; remove it from PENDING_EXTERNAL_DB (${issue}).`);
  }
}
for (const warning of warnings) {
  console.log(process.env.GITHUB_ACTIONS ? `::warning::${warning}` : `warning: ${warning}`);
}
if (errors.length) {
  console.error(errors.join('\n'));
  process.exitCode = 1;
} else {
  console.log(`CI test database config OK (${checked} components).`);
}
