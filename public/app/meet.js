/* BC Daily Report – group video/voice meeting on our own LiveKit server.
   Used by /app/meet.html (app users) and /meet.php (web panel staff).
   Config either comes from window.MEET_CONFIG or from /api/app.php?a=meet_token. */
(function () {
  'use strict';
  var root, grid, bar, statusEl, titleEl, countEl;
  var room = null, tiles = {}, mode = 'video';

  function q(sel) { return root.querySelector(sel); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function build() {
    root = document.getElementById('meet') || document.body;
    root.innerHTML =
      '<div class="mt-top"><div><div class="mt-title">Meeting</div><div class="mt-status">Connect ho raha hai…</div></div>' +
      '<div class="mt-count">0</div></div>' +
      '<div class="mt-grid"></div>' +
      '<div class="mt-bar"></div>';
    grid = q('.mt-grid'); bar = q('.mt-bar');
    statusEl = q('.mt-status'); titleEl = q('.mt-title'); countEl = q('.mt-count');
  }
  function setStatus(t) { if (statusEl) statusEl.textContent = t; }
  function setCount() {
    if (!countEl || !room) return;
    countEl.textContent = '👥 ' + (room.remoteParticipants.size + 1);
  }

  function tile(id, label) {
    if (tiles[id]) return tiles[id];
    var d = document.createElement('div');
    d.className = 'mt-tile';
    d.innerHTML = '<video autoplay playsinline></video><div class="mt-name">' + esc(label) + '</div><div class="mt-ph">' + esc((label || '?').charAt(0).toUpperCase()) + '</div>';
    grid.appendChild(d);
    tiles[id] = d;
    layout();
    return d;
  }
  function dropTile(id) {
    if (tiles[id]) { tiles[id].remove(); delete tiles[id]; layout(); }
  }
  function layout() {
    var n = Object.keys(tiles).length || 1;
    var cols = n <= 1 ? 1 : (n <= 4 ? 2 : 3);
    grid.style.gridTemplateColumns = 'repeat(' + cols + ', 1fr)';
  }

  function btn(label, onClick, cls) {
    var b = document.createElement('button');
    b.className = 'mt-btn' + (cls ? ' ' + cls : '');
    b.innerHTML = label;
    b.onclick = onClick;
    bar.appendChild(b);
    return b;
  }

  function fail(msg) {
    setStatus('');
    root.innerHTML = '<div class="mt-err"><p>' + esc(msg) + '</p><button class="mt-btn" onclick="history.back()">Wapas</button></div>';
  }

  function config() {
    if (window.MEET_CONFIG) return Promise.resolve(window.MEET_CONFIG);
    var t = localStorage.getItem('bc_token') || '';
    if (!t) return Promise.resolve({ ok: false, error: 'Pehle app mein login karein.' });
    return fetch('/api/app.php?a=meet_token', { headers: { Authorization: 'Bearer ' + t, Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'Internet nahi hai.' }; });
  }

  function start() {
    build();
    if (!window.LivekitClient) { fail('Meeting library load nahi hui. Internet check karein.'); return; }
    config().then(function (cfg) {
      if (!cfg || !cfg.ok) { fail((cfg && cfg.error) || 'Meeting join nahi ho payi.'); return; }
      mode = cfg.mode === 'voice' ? 'voice' : 'video';
      titleEl.textContent = cfg.title || 'Meeting';
      join(cfg);
    });
  }

  function join(cfg) {
    var LK = window.LivekitClient;
    room = new LK.Room({ adaptiveStream: true, dynacast: true });

    room.on(LK.RoomEvent.TrackSubscribed, function (track, pub, participant) {
      var t = tile(participant.identity, participant.name || participant.identity);
      if (track.kind === 'video') { track.attach(t.querySelector('video')); t.classList.add('has-video'); }
      else { var a = document.createElement('audio'); a.autoplay = true; track.attach(a); t.appendChild(a); }
      setCount();
    });
    room.on(LK.RoomEvent.TrackUnsubscribed, function (track, pub, participant) {
      track.detach().forEach(function (e) { if (e.tagName === 'AUDIO') e.remove(); });
      var t = tiles[participant.identity];
      if (t && track.kind === 'video') t.classList.remove('has-video');
    });
    room.on(LK.RoomEvent.ParticipantConnected, function (p) { tile(p.identity, p.name || p.identity); setCount(); setStatus('Connected'); });
    room.on(LK.RoomEvent.ParticipantDisconnected, function (p) { dropTile(p.identity); setCount(); });
    room.on(LK.RoomEvent.Disconnected, function () { setStatus('Meeting khatam'); setTimeout(function () { history.back(); }, 1200); });

    room.connect(cfg.url, cfg.token).then(function () {
      setStatus('Connected');
      var me = tile('me', (cfg.name || 'Main') + ' (aap)');
      return room.localParticipant.setMicrophoneEnabled(true).then(function () {
        if (mode !== 'video') return null;
        return room.localParticipant.setCameraEnabled(true).then(function () {
          var pubs = Array.from(room.localParticipant.videoTrackPublications.values());
          if (pubs.length && pubs[0].track) { pubs[0].track.attach(me.querySelector('video')); me.classList.add('has-video'); }
        });
      });
    }).then(function () {
      var micOn = true, camOn = mode === 'video';
      var mb = btn('🎤', function () {
        micOn = !micOn;
        room.localParticipant.setMicrophoneEnabled(micOn);
        mb.style.opacity = micOn ? 1 : .45;
      });
      if (mode === 'video') {
        var cb = btn('📷', function () {
          camOn = !camOn;
          room.localParticipant.setCameraEnabled(camOn).then(function () {
            var pubs = Array.from(room.localParticipant.videoTrackPublications.values());
            var meT = tiles['me'];
            if (camOn && pubs.length && pubs[0].track && meT) { pubs[0].track.attach(meT.querySelector('video')); meT.classList.add('has-video'); }
            else if (meT) { meT.classList.remove('has-video'); }
          });
          cb.style.opacity = camOn ? 1 : .45;
        });
      }
      btn('📴 Leave', function () { room.disconnect(); }, 'red');
      setCount();
    }).catch(function (e) {
      fail('Join nahi ho payi: ' + (e && e.message ? e.message : 'camera/mic ya network problem'));
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
