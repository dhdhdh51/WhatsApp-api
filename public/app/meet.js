/* BC Daily Report – group video/voice on our own LiveKit server.
   Used by /app/meet.html (app users) and /meet.php (panel staff).

   Big rooms: audio for everyone, video only for the tiles on the current page
   (adaptiveStream pauses the rest). Active speakers and raised hands sort to
   the front. Tap a tile to zoom it. Host can mute/unmute. In-meeting chat
   rides on the LiveKit data channel. */
(function () {
  'use strict';
  var root, grid, bar, pager, audioBox, statusEl, titleEl, countEl, chatBox, chatLog, chatIn, handsEl;
  var room = null, cfg = null, isHost = false, mode = 'video';
  var P = {};                 // identity -> { name, isLocal, videoTrack, el, speaking, micOff, hand }
  var page = 0, pinned = null, chatOpen = false, unread = 0;
  var PER = Math.max(1, Math.min(12, parseInt((location.search.match(/[?&]per=(\d+)/) || [])[1] || '6', 10) || 6));

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function q(s) { return root.querySelector(s); }

  function build() {
    root = document.getElementById('meet') || document.body;
    root.innerHTML =
      '<div class="mt-top"><div class="mt-head"><div class="mt-title">Meeting</div><div class="mt-status">Connect ho raha hai…</div></div>' +
      '<div class="mt-hands"></div><div class="mt-count">0</div></div>' +
      '<div class="mt-grid"></div>' +
      '<div class="mt-pager"></div>' +
      '<div class="mt-chat"><div class="mt-chat-log"></div>' +
      '<div class="mt-chat-in"><input type="text" maxlength="500" placeholder="Message likhein…"><button>Bhejein</button></div></div>' +
      '<div class="mt-bar"></div>' +
      '<div class="mt-audio" aria-hidden="true"></div>';
    grid = q('.mt-grid'); bar = q('.mt-bar'); pager = q('.mt-pager');
    audioBox = q('.mt-audio'); statusEl = q('.mt-status'); titleEl = q('.mt-title');
    countEl = q('.mt-count'); handsEl = q('.mt-hands');
    chatBox = q('.mt-chat'); chatLog = q('.mt-chat-log'); chatIn = q('.mt-chat-in input');
    q('.mt-chat-in button').onclick = sendChat;
    chatIn.onkeydown = function (e) { if (e.key === 'Enter') sendChat(); };
  }
  function setStatus(t) { if (statusEl) statusEl.textContent = t; }
  function fail(msg) {
    root.innerHTML = '<div class="mt-err"><p>' + esc(msg) + '</p><button class="mt-btn" id="mtBack">Wapas</button></div>';
    var b = document.getElementById('mtBack');
    if (b) b.onclick = function () { history.back(); };
  }

  // ---------------------------------------------------------------- tiles
  function makeTile(id) {
    var p = P[id];
    var d = document.createElement('div');
    d.className = 'mt-tile';
    d.innerHTML = '<video autoplay playsinline muted></video>' +
      '<div class="mt-ph">' + esc((p.name || '?').charAt(0).toUpperCase()) + '</div>' +
      '<div class="mt-name"><span class="mt-mic"></span><span class="mt-nm"></span></div>' +
      '<div class="mt-hand">✋</div>' +
      (isHost && !p.isLocal ? '<button class="mt-mod" title="Mute / unmute">🔇</button>' : '');
    d.onclick = function () { pinned = (pinned === id) ? null : id; render(); };
    var mod = d.querySelector('.mt-mod');
    if (mod) mod.onclick = function (e) { e.stopPropagation(); setMuted(id, !P[id].micOff); };
    return d;
  }
  function updateTile(id) {
    var p = P[id];
    if (!p || !p.el) return;
    p.el.querySelector('.mt-nm').textContent = p.name + (p.isLocal ? ' (aap)' : '');
    p.el.querySelector('.mt-mic').textContent = p.micOff ? '🔇 ' : '';
    p.el.classList.toggle('speaking', !!p.speaking);
    p.el.classList.toggle('raised', !!p.hand);
    p.el.classList.toggle('pinned', pinned === id);
    var v = p.el.querySelector('video');
    if (p.videoTrack) {
      if (v.dataset.att !== '1') { p.videoTrack.attach(v); v.dataset.att = '1'; }
      p.el.classList.add('has-video');
    } else {
      p.el.classList.remove('has-video');
    }
    var mod = p.el.querySelector('.mt-mod');
    if (mod) mod.textContent = p.micOff ? '🔈' : '🔇';
  }

  function order() {
    return Object.keys(P).sort(function (a, b) {
      var pa = P[a], pb = P[b];
      if (!!pb.speaking !== !!pa.speaking) return pb.speaking ? 1 : -1;   // speakers first
      if (!!pb.hand !== !!pa.hand) return pb.hand ? 1 : -1;               // then raised hands
      if (pa.isLocal !== pb.isLocal) return pa.isLocal ? -1 : 1;
      return String(pa.name).localeCompare(String(pb.name));
    });
  }

  function render() {
    var ids = order();
    var vis;
    if (pinned && P[pinned]) {
      vis = [pinned];                                  // zoomed: just that person
      pager.innerHTML = '<span class="mt-pglbl">Zoom – tap karke wapas</span>';
    } else {
      var pages = Math.max(1, Math.ceil(ids.length / PER));
      if (page > pages - 1) page = pages - 1;
      vis = ids.slice(page * PER, page * PER + PER);
      pager.innerHTML = '';
      if (pages > 1) {
        var mk = function (txt, to, dis) {
          var b = document.createElement('button');
          b.className = 'mt-pg'; b.textContent = txt; b.disabled = !!dis;
          b.onclick = function () { page = to; render(); };
          pager.appendChild(b);
        };
        mk('‹ Pichhle', page - 1, page === 0);
        var lbl = document.createElement('span');
        lbl.className = 'mt-pglbl'; lbl.textContent = (page + 1) + ' / ' + pages;
        pager.appendChild(lbl);
        mk('Aage ›', page + 1, page >= pages - 1);
      }
    }

    ids.forEach(function (id) {
      var p = P[id];
      if (vis.indexOf(id) < 0 && p.el && p.el.parentNode) {
        var v = p.el.querySelector('video');
        if (p.videoTrack && v && v.dataset.att === '1') { try { p.videoTrack.detach(v); } catch (e) {} v.dataset.att = ''; }
        p.el.remove();
      }
    });
    vis.forEach(function (id) {
      if (!P[id].el) P[id].el = makeTile(id);
      grid.appendChild(P[id].el);
      updateTile(id);
    });

    grid.classList.toggle('zoomed', !!pinned);
    grid.style.gridTemplateColumns = 'repeat(' + (pinned ? 1 : (vis.length <= 1 ? 1 : (vis.length <= 4 ? 2 : 3))) + ', 1fr)';
    countEl.textContent = '👥 ' + ids.length;
    var hands = ids.filter(function (i) { return P[i].hand; });
    handsEl.textContent = hands.length ? '✋ ' + hands.length : '';
    handsEl.title = hands.map(function (i) { return P[i].name; }).join(', ');
    quality(ids.length);
  }

  /** Low-res video when many people are on screen; full quality for a zoomed tile. */
  function quality(total) {
    var LK = window.LivekitClient;
    if (!room || !LK || !LK.VideoQuality) return;
    room.remoteParticipants.forEach(function (rp) {
      var want = (pinned === rp.identity) ? LK.VideoQuality.HIGH
        : (total > 4 ? LK.VideoQuality.LOW : (total > 2 ? LK.VideoQuality.MEDIUM : LK.VideoQuality.HIGH));
      rp.videoTrackPublications.forEach(function (pub) {
        try { if (pub.isSubscribed) pub.setVideoQuality(want); } catch (e) {}
      });
    });
  }

  function upsert(identity, name, isLocal) {
    if (!P[identity]) P[identity] = { name: name || identity, isLocal: !!isLocal, videoTrack: null, el: null, speaking: false, micOff: false, hand: false };
    else if (name) P[identity].name = name;
    return P[identity];
  }
  function drop(identity) {
    var p = P[identity];
    if (!p) return;
    if (p.el && p.el.parentNode) p.el.remove();
    if (p.audioEl) p.audioEl.remove();
    if (pinned === identity) pinned = null;
    delete P[identity];
  }

  // ---------------------------------------------------------------- chat (data channel)
  function addChat(name, text, mine) {
    var d = document.createElement('div');
    d.className = 'mt-msg' + (mine ? ' mine' : '');
    d.innerHTML = '<b>' + esc(name) + '</b> ' + esc(text);
    chatLog.appendChild(d);
    chatLog.scrollTop = chatLog.scrollHeight;
    if (!chatOpen && !mine) { unread++; paintChatBtn(); }
  }
  function sendChat() {
    var t = (chatIn.value || '').trim();
    if (!t || !room) return;
    chatIn.value = '';
    try {
      var payload = new TextEncoder().encode(JSON.stringify({ t: 'chat', n: cfg.name || 'Main', m: t }));
      room.localParticipant.publishData(payload, { reliable: true });
    } catch (e) {}
    addChat((cfg.name || 'Main') + ' (aap)', t, true);
  }
  var chatBtn = null;
  function paintChatBtn() { if (chatBtn) chatBtn.innerHTML = '💬' + (unread ? '<i>' + unread + '</i>' : ''); }
  function toggleChat() {
    chatOpen = !chatOpen;
    chatBox.classList.toggle('open', chatOpen);
    if (chatOpen) { unread = 0; paintChatBtn(); chatIn.focus(); }
  }

  // ---------------------------------------------------------------- hand raise
  function toggleHand() {
    if (!room) return;
    var me = P['me'];
    me.hand = !me.hand;
    try { room.localParticipant.setAttributes({ hand: me.hand ? '1' : '' }); } catch (e) {}
    try {
      room.localParticipant.publishData(new TextEncoder().encode(JSON.stringify({ t: 'hand', up: me.hand })), { reliable: true });
    } catch (e) {}
    if (handBtn) handBtn.style.opacity = me.hand ? 1 : .55;
    updateTile('me');
    render();
  }
  var handBtn = null;

  // ---------------------------------------------------------------- host actions
  function setMuted(identity, muted) {
    if (!isHost) return;
    api('meet_mute', { identity: identity, muted: muted }).then(function (r) {
      if (!r || !r.ok) return;
      if (P[identity]) { P[identity].micOff = muted; updateTile(identity); }
    });
  }
  function muteAll() {
    if (!isHost) return;
    api('meet_mute', { identity: '*', muted: true }).then(function (r) {
      setStatus(r && r.ok ? ((r.changed || 0) + ' logon ko mute kiya') : 'Mute nahi ho paya');
      setTimeout(function () { setStatus('Connected'); }, 2500);
    });
  }
  function api(action, body) {
    var h = { 'Content-Type': 'application/json', Accept: 'application/json' };
    var url = (cfg && cfg.mute_url) ? cfg.mute_url : '/api/app.php?a=' + action;
    if (cfg && cfg.csrf) h['X-CSRF-TOKEN'] = cfg.csrf;
    else {
      var t = localStorage.getItem('bc_token');
      if (t) h.Authorization = 'Bearer ' + t;
    }
    return fetch(url, { method: 'POST', headers: h, body: JSON.stringify(body || {}) })
      .then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
  }

  function btn(label, onClick, cls) {
    var b = document.createElement('button');
    b.className = 'mt-btn' + (cls ? ' ' + cls : '');
    b.innerHTML = label; b.onclick = onClick;
    bar.appendChild(b);
    return b;
  }

  // ---------------------------------------------------------------- bootstrap
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
    config().then(function (c) {
      if (!c || !c.ok) { fail((c && c.error) || 'Meeting join nahi ho payi.'); return; }
      cfg = c;
      mode = c.mode === 'voice' ? 'voice' : 'video';
      isHost = !!c.is_host;
      titleEl.textContent = c.title || 'Meeting';
      join();
    });
  }

  function join() {
    var LK = window.LivekitClient;
    room = new LK.Room({
      adaptiveStream: true,
      dynacast: true,
      audioCaptureDefaults: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      videoCaptureDefaults: { resolution: LK.VideoPresets && LK.VideoPresets.h360 ? LK.VideoPresets.h360.resolution : undefined },
      publishDefaults: { dtx: true, red: true, simulcast: true }
    });

    room.on(LK.RoomEvent.TrackSubscribed, function (track, pub, rp) {
      var p = upsert(rp.identity, rp.name || rp.identity, false);
      if (track.kind === 'video') { p.videoTrack = track; }
      else {
        var a = document.createElement('audio');
        a.autoplay = true;
        track.attach(a);
        audioBox.appendChild(a);
        p.audioEl = a;
        p.micOff = !!pub.isMuted;
      }
      render();
    });
    room.on(LK.RoomEvent.TrackUnsubscribed, function (track, pub, rp) {
      var p = P[rp.identity];
      if (!p) return;
      if (track.kind === 'video') {
        var v = p.el && p.el.querySelector('video');
        if (v && v.dataset.att === '1') { try { track.detach(v); } catch (e) {} v.dataset.att = ''; }
        p.videoTrack = null;
      } else if (p.audioEl) { try { track.detach(p.audioEl); } catch (e) {} p.audioEl.remove(); p.audioEl = null; }
      render();
    });
    room.on(LK.RoomEvent.TrackMuted, function (pub, rp) { if (P[rp.identity] && pub.kind === 'audio') { P[rp.identity].micOff = true; updateTile(rp.identity); } });
    room.on(LK.RoomEvent.TrackUnmuted, function (pub, rp) { if (P[rp.identity] && pub.kind === 'audio') { P[rp.identity].micOff = false; updateTile(rp.identity); } });
    room.on(LK.RoomEvent.ParticipantConnected, function (rp) { upsert(rp.identity, rp.name || rp.identity, false); setStatus('Connected'); render(); });
    room.on(LK.RoomEvent.ParticipantDisconnected, function (rp) { drop(rp.identity); render(); });
    room.on(LK.RoomEvent.ActiveSpeakersChanged, function (sp) {
      var on = {};
      sp.forEach(function (s) { on[s.identity] = true; });
      Object.keys(P).forEach(function (id) { P[id].speaking = !!on[id]; });
      render();
    });
    if (LK.RoomEvent.ParticipantAttributesChanged) {
      room.on(LK.RoomEvent.ParticipantAttributesChanged, function (changed, rp) {
        var p = P[rp.identity];
        if (!p) return;
        if (changed && typeof changed.hand !== 'undefined') { p.hand = changed.hand === '1'; render(); }
      });
    }
    room.on(LK.RoomEvent.DataReceived, function (payload, rp) {
      var msg;
      try { msg = JSON.parse(new TextDecoder().decode(payload)); } catch (e) { return; }
      if (!msg) return;
      if (msg.t === 'chat') addChat(msg.n || (rp && rp.name) || 'Koi', msg.m || '', false);
      else if (msg.t === 'hand' && rp && P[rp.identity]) { P[rp.identity].hand = !!msg.up; render(); }
    });
    room.on(LK.RoomEvent.Disconnected, function () { setStatus('Meeting khatam'); setTimeout(function () { history.back(); }, 1200); });

    room.connect(cfg.url, cfg.token).then(function () {
      setStatus('Connected');
      var me = upsert('me', cfg.name || 'Main', true);
      return room.localParticipant.setMicrophoneEnabled(true).then(function () {
        if (mode !== 'video') return null;
        return room.localParticipant.setCameraEnabled(true).then(function () {
          var pubs = Array.from(room.localParticipant.videoTrackPublications.values());
          if (pubs.length && pubs[0].track) me.videoTrack = pubs[0].track;
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
            P['me'].videoTrack = (camOn && pubs.length && pubs[0].track) ? pubs[0].track : null;
            var v = P['me'].el && P['me'].el.querySelector('video');
            if (v) v.dataset.att = '';
            updateTile('me');
          });
          cb.style.opacity = camOn ? 1 : .45;
        });
      }
      handBtn = btn('✋', toggleHand);
      handBtn.style.opacity = .55;
      chatBtn = btn('💬', toggleChat, 'chat');
      if (isHost) btn('🔇 Sabko mute', muteAll, 'wide');
      btn('📴', function () { room.disconnect(); }, 'red');
      render();
    }).catch(function (e) {
      fail('Join nahi ho payi: ' + (e && e.message ? e.message : 'camera/mic ya network problem'));
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
