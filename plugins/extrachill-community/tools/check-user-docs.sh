#!/bin/sh
# End-user docs gate: at least 5 docs/user articles, each titled, passing the
# Extra Chill writing-rules voice check (extrachill-docs scripts/check-user-docs-voice.php, pinned).
set -e
n=$(ls docs/user/*.md 2>/dev/null | wc -l)
[ "$n" -ge "${MIN_USER_DOCS:-5}" ] || { echo "expected at least ${MIN_USER_DOCS:-5} docs/user/*.md, found $n"; exit 1; }
curl --fail --silent --show-error https://raw.githubusercontent.com/Extra-Chill/extrachill-docs/b6de069da1d2aff7dd3ebf00d86a85bdd6f3e9f0/scripts/check-user-docs-voice.php --output /tmp/ec-user-docs-voice.php
php /tmp/ec-user-docs-voice.php docs/user
for f in docs/user/*.md; do head -1 "$f" | grep -q '^# ' || { echo "$f must start with a '# Title' heading"; exit 1; }; done
echo "community docs gate passed ($n articles)"
