#!/usr/bin/env bash
# APKをビルドして、版番号つきのファイル名（例: meishi_app-v0.1.1.apk）で dist/ に置く。
set -euo pipefail
cd "$(dirname "$0")"
ver=$(grep '^version:' pubspec.yaml | sed 's/version: *//; s/+.*//')
flutter build apk --release --target-platform android-arm64 "$@"
mkdir -p dist
cp build/app/outputs/flutter-apk/app-release.apk "dist/meishi_app-v${ver}.apk"
echo "dist/meishi_app-v${ver}.apk"
