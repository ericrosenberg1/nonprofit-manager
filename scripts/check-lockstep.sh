#!/usr/bin/env bash
#
# Nonprofit Manager ships as one product across three repos and three release
# channels. Nothing used to check that they agreed, and they silently drifted:
# a version bump that skipped the licensing changelog erased 2026.09.9 from the
# notes customers read in their update screen.
#
# This is that check. Run by .githooks/pre-push in each repo, or by hand:
#   scripts/check-lockstep.sh
#
# A sibling repo that is not checked out is skipped with a note, never failed,
# so this stays usable on a machine that only has one of them.

set -uo pipefail

FREE_DIR="${FREE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
PRO_DIR="${PRO_DIR:-$(dirname "$FREE_DIR")/nonprofit-manager-pro}"
SITE_DIR="${SITE_DIR:-$FREE_DIR/nonprofit-manager-site}"

fail=0
note() { printf '  %s\n' "$*"; }
ok()   { printf '  ok    %s\n' "$*"; }
bad()  { printf '  FAIL  %s\n' "$*"; fail=1; }

echo "==> version lockstep"

free_ver=""
free_tag=""
if [ -f "$FREE_DIR/nonprofit-manager.php" ]; then
  free_ver=$(grep -m1 -E '^\s*\*\s*Version:' "$FREE_DIR/nonprofit-manager.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
  free_tag=$(grep -m1 -i 'Stable tag' "$FREE_DIR/readme.txt" | sed -E 's/.*[Ss]table tag:[[:space:]]*//' | tr -d '[:space:]')
  if [ "$free_ver" = "$free_tag" ]; then
    ok "free plugin header and readme Stable tag agree ($free_ver)"
  else
    bad "free header ($free_ver) != readme Stable tag ($free_tag)"
  fi
else
  note "skip: free plugin not found at $FREE_DIR"
fi

pro_ver=""
if [ -f "$PRO_DIR/nonprofit-manager-pro.php" ]; then
  pro_hdr=$(grep -m1 -E '^\s*\*\s*Version:' "$PRO_DIR/nonprofit-manager-pro.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
  pro_const=$(grep -m1 "define( 'NPMP_PRO_VERSION'" "$PRO_DIR/nonprofit-manager-pro.php" | sed -E "s/.*'NPMP_PRO_VERSION',[[:space:]]*'([^']+)'.*/\1/")
  pro_ver="$pro_hdr"
  if [ "$pro_hdr" = "$pro_const" ]; then
    ok "Pro header and NPMP_PRO_VERSION agree ($pro_ver)"
  else
    bad "Pro header ($pro_hdr) != NPMP_PRO_VERSION ($pro_const)"
  fi

  # Pro refuses to load against a free plugin older than this floor, so a floor
  # above the shipping free version would disable Pro on a correct install.
  floor=$(grep -m1 "define( 'NPMP_PRO_MIN_FREE_VERSION'" "$PRO_DIR/nonprofit-manager-pro.php" | sed -E "s/.*'NPMP_PRO_MIN_FREE_VERSION',[[:space:]]*'([^']+)'.*/\1/")
  if [ -n "$free_ver" ] && [ -n "$floor" ]; then
    if [ "$(printf '%s\n%s\n' "$floor" "$free_ver" | sort -V | head -1)" = "$floor" ]; then
      ok "Pro's minimum free version ($floor) is satisfied by free $free_ver"
    else
      bad "Pro requires free >= $floor but free is $free_ver, Pro would switch itself off"
    fi
  fi
else
  note "skip: Pro plugin not found at $PRO_DIR"
fi

if [ -n "$free_ver" ] && [ -n "$pro_ver" ]; then
  if [ "$free_ver" = "$pro_ver" ]; then
    ok "free and Pro are in lockstep ($free_ver)"
  else
    bad "free ($free_ver) and Pro ($pro_ver) are NOT in lockstep"
  fi
fi

VER_TS="$SITE_DIR/src/pages/api/license/version.ts"
if [ -f "$VER_TS" ]; then
  site_ver=$(grep -m1 "const CURRENT_VERSION" "$VER_TS" | sed -E "s/.*'([^']+)'.*/\1/")
  if [ -n "$pro_ver" ]; then
    if [ "$site_ver" = "$pro_ver" ]; then
      ok "licence server advertises the shipping Pro version ($site_ver)"
    else
      bad "licence server advertises $site_ver but Pro is $pro_ver"
    fi
  fi

  # The bug this script exists for: a bump with no matching changelog entry.
  if [ -n "$site_ver" ]; then
    if grep -q "<h4>$site_ver</h4>" "$VER_TS"; then
      ok "changelog has an entry for $site_ver"
    else
      bad "changelog has NO entry for $site_ver, customers see the previous release's notes"
    fi
  fi

  # Pro 2026.09.22+ installs an update only when the manifest carries a
  # sha256 and an Ed25519 signature from the release key over the version and
  # that sha256. An unsigned or mis-signed release would be refused by every
  # updated site, so check the pair against Pro's own SIGNING_KEY here.
  UPD="$PRO_DIR/includes/license/class-plugin-updater.php"
  site_sha=$(sed -n "s/^const CURRENT_ZIP_SHA256 = '\([^']*\)'.*/\1/p" "$VER_TS")
  site_sig=$(sed -n "s/^const CURRENT_ZIP_SIGNATURE = '\([^']*\)'.*/\1/p" "$VER_TS")
  pubkey=""
  [ -f "$UPD" ] && pubkey=$(sed -n "s/.*const SIGNING_KEY = '\([^']*\)'.*/\1/p" "$UPD" | head -1)
  if [ -n "$site_sig" ] || { [ -n "$pubkey" ] && [ "$(printf '%s\n%s\n' "2026.09.22" "$site_ver" | sort -V | head -1)" = "2026.09.22" ]; }; then
    if [ -z "$pubkey" ]; then
      note "skip: Pro updater not found, can't check the release signature"
    elif [ -z "$site_sig" ] || [ -z "$site_sha" ]; then
      bad "licence server advertises $site_ver with no CURRENT_ZIP_SHA256/CURRENT_ZIP_SIGNATURE, updated sites would refuse it (run release.sh --dry-run on the tag to get them)"
    elif php -r '
        $pk = base64_decode( $argv[1], true ); $sig = base64_decode( $argv[2], true );
        $msg = "nonprofit-manager-release-v1\nnonprofit-manager-pro\n" . $argv[3] . "\n" . $argv[4];
        exit( ( $pk && $sig && 32 === strlen( $pk ) && 64 === strlen( $sig ) && sodium_crypto_sign_verify_detached( $sig, $msg, $pk ) ) ? 0 : 1 );
      ' "$pubkey" "$site_sig" "$site_ver" "$site_sha"; then
      ok "release signature verifies for $site_ver"
    else
      bad "release signature for $site_ver does not verify against Pro's SIGNING_KEY"
    fi
  fi

  # An interpolated heading retitles the last entry on every bump.
  if grep -q '<h4>${CURRENT_VERSION}</h4>' "$VER_TS"; then
    bad 'changelog heading interpolates ${CURRENT_VERSION}, use a literal version'
  else
    ok "changelog headings are literal versions"
  fi
else
  note "skip: licence server not found at $SITE_DIR"
fi

if [ "$fail" -eq 0 ]; then
  echo "==> lockstep ok"
else
  echo "==> lockstep FAILED, fix the above before releasing"
fi
exit "$fail"
