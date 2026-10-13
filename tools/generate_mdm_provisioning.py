#!/usr/bin/env python3
"""Generate Android Enterprise Device Owner QR provisioning files for the signed BC Report APK."""
from __future__ import annotations
import argparse
import base64
import hashlib
import json
from pathlib import Path

PACKAGE = "site.bharatseo.bcreport"
COMPONENT = "site.bharatseo.bcreport/.MdmAdminReceiver"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apk", required=True, type=Path)
    parser.add_argument("--base-url", default="https://bc.bharatseo.site")
    parser.add_argument("--output-dir", default="public/downloads", type=Path)
    args = parser.parse_args()

    apk = args.apk.resolve()
    data = apk.read_bytes()
    checksum = base64.urlsafe_b64encode(hashlib.sha256(data).digest()).decode().rstrip("=")
    cfg = {
        "android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME": COMPONENT,
        "android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION": args.base_url.rstrip("/") + "/downloads/bc-report.apk",
        "android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_CHECKSUM": checksum,
        "android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE": {
            "server_url": args.base_url.rstrip("/"),
            "organization": "BC Daily Report",
        },
    }
    out = args.output_dir
    out.mkdir(parents=True, exist_ok=True)
    raw = json.dumps(cfg, ensure_ascii=False, separators=(",", ":"))
    (out / "mdm-provisioning.json").write_text(json.dumps(cfg, ensure_ascii=False, indent=2) + "\n")
    try:
        import qrcode
        qr = qrcode.QRCode(version=None, error_correction=qrcode.constants.ERROR_CORRECT_M, box_size=10, border=4)
        qr.add_data(raw)
        qr.make(fit=True)
        qr.make_image(fill_color="black", back_color="white").save(out / "mdm-provisioning.png")
    except ImportError:
        print("qrcode package missing; JSON was generated but no PNG")
    print(json.dumps({"sha256_base64url": checksum, "bytes": len(data), "component": COMPONENT}))


if __name__ == "__main__":
    main()
