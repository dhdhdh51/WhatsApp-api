/* BC Daily Report – group video/voice + live broadcast on our own LiveKit server.
   Used by /app/meet.html (app users) and /meet.php (panel staff).

   Meeting   : everyone publishes; big rooms paginate; host can mute/unmute.
   Broadcast : the audience joins view-only (token canPublish=false), only the host (and
               anyone the host promotes) publishes. Viewers raise a hand ✋ and the host can
               "allow to speak". In-meeting chat is persistent (DB-backed, polled) so late
               joiners and view-only viewers see the whole thread. */
(function () {
  'use strict';
  var root, grid, bar, pager, audioBox, statusEl, titleEl, countEl, chatBox, chatLog, chatIn, handsEl, liveEl, trayEl;
  var room = null, cfg = null, isHost = false, mode = 'video';
  var canPublish = true, broadcastMode = false, myHand = false, viewers = 1;
  var P = {};                 // identity -> { name, isLocal, videoTrack, el, speaking, micOff, hand }
  var hands = {};             // identity -> name (raised hands, for the host tray / badge)
  var page = 0, pinned = null, chatOpen = false, unread = 0;
  var lastChatId = 0, chatTimer = null, micOn = false, camOn = false, chatBtn = null, handBtn = null;
  var PER = Math.max(1, Math.min(12, parseInt((location.search.match(/[?&]per=(\d+)/) || [])[1] || '6', 10) || 6));

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function enc(o) { return new TextEncoder().encode(JSON.stringify(o)); }
  function q(s) { return root.querySelector(s); }

  function build() {
    root = document.getElementById('meet') || document.body;
    root.innerHTML =
      '<div class="mt-top"><div class="mt-head"><div class="mt-title">Meeting</div><div class="mt-status">Connect ho raha hai…</div></div>' +
      '<div class="mt-live"></div><div class="mt-hands"></div><div class="mt-count">0</div></div>' +
      '<div class="mt-tray"></div>' +
      '<div class="mt-grid"></div>' +
      '<div class="mt-pager"></div>' +
      '<div class="mt-chat"><div class="mt-chat-log"></div>' +
      '<div class="mt-chat-in"><input type="text" maxlength="500" placeholder="Message likhein…"><button>Bhejein</button></div></div>' +
      '<div class="mt-bar"></div>' +
      '<div class="mt-audio" aria-hidden="true"></div>';
    grid = q('.mt-grid'); bar = q('.mt-bar'); pager = q('.mt-pager');
    audioBox = q('.mt-audio'); statusEl = q('.mt-status'); titleEl = q('.mt-title');
    countEl = q('.mt-count'); handsEl = q('.mt-hands'); liveEl = q('.mt-live'); trayEl = q('.mt-tray');
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
      (isHost && !p.isLocal ? '<button class="mt-mod" title="Mute / unmute">🔇</button>' : '') +
      (isHost && broadcastMode && !p.isLocal ? '<button class="mt-demote" title="View-only">⬇️</button>' : '');
    d.onclick = function () { pinned = (pinned === id) ? null : id; render(); };
    var mod = d.querySelector('.mt-mod');
    if (mod) mod.onclick = function (e) { e.stopPropagation(); setMuted(id, !P[id].micOff); };
    var dem = d.querySelector('.mt-demote');
    if (dem) dem.onclick = function (e) { e.stopPropagation(); promote(id, false); };
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

    if (!ids.length) {
      grid.innerHTML = '<div class="mt-wait">' + (broadcastMode ? 'Host connect ho raha hai… 📢' : 'Koi aur nahi juda hai.') + '</div>';
    }
    grid.classList.toggle('zoomed', !!pinned);
    grid.style.gridTemplateColumns = 'repeat(' + (pinned ? 1 : (vis.length <= 1 ? 1 : (vis.length <= 4 ? 2 : 3))) + ', 1fr)';
    updateCount();
    updateHandsBadge();
    quality(ids.length);
  }

  function updateCount() {
    if (broadcastMode) countEl.textContent = '👁 ' + Math.max(viewers, Object.keys(P).length);
    else countEl.textContent = '👥 ' + Object.keys(P).length;
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

  // ---------------------------------------------------------------- persistent chat (DB-backed)
  function chatReq(method, extra, body) {
    var headers = { Accept: 'application/json' };
    var url;
    if (cfg.chat_url) {                               // panel (session + CSRF)
      url = cfg.chat_url + (extra || '');
      if (method === 'POST' && cfg.csrf) headers['X-CSRF-TOKEN'] = cfg.csrf;
    } else {                                          // app (bearer token)
      url = '/api/app.php?a=meet_chat' + (extra || '');
      var t = localStorage.getItem('bc_token'); if (t) headers.Authorization = 'Bearer ' + t;
    }
    var init = { method: method, headers: headers };
    if (body) { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
    return fetch(url, init).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
  }
  function renderMsg(m) {
    if (!m || m.id <= lastChatId) return;             // monotonic dedupe
    lastChatId = m.id;
    var d = document.createElement('div');
    d.className = 'mt-msg' + (m.mine ? ' mine' : '') + (m.staff ? ' staff' : '');
    d.innerHTML = '<b>' + esc(m.name) + '</b> ' + esc(m.body) + '<i class="mt-t">' + esc(m.time || '') + '</i>';
    chatLog.appendChild(d);
    chatLog.scrollTop = chatLog.scrollHeight;
    if (!chatOpen && !m.mine) { unread++; paintChatBtn(); }
  }
  function chatPoll() {
    chatReq('GET', '&after=' + lastChatId).then(function (r) {
      if (r && r.ok && r.rows) r.rows.forEach(renderMsg);
    });
  }
  function sendChat() {
    var t = (chatIn.value || '').trim();
    if (!t) return;
    chatIn.value = '';
    chatReq('POST', '&after=' + lastChatId, { body: t }).then(function (r) {
      if (r && r.ok && r.rows) r.rows.forEach(renderMsg);
      else setStatus('Message nahi gaya – dobara koshish karein');
    });
  }
  function startChat() { chatPoll(); chatTimer = setInterval(chatPoll, 3000); }
  function paintChatBtn() { if (chatBtn) chatBtn.innerHTML = '💬' + (unread ? '<i>' + unread + '</i>' : ''); }
  function toggleChat() {
    chatOpen = !chatOpen;
    chatBox.classList.toggle('open', chatOpen);
    if (chatOpen) { unread = 0; paintChatBtn(); chatIn.focus(); }
  }

  // ---------------------------------------------------------------- hand raise + host promote
  function toggleHand() {
    if (!room) return;
    myHand = !myHand;
    if (P['me']) P['me'].hand = myHand;
    try { room.localParticipant.setAttributes({ hand: myHand ? '1' : '' }); } catch (e) {}
    try { room.localParticipant.publishData(enc({ t: 'hand', up: myHand, n: cfg.name || 'Main' }), { reliable: true }); } catch (e) {}
    if (handBtn) handBtn.style.opacity = myHand ? 1 : .55;
    if (P['me']) updateTile('me');
    if (myHand && broadcastMode && !canPublish) setStatus('✋ Haath uthaya – host se bolne ki permission ka intezaar…');
    else if (!myHand && broadcastMode && !canPublish) setStatus('LIVE – aap dekh/sun rahe hain');
    render();
  }
  function handRaised(identity, name, up) {
    if (!identity) return;
    if (up) hands[identity] = name || (P[identity] && P[identity].name) || 'Koi';
    else delete hands[identity];
    if (P[identity]) { P[identity].hand = !!up; updateTile(identity); }
    updateHandsBadge(); renderTray();
  }
  function updateHandsBadge() {
    var ids = Object.keys(hands);
    handsEl.textContent = ids.length ? '✋ ' + ids.length : '';
    handsEl.title = ids.map(function (i) { return hands[i]; }).join(', ');
  }
  function renderTray() {
    if (!trayEl) return;
    var ids = Object.keys(hands);
    if (!isHost || !broadcastMode || !ids.length) { trayEl.className = 'mt-tray'; trayEl.innerHTML = ''; return; }
    trayEl.className = 'mt-tray open';
    trayEl.innerHTML = '<div class="mt-tray-h">✋ Bolna chahte hain (' + ids.length + ')</div>';
    ids.forEach(function (id) {
      var r = document.createElement('div'); r.className = 'mt-tray-row';
      var s = document.createElement('span'); s.textContent = hands[id]; r.appendChild(s);
      var b = document.createElement('button'); b.textContent = '🎤 Allow';
      b.onclick = function () { promote(id, true); };
      r.appendChild(b); trayEl.appendChild(r);
    });
  }
  function promote(identity, allow) {
    var headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
    var url;
    if (cfg.promote_url) { url = cfg.promote_url; if (cfg.csrf) headers['X-CSRF-TOKEN'] = cfg.csrf; }
    else { url = '/api/app.php?a=meet_promote'; var t = localStorage.getItem('bc_token'); if (t) headers.Authorization = 'Bearer ' + t; }
    return fetch(url, { method: 'POST', headers: headers, body: JSON.stringify({ identity: identity, allow: allow }) })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (r && r.ok) {
          try { room.localParticipant.publishData(enc({ t: 'promote', id: identity, allow: allow }), { reliable: true }); } catch (e) {}
          if (allow) { delete hands[identity]; if (P[identity]) P[identity].hand = false; }
          updateHandsBadge(); renderTray();
          setStatus(allow ? 'Bolne ki permission de di' : 'Wapas view-only kar diya');
        } else { setStatus('Permission badal nahi payi'); }
        return r;
      }).catch(function () { return { ok: false }; });
  }

  // ---------------------------------------------------------------- host mute
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
      setTimeout(function () { setStatus(liveStatus()); }, 2500);
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

  // ---------------------------------------------------------------- local media / publish state
  function liveStatus() {
    if (broadcastMode && !canPublish) return 'LIVE – aap dekh/sun rahe hain';
    if (broadcastMode) return '🔴 LIVE – aap present kar rahe hain';
    return 'Connected';
  }
  function enableLocalMedia() {
    micOn = true;
    return room.localParticipant.setMicrophoneEnabled(true).then(function () {
      if (mode !== 'video') return null;
      camOn = true;
      return room.localParticipant.setCameraEnabled(true).then(function () {
        var pubs = Array.from(room.localParticipant.videoTrackPublications.values());
        if (pubs.length && pubs[0].track && P['me']) P['me'].videoTrack = pubs[0].track;
      });
    });
  }
  function toggleMic() {
    micOn = !micOn;
    room.localParticipant.setMicrophoneEnabled(micOn);
    var b = bar.querySelector('.mic-btn'); if (b) b.style.opacity = micOn ? 1 : .45;
  }
  function toggleCam() {
    camOn = !camOn;
    room.localParticipant.setCameraEnabled(camOn).then(function () {
      var pubs = Array.from(room.localParticipant.videoTrackPublications.values());
      if (P['me']) P['me'].videoTrack = (camOn && pubs.length && pubs[0].track) ? pubs[0].track : null;
      var v = P['me'] && P['me'].el && P['me'].el.querySelector('video');
      if (v) v.dataset.att = '';
      updateTile('me');
    });
    var b = bar.querySelector('.cam-btn'); if (b) b.style.opacity = camOn ? 1 : .45;
  }
  function renderBar() {
    bar.innerHTML = '';
    if (canPublish) {
      var mb = btn('🎤', toggleMic, 'mic-btn'); mb.style.opacity = micOn ? 1 : .45;
      if (mode === 'video') { var cb = btn('📷', toggleCam, 'cam-btn'); cb.style.opacity = camOn ? 1 : .45; }
    }
    handBtn = btn('✋', toggleHand, 'hand'); handBtn.style.opacity = myHand ? 1 : .55;
    handBtn.title = canPublish ? 'Haath uthayein' : 'Bolne ke liye haath uthayein';
    chatBtn = btn('💬', toggleChat, 'chat'); paintChatBtn();
    if (isHost) btn('🔇 Sabko mute', muteAll, 'wide');
    btn('📴', function () { try { room.disconnect(); } catch (e) {} }, 'red');
  }
  /** Re-sync UI when the server grants or revokes our publish permission (broadcast promote). */
  function syncLocalPublish() {
    if (!room || !room.localParticipant) return;
    var perm = room.localParticipant.permissions;
    var allowed = perm ? !!perm.canPublish : canPublish;
    if (allowed === canPublish) return;
    canPublish = allowed;
    if (allowed) {
      upsert('me', cfg.name || 'Main', true);
      myHand = false; if (P['me']) P['me'].hand = false;
      try { room.localParticipant.setAttributes({ hand: '' }); } catch (e) {}
      enableLocalMedia().then(function () { updateTile('me'); render(); });
      setStatus('✅ Aap ab bol sakte hain');
    } else {
      micOn = camOn = false;
      try { room.localParticipant.setMicrophoneEnabled(false); } catch (e) {}
      try { room.localParticipant.setCameraEnabled(false); } catch (e) {}
      drop('me');
      setStatus('🔇 Aap wapas view-only hain');
    }
    renderBar();
    render();
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
      broadcastMode = !!c.is_broadcast;
      canPublish = (c.can_publish !== false);
      titleEl.textContent = c.title || (broadcastMode ? 'Broadcast' : 'Meeting');
      if (broadcastMode) liveEl.textContent = '🔴 LIVE';
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
      if (hands[rp.identity]) { delete hands[rp.identity]; updateHandsBadge(); renderTray(); } // promoted -> speaking
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
      // in a broadcast, a participant with no more tracks is back to a plain viewer – drop the tile
      if (broadcastMode && !p.videoTrack && !p.audioEl && !p.isLocal) drop(rp.identity);
      render();
    });
    room.on(LK.RoomEvent.TrackMuted, function (pub, rp) { if (P[rp.identity] && pub.kind === 'audio') { P[rp.identity].micOff = true; updateTile(rp.identity); } });
    room.on(LK.RoomEvent.TrackUnmuted, function (pub, rp) { if (P[rp.identity] && pub.kind === 'audio') { P[rp.identity].micOff = false; updateTile(rp.identity); } });
    room.on(LK.RoomEvent.ParticipantConnected, function (rp) {
      if (broadcastMode) { viewers++; updateCount(); }      // viewers don't get a tile until they publish
      else { upsert(rp.identity, rp.name || rp.identity, false); setStatus('Connected'); render(); }
    });
    room.on(LK.RoomEvent.ParticipantDisconnected, function (rp) {
      if (hands[rp.identity]) { delete hands[rp.identity]; updateHandsBadge(); renderTray(); }
      drop(rp.identity);
      if (broadcastMode) viewers = Math.max(1, viewers - 1);
      render();
    });
    room.on(LK.RoomEvent.ActiveSpeakersChanged, function (sp) {
      var on = {};
      sp.forEach(function (s) { on[s.identity] = true; });
      Object.keys(P).forEach(function (id) { P[id].speaking = !!on[id]; });
      render();
    });
    if (LK.RoomEvent.ParticipantAttributesChanged) {
      room.on(LK.RoomEvent.ParticipantAttributesChanged, function (changed, rp) {
        if (rp === room.localParticipant) return;
        if (changed && typeof changed.hand !== 'undefined') handRaised(rp.identity, rp.name || rp.identity, changed.hand === '1');
      });
    }
    if (LK.RoomEvent.ParticipantPermissionsChanged) {
      room.on(LK.RoomEvent.ParticipantPermissionsChanged, function (prev, participant) {
        if (!participant || participant === room.localParticipant || participant.isLocal) syncLocalPublish();
      });
    }
    room.on(LK.RoomEvent.DataReceived, function (payload, rp) {
      var msg;
      try { msg = JSON.parse(new TextDecoder().decode(payload)); } catch (e) { return; }
      if (!msg) return;
      if (msg.t === 'hand' && rp) handRaised(rp.identity, msg.n || rp.name || rp.identity, !!msg.up);
      else if (msg.t === 'promote' && room.localParticipant && msg.id === room.localParticipant.identity) {
        if (msg.allow) setStatus('✅ Host ne aapko bolne ki permission di');
        setTimeout(syncLocalPublish, 400);
      }
    });
    room.on(LK.RoomEvent.Disconnected, function () {
      if (chatTimer) { clearInterval(chatTimer); chatTimer = null; }
      setStatus('Meeting khatam'); setTimeout(function () { history.back(); }, 1200);
    });

    room.connect(cfg.url, cfg.token).then(function () {
      viewers = (room.remoteParticipants ? room.remoteParticipants.size : 0) + 1;
      setStatus(liveStatus());
      if (canPublish) {
        upsert('me', cfg.name || 'Main', true);
        return enableLocalMedia();
      }
      return null;
    }).then(function () {
      renderBar();
      render();
      startChat();
    }).catch(function (e) {
      fail('Join nahi ho payi: ' + (e && e.message ? e.message : 'camera/mic ya network problem'));
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
