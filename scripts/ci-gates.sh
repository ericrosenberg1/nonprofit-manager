#!/usr/bin/env bash
# Local gates. Replaces .github/workflows/ci.yml (GitHub Actions retired
# 2026-09-03). Run by .githooks/pre-push, or by hand: scripts/ci-gates.sh
set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> php -l (syntax)"
find . -name "*.php" -not -path "./vendor/*" -not -path "./node_modules/*" -print0 | xargs -0 -n1 php -l

echo "==> release lockstep"
"$(dirname "$0")/check-lockstep.sh"

echo "==> gates passed"
