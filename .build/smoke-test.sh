#!/usr/bin/env bash
# Runs the PHAR once per feature that depends on how it was built: the version, iconv and the bundled OCR.
# Usage: .build/smoke-test.sh <expected version> <tests/files as the PHP command sees it> <PHAR path> <PHP command...>
set -euo pipefail

expected=$1
files=$2
phar=$3
shift 3

version=$("$@" "$phar" --version)
if [ "$version" != "$expected" ]; then
  echo "--version printed '$version', expected '$expected'" >&2
  exit 1
fi

vtt=$("$@" "$phar" convert "$files/cli/latin1.srt" --encoding Windows-1252 --to vtt --output -)
if ! grep -qx 'Café au lait.' <<< "$vtt"; then
  printf 'convert printed:\n%s\n' "$vtt" >&2
  exit 1
fi

ocr=$("$@" "$phar" convert "$files/pgs/text_1080p.sup" --ocr --to srt --output -)
if [[ $ocr != *Bergen* ]]; then
  printf 'convert --ocr printed:\n%s\n' "$ocr" >&2
  exit 1
fi

echo "smoke test passed: $version"
