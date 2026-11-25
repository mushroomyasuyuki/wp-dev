#!/usr/bin/env bash
# Google Play 提出用の「アップロード鍵」を作る。1回だけ実行する。
#   ./tools/make_keystore.sh
# できるもの:
#   ~/meishi-upload-key.jks     … 鍵ファイル（**失くさない・人に渡さない・Gitに入れない**）
#   android/key.properties      … ビルドが鍵を見つけるための設定（パスワードが入る。Gitには入れない）
# 必要なもの: JDK（keytool）。入っていなければ  sudo apt install default-jdk-headless
set -euo pipefail
cd "$(dirname "$0")/.."
KEY="$HOME/meishi-upload-key.jks"
if [ -e "$KEY" ]; then echo "すでに $KEY があります。上書きしないため中止しました。"; exit 1; fi
read -r -s -p "鍵のパスワード（6文字以上。忘れないこと）: " PW; echo
read -r -s -p "もう一度: " PW2; echo
[ "$PW" = "$PW2" ] || { echo "パスワードが一致しません"; exit 1; }
keytool -genkeypair -v -keystore "$KEY" -alias upload -keyalg RSA -keysize 2048 -validity 10000 \
  -storepass "$PW" -keypass "$PW" -dname "CN=Mush Office, O=Mush Office, C=JP"
cat > android/key.properties <<EOP
storePassword=$PW
keyPassword=$PW
keyAlias=upload
storeFile=$KEY
EOP
chmod 600 "$KEY" android/key.properties
echo "できました。$KEY を、パスワードと一緒に、安全な場所（別のPC・USBなど）へバックアップしてください。"
