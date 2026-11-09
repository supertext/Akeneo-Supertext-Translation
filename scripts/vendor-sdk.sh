#!/bin/sh
# Copies the PHP Supertext SDK from its GitHub repository into lib/supertext-sdk. The SDK isn't
# on Packagist yet, so the bundle ships this copy (namespace Supertext\Sdk, unchanged; mapped in
# composer.json). autoload.php is the SDK's own Composer-free autoloader (tests, PHPStan).
#
#   scripts/vendor-sdk.sh [commit-or-branch]   (default: main)
#   SDK_DIR=/path/to/clone scripts/vendor-sdk.sh   (use a local clone as it is)
set -eu
REF="${1:-main}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -n "${SDK_DIR:-}" ]; then
    SRC="$SDK_DIR"
else
    git clone --quiet https://github.com/Supertext/Supertext-API-SDK-and-Guide "$TMP/sdk"
    git -C "$TMP/sdk" -c advice.detachedHead=false checkout --quiet "$REF"
    SRC="$TMP/sdk"
fi
COMMIT="$(git -C "$SRC" rev-parse HEAD)"

DEST="$ROOT/lib/supertext-sdk"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$SRC/sdk/php/src" "$DEST/src"
cp "$SRC/sdk/php/autoload.php" "$DEST/autoload.php"
cp "$SRC/LICENSE" "$DEST/LICENSE"
printf '%s\n' "$COMMIT" > "$DEST/COMMIT"
VERSION="$(sed -n "s/.*SDK_VERSION = '\\([^']*\\)'.*/\\1/p" "$DEST/src/Supertext.php")"
printf '%s\n' "$VERSION" > "$DEST/VERSION"
cat > "$DEST/README.md" <<EOF
# Supertext SDK (bundled copy)

PHP part of the [Supertext SDK](https://github.com/Supertext/Supertext-API-SDK-and-Guide),
version $VERSION, commit \`$COMMIT\`, namespace \`Supertext\\Sdk\` (unchanged).

Don't edit these files: fix the SDK in its own repository, then run \`scripts/vendor-sdk.sh\`.
EOF
echo "lib/supertext-sdk: Supertext SDK (PHP) from commit $COMMIT"
