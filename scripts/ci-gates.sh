#!/usr/bin/env bash
# Local gates. Replaces .github/workflows/ci.yml (GitHub Actions retired
# 2026-09-03). Run by .githooks/pre-push, or by hand: scripts/ci-gates.sh
set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> php -l (syntax)"
find . -name "*.php" -not -path "./vendor/*" -not -path "./node_modules/*" -print0 | xargs -0 -n1 php -l

echo "==> unit tests"
for t in tests/test-*.php; do
  [ -e "$t" ] || continue
  php "$t" > /tmp/npmp-free-gate.$$ 2>&1 || { cat /tmp/npmp-free-gate.$$; rm -f /tmp/npmp-free-gate.$$; echo "FAILED: $t"; exit 1; }
  tail -1 /tmp/npmp-free-gate.$$ | sed "s|^|  $(basename "$t"): |"
  rm -f /tmp/npmp-free-gate.$$
done

echo "==> release lockstep"
"$(dirname "$0")/check-lockstep.sh"

echo "==> gates passed"
