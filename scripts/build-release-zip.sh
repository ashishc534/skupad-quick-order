#!/usr/bin/env bash
# Build dist/pryviko-quick-order-1.1.0.zip with top-level pryviko-quick-order/ including vendor.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="1.1.0"
NAME="pryviko-quick-order"
DIST="${ROOT}/dist"
STAGE="${DIST}/.stage-${NAME}"
ZIP="${DIST}/${NAME}-${VERSION}.zip"

mkdir -p "${DIST}"
rm -rf "${STAGE}" "${ZIP}"
mkdir -p "${STAGE}/${NAME}"

# Ensure Composer vendor is present for the release zip.
if [[ ! -f "${ROOT}/vendor/autoload.php" ]]; then
  (cd "${ROOT}" && composer install --no-dev --optimize-autoloader)
fi

rsync -a \
  --exclude '.git' \
  --exclude '.github' \
  --exclude '.wordpress-org' \
  --exclude 'tests' \
  --exclude 'dist' \
  --exclude 'scripts' \
  --exclude '.phpunit*' \
  --exclude '.DS_Store' \
  --exclude 'node_modules' \
  --exclude 'test_unit.py' \
  --exclude 'phpunit.xml.dist' \
  --exclude 'LISTING.md' \
  "${ROOT}/" "${STAGE}/${NAME}/"

# Keep vendor inside the zip even though .gitignore excludes it from git.
if [[ ! -d "${STAGE}/${NAME}/vendor" ]]; then
  echo "ERROR: vendor/ missing from stage" >&2
  exit 1
fi

(
  cd "${STAGE}"
  zip -r "${ZIP}" "${NAME}" -x '*.DS_Store*'
)

rm -rf "${STAGE}"
echo "Built ${ZIP}"
ls -lh "${ZIP}"
