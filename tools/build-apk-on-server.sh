#!/usr/bin/env bash
# Build + publish a SIGNED release APK on the app server (no GitHub secrets needed).
#
# One-time setup on the server (already done on bharatseo.site):
#   sudo apt-get install -y openjdk-17-jdk-headless unzip curl
#   mkdir -p ~/android-sdk/cmdline-tools && cd /tmp
#   curl -sLO https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip
#   unzip -q commandlinetools-linux-11076708_latest.zip -d ~/android-sdk/cmdline-tools
#   mv ~/android-sdk/cmdline-tools/cmdline-tools ~/android-sdk/cmdline-tools/latest
#   yes | ~/android-sdk/cmdline-tools/latest/bin/sdkmanager --licenses
#   ~/android-sdk/cmdline-tools/latest/bin/sdkmanager "platform-tools" "platforms;android-35" "build-tools;35.0.0"
#   mkdir -p ~/.bckeys && chmod 700 ~/.bckeys     # put release.jks + pw.txt here (chmod 600)
#
# Usage:  tools/build-apk-on-server.sh <versionCode>
# Example: tools/build-apk-on-server.sh 11
#
# The release keystore must stay OUTSIDE the web root and must never be committed.
set -euo pipefail

VCODE="${1:?usage: build-apk-on-server.sh <versionCode>   (must be higher than the current one)}"
SRC="${SRC:-$HOME/bcbuild/android}"
KEYS="${KEYS:-$HOME/.bckeys}"
WEBROOT="${WEBROOT:-/www/wwwroot/bc.bharatseo.site}"
DL="$WEBROOT/public/downloads"

export ANDROID_HOME="${ANDROID_HOME:-$HOME/android-sdk}"
export ANDROID_SDK_ROOT="$ANDROID_HOME"
export JAVA_HOME="${JAVA_HOME:-/usr/lib/jvm/java-17-openjdk-amd64}"
export PATH="$JAVA_HOME/bin:$PATH"

export APP_BASE_URL="${APP_BASE_URL:-https://bc.bharatseo.site}"
export VERSION_CODE="$VCODE"
export KEYSTORE_FILE="$KEYS/release.jks"
export KEYSTORE_PASSWORD="$(tr -d '[:space:]' < "$KEYS/pw.txt")"
export KEY_ALIAS="${KEY_ALIAS:-bcreport}"
export KEY_PASSWORD="$KEYSTORE_PASSWORD"

cd "$SRC"
chmod +x gradlew
./gradlew --no-daemon assembleRelease

APK="app/build/outputs/apk/release/app-release.apk"
[ -f "$APK" ] || { echo "build produced no APK" >&2; exit 1; }

BT="$(ls -d "$ANDROID_HOME"/build-tools/* | sort -V | tail -1)"
echo "--- signature ---"
"$BT/apksigner" verify --print-certs "$APK" | grep "certificate DN" | head -1
"$BT/aapt2" dump badging "$APK" | grep -oE "versionCode='[0-9]+' versionName='[^']+'" | head -1

echo "--- publishing ---"
sudo cp "$APK" "$DL/bc-report.apk"
sudo chown www:www "$DL/bc-report.apk"
sudo chmod 644 "$DL/bc-report.apk"
printf '{"code":%s,"name":"1.0.%s"}' "$VCODE" "$VCODE" | sudo tee "$DL/version.json" >/dev/null
sudo chown www:www "$DL/version.json"

# The Device Owner (MDM) QR embeds the APK signature checksum, so refresh it too.
if python3 -c "import qrcode, PIL" 2>/dev/null; then
  rm -rf /tmp/mdmout
  python3 "$(dirname "$0")/generate_mdm_provisioning.py" --apk "$APK" --output-dir /tmp/mdmout
  sudo cp /tmp/mdmout/mdm-provisioning.* "$DL/"
  sudo chown www:www "$DL"/mdm-provisioning.*
  echo "MDM provisioning QR refreshed"
else
  echo "WARNING: python3-qrcode/python3-pil missing - MDM QR not refreshed" >&2
fi

echo "done: https://bc.bharatseo.site/downloads/bc-report.apk (1.0.$VCODE)"
