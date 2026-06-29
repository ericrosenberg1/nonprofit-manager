#!/usr/bin/env bash
# Build the distributable Nonprofit Manager (free) zip from git HEAD.
# Deterministic: git archive ships only committed files; dev paths are stripped
# via .gitattributes export-ignore.
set -euo pipefail
SLUG="nonprofit-manager"
rm -rf dist && mkdir -p "dist/${SLUG}"
git archive HEAD | tar -x -C "dist/${SLUG}"
( cd dist && zip -rqX "${SLUG}.zip" "${SLUG}" )
rm -rf "dist/${SLUG}"
echo "Built dist/${SLUG}.zip"
