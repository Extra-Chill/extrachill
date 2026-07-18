#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
artifact_root="${TMPDIR:-/tmp}/extrachill-blog-artist-dispatch-integration"

wp-codebox run \
  --mount "${repo_root}:/wordpress/wp-content/plugins/extrachill-blog" \
  --command wordpress.run-php \
  --arg "code-file=${repo_root}/tests/integration/artist-dispatch-rest.php" \
  --wp nightly \
  --artifacts "${artifact_root}" \
  --json
