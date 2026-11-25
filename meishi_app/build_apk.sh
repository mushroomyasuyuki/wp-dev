#!/usr/bin/env bash
# APKをビルドして、版番号つきのファイル名（例: meishi_app-v0.1.1.apk）で dist/ に置く。
set -euo pipefail
cd "$(dirname "$0")"
ver=$(grep '^version:' pubspec.yaml | sed 's/version: *//; s/+.*//')
# AAB=1 ./build_apk.sh でGoogle Play提出用のAAB（android/key.properties の鍵で署名）を作る
if [ "${AAB:-}" = "1" ]; then
  [ -f android/key.properties ] || { echo "android/key.properties がありません。先に tools/make_keystore.sh を実行してください。"; exit 1; }
  flutter build appbundle --release "$@"
  mkdir -p dist
  cp build/app/outputs/bundle/release/app-release.aab "dist/meishi_app-v${ver}${SUFFIX:-}.aab"
  echo "dist/meishi_app-v${ver}${SUFFIX:-}.aab"
  exit 0
fi
flutter build apk --release --target-platform android-arm64 "$@"
mkdir -p dist
cp build/app/outputs/flutter-apk/app-release.apk "dist/meishi_app-v${ver}${SUFFIX:-}.apk"
echo "dist/meishi_app-v${ver}${SUFFIX:-}.apk"
