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

## Broadcast / town-hall

A meeting started with audience **`broadcast`** (panel `/meetings.php` → "📢 Broadcast")
is a one-to-many town-hall:

- **Rings everyone** – every active BC *and* every active staff member gets the
  meeting ring (FCM) + an in-app banner, regardless of branch/CBC. The fan-out
  runs after the HTTP response returns (`fastcgi_finish_request` /
  `litespeed_finish_request`), so the "Start" click stays fast.
- **View-only audience** – the audience joins with a LiveKit token where
  `canPublish = false` (set in `src/Livekit.php`), so only the host publishes
  audio/video. The SFU still fans the host's single uplink out to every viewer,
  so uplink load does not grow with audience size (viewers only download).
- **Raise hand → speak** – a viewer raises ✋; the host taps "allow", which calls
  `Livekit::setCanPublish()` (RoomService `UpdateParticipant`) and records the
  grant in `meeting_speakers` so it survives a reconnect. "View-only ⬇️" revokes it.
- **Live chat** – persisted in `meeting_messages` and polled (`a=meet_chat` /
  `/meet.php?id=N&chat=1`), so late joiners and view-only viewers see the whole
  thread, not just data-channel messages sent while they were connected.

Because a broadcast can put the whole organisation in one room, keep the UDP media
port (7882) and the ICE/TCP fallback (7881) open, and watch the server's uplink
bandwidth during large town-halls.

## The three broadcasts on the Meetings page

The panel (`/meetings.php`) offers these three broadcasts first, then the meeting
types Everyone / CBCs / BCs / One branch (`all`, `cbc`, `bc`, `branch`). CBC
meetings started from the app (`cbc_bcs`, `one_bc`, `selected`) are unchanged.

| Panel label | `audience` | Who is rung | Stage |
|---|---|---|---|
| 📢 Broadcast | `broadcast` | every active BC + staff in reach | studio stage (host, lower third, सूचना ticker) |
| 📺 Broadcast 2 | `channel` | the same as Broadcast 1 | TV news-channel frame: bank logo, "Central Bank BC Meeting Channel" + RO, LIVE, welcome / notice / topic, APY · PMJJBY · PMSBY cards, सूचना line, slogans |
| 🔗 Broadcast 3 | `guest` | nobody | studio stage; people join only through guest links |

All three are group video calls: everyone shows their camera, and the mic is for
the host and the people he allows (✋ → "🎤 Allow", or "⭐ मंच पर लाएँ").

A BC Supervisor's name in every meeting reads `DEEPAK SHARMA - BC SUPERVISOR AGRA RO`
(`Meetings::displayName` / `supervisorLabel`): name, role and RO in capitals. The RO
is written `<CITY> RO` however the region is named. When a BC Supervisor hosts, the
strip on the host's video shows only that label (`host_label`). Admin and CBC names
are unchanged.

BC Supervisors come first, right after the host (`supKey` in `meet.js`; role taken from
the participant's token metadata). This applies to the meeting tiles, the broadcast
gallery, and the people list (host, BC Supervisors, other staff, then BCs/guests). A
meeting's header starts with the host's label when a BC Supervisor hosts
(`DEEPAK SHARMA - BC SUPERVISOR AGRA RO · title`). Meeting tile names wrap to 3 lines
instead of being cut.

**Broadcast 2** sends `stage_style: 'channel'` to the meeting screen.
`public/app/meet.js` (`buildChannel` / `layoutChannel`) draws the frame at a fixed
design size and scales it to the stage. A wide stage uses 1280×720. A tall phone
stage uses 720 wide and 820–1100 high, and the topic and slogans move into the
scrolling सूचना line. The live video boxes are placed over the presenter area. A
screen share, or a tile a viewer enlarged, fills the stage without the frame. On
phones the gallery shows one row of 3, so the frame stays readable.

**Broadcast 3** rings nobody: no BC, CBC or staff member gets a notification or an
in-app banner, and the scheduler does not re-ring. Starting it creates the first
guest link at once (`src/GuestLinks.php`). The host copies the link or shares it on
WhatsApp, and can make more with "Guest link". A guest opens the link, types a
name and joins with the camera on (camera-only token). Staff join from the panel
("Join"). Links stop working when the broadcast ends or the host closes them.

A Broadcast 3 link allows 300 joins per hour (30 per IP in 15 minutes). A guest
link in any other meeting allows 40 (10 per IP), and that guest stays view-only
until the host brings them onto the stage.
