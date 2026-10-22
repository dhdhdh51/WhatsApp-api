# Self-hosted group meetings (LiveKit SFU)

Group video/voice meetings run on our own server – no third-party meeting
service. 1:1 calls use plain WebRTC + our coturn; group meetings need an SFU,
which is what LiveKit provides.

## Server pieces

| Piece | Where | Notes |
|---|---|---|
| LiveKit SFU | `/usr/local/bin/livekit-server` | systemd unit `livekit.service` |
| Config | `/etc/livekit/livekit.yaml` (chmod 600) | API key + secret live here |
| TURN/STUN | coturn, `/etc/turnserver.conf` | used by 1:1 calls |
| Reverse proxy | `/www/server/panel/vhost/openlitespeed/proxy/bc.bharatseo.site/livekit.conf` | exposes `wss://bc.bharatseo.site/rtc` |

LiveKit binds to `127.0.0.1:7880` only; the web server terminates TLS, so no
extra certificate or public HTTP port is needed for signalling.

### Ports that must be open (firewall + cloud security group)

| Port | Proto | Why |
|---|---|---|
| 7881 | TCP | ICE/TCP fallback when UDP is blocked |
| 7882 | UDP | actual audio/video media |
| 3478 | UDP+TCP | coturn (1:1 calls) |
| 49160-49200 | UDP | coturn relay range |

### OpenLiteSpeed reverse proxy

Important: do **not** add a broad `context /rtc { type proxy }`. A proxy
context on the same URI as the `websocket` context breaks the WebSocket
upgrade (the SDK then fails with "Encountered websocket error during
connection establishment"). Only proxy the HTTP sub-path:

```
extprocessor livekit {
  type                    proxy
  address                 127.0.0.1:7880
  maxConns                200
  initTimeout             60
  retryTimeout            0
  respBuffer              0
}

context /rtc/validate {
  type                    proxy
  handler                 livekit
  addDefaultCharset       off
}

websocket /rtc {
  address                 127.0.0.1:7880
}
```

Reload with `/usr/local/lsws/bin/lswsctrl reload`.

## App settings (settings table – secrets stay out of git)

| Key | Value |
|---|---|
| `livekit_url` | `wss://bc.bharatseo.site` – **without** `/rtc`; the SDK appends it |
| `livekit_key` | API key from `/etc/livekit/livekit.yaml` |
| `livekit_secret` | API secret from the same file |
| `webrtc_ice` | JSON STUN/TURN list used by 1:1 calls |

When `livekit_url`/`key`/`secret` are all set, `Meetings::url()` returns our own
page (`/app/meet.html`) and the panel Join button opens `/meet.php?id=N`;
otherwise it falls back to `meeting_base_url`.

## Join flow

1. Host starts a meeting (panel `/meetings.php`, or a CBC/admin from the app).
2. The audience gets a notification + an in-app "Join" banner.
3. Joining opens our page, which asks `/api/app.php?a=meet_token` (app users)
   or reads the token rendered by `/meet.php` (panel users). The token is a
   JWT signed with the LiveKit secret – `src/Livekit.php`.
4. `public/app/meet.js` connects to the room and renders the participant grid.
