/* BC Monitoring App — group video / voice + LIVE broadcast (LiveKit).
   Used by /app/meet.html (app users) and /meet.php (panel staff).

   Normal meeting  -> simple tile grid (build/join): everyone talks and shows video.
   Broadcast       -> news-studio layout (buildStudio/joinStudio), run like a group video call:
                      the host is big on the stage and EVERYONE's camera is in the gallery below it.
                      The microphone is for the host, moderators and people the host allowed
                      (raised hand -> Allow); everyone else joins with camera only (token
                      canPublishSources = camera). Central Bank of India branding, LIVE badge,
                      lower-third with the host's RO, tickers, participants list, chat, scheme panel. */
(function () {
  'use strict';
  var root, grid, bar, pager, audioBox, statusEl, titleEl, countEl, chatBox, chatLog, chatIn, handsEl, liveEl, trayEl;
  var room = null, cfg = null, isHost = false, mode = 'video';
  var canPublish = true, canVideo = true, broadcastMode = false, studio = false, myHand = false, viewers = 1;
  var screenOn = false, schemeLoaded = false;
  var P = {};                 // 'me' | identity -> media { name,isLocal,videoTrack,camOff,screen,audioEl,speaking,micOff,hand,el }
  var parts = {};             // identity -> { name, local } (everyone in the room: gallery + participants list)
  var hands = {};             // identity -> name (raised hands)
  var page = 0, pinned = null, chatOpen = false, unread = 0;
  var lastChatId = 0, chatTimer = null, micOn = false, camOn = false, chatBtn = null, handBtn = null, micBtn = null, camBtn = null;
  var S = {};                 // studio DOM refs
  var G = {};                 // studio gallery tiles: key -> { el, vid, track, allow }
  var gpage = 0, stagePin = null, stageId = null, stageIsCam = false;
  var PER = Math.max(1, Math.min(12, parseInt((location.search.match(/[?&]per=(\d+)/) || [])[1] || '6', 10) || 6));

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function enc(o) { return new TextEncoder().encode(JSON.stringify(o)); }
  function q(s) { return root.querySelector(s); }
  function quiet(p) { if (p && typeof p.catch === 'function') p.catch(function () {}); }
  function setStatus(t) {
    if (statusEl) { statusEl.textContent = t; return; }
    if (!studio || !S.toast) return;
    S.toast.textContent = t; S.toast.classList.add('show');
    clearTimeout(S.toastT); S.toastT = setTimeout(function () { S.toast.classList.remove('show'); }, 3500);
  }
  function fail(msg) {
    root.innerHTML = '<div class="mt-err"><p>' + esc(msg) + '</p><button class="mt-btn" id="mtBack">Wapas</button></div>';
    var b = document.getElementById('mtBack'); if (b) b.onclick = goBack;
  }
  // Leave the meeting screen. The panel opens meetings in a new tab, where history.back() does
  // nothing, so fall back to a real page (back_url from meet.php, the app home otherwise).
  function goBack() {
    var to = (cfg && cfg.back_url) || '/app/';
    if (history.length > 1) {
      history.back();
      var t = setTimeout(function () { location.replace(to); }, 1500);   // back() did not navigate
      window.addEventListener('pagehide', function () { clearTimeout(t); }, { once: true });
    } else location.replace(to);
  }
  function leave() { try { room.disconnect(); } catch (e) { goBack(); } }

  function upsert(identity, name, isLocal) {
    if (!P[identity]) P[identity] = { name: name || identity, isLocal: !!isLocal, videoTrack: null, camOff: false, screen: null, el: null, speaking: false, micOff: false, hand: false };
    else if (name) P[identity].name = name;
    return P[identity];
  }
  function localId() { return room && room.localParticipant ? room.localParticipant.identity : ''; }
  function vidOf(p) { return p && p.videoTrack && !p.camOff ? p.videoTrack : null; }   // camera track that really shows a picture
  function refreshAll() { if (studio) { studioControls(); studioRender(); } else { renderBar(); render(); } }

  // ================= shared chat (persistent, DB-backed) =================
  function chatReq(method, extra, body) {
    var headers = { Accept: 'application/json' }, url;
    if (cfg.chat_url) { url = cfg.chat_url + (extra || ''); if (method === 'POST' && cfg.csrf) headers['X-CSRF-TOKEN'] = cfg.csrf; }
    else { url = '/api/app.php?a=meet_chat' + (extra || ''); var t = localStorage.getItem('bc_token'); if (t) headers.Authorization = 'Bearer ' + t; }
    var init = { method: method, headers: headers };
    if (body) { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
    return fetch(url, init).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
  }
  function renderMsg(m) {
    if (!m || m.id <= lastChatId) return; lastChatId = m.id;
    if (studio) {
      var d = document.createElement('div'); d.className = 'bcs-msg' + (m.mine ? ' mine' : '');
      d.innerHTML = '<span class="who' + (m.staff ? ' staff' : '') + '">' + esc(m.name) + '</span><span class="tm">' + esc(m.time || '') + '</span><div class="bd">' + esc(m.body) + '</div>';
      S.chatlog.appendChild(d); S.chatlog.scrollTop = S.chatlog.scrollHeight; return;
    }
    var e = document.createElement('div'); e.className = 'mt-msg' + (m.mine ? ' mine' : '') + (m.staff ? ' staff' : '');
    e.innerHTML = '<b>' + esc(m.name) + '</b> ' + esc(m.body) + '<i class="mt-t">' + esc(m.time || '') + '</i>';
    chatLog.appendChild(e); chatLog.scrollTop = chatLog.scrollHeight;
    if (!chatOpen && !m.mine) { unread++; paintChatBtn(); }
  }
  function chatPoll() { chatReq('GET', '&after=' + lastChatId).then(function (r) { if (r && r.ok && r.rows) r.rows.forEach(renderMsg); }); }
  function sendChat() {
    var input = studio ? S.chatin : chatIn, t = (input.value || '').trim(); if (!t) return; input.value = '';
    chatReq('POST', '&after=' + lastChatId, { body: t }).then(function (r) { if (r && r.ok && r.rows) r.rows.forEach(renderMsg); else setStatus('Message nahi gaya'); });
  }
  function startChat() { chatPoll(); chatTimer = setInterval(chatPoll, 3000); }
  function paintChatBtn() {
    if (!chatBtn) return; setBtn(chatBtn, '💬', 'Chat');
    if (unread) { var i = document.createElement('i'); i.textContent = unread; chatBtn.appendChild(i); }
  }
  function toggleChat() { chatOpen = !chatOpen; chatBox.classList.toggle('open', chatOpen); if (chatOpen) { unread = 0; paintChatBtn(); chatIn.focus(); } }

  // ================= shared hand-raise / promote =================
  function toggleHand() {
    if (!room) return; myHand = !myHand;
    if (P['me']) P['me'].hand = myHand;
    try { quiet(room.localParticipant.setAttributes({ hand: myHand ? '1' : '' })); } catch (e) {}
    try { quiet(room.localParticipant.publishData(enc({ t: 'hand', up: myHand, n: cfg.name || 'Main' }), { reliable: true })); } catch (e) {}
    if (studio) { studioControls(); studioRender(); } else { paintHand(); if (P['me']) updateTile('me'); render(); }
    if (myHand && broadcastMode && !canPublish) setStatus('✋ Haath uthaya – host bolne ki permission dega…');
  }
  function handRaised(identity, name, up) {
    if (!identity) return;
    if (up) hands[identity] = name || (parts[identity] && parts[identity].name) || 'Koi'; else delete hands[identity];
    if (P[identity]) { P[identity].hand = !!up; }
    if (studio) { studioParts(); studioGallery(); } else { if (P[identity]) updateTile(identity); updateHandsBadge(); renderTray(); }
  }
  function promote(identity, allow) {
    var headers = { 'Content-Type': 'application/json', Accept: 'application/json' }, url;
    if (cfg.promote_url) { url = cfg.promote_url; if (cfg.csrf) headers['X-CSRF-TOKEN'] = cfg.csrf; }
    else { url = '/api/app.php?a=meet_promote'; var t = localStorage.getItem('bc_token'); if (t) headers.Authorization = 'Bearer ' + t; }
    return fetch(url, { method: 'POST', headers: headers, body: JSON.stringify({ identity: identity, allow: allow }) })
      .then(function (r) { return r.json(); }).then(function (r) {
        if (r && r.ok) {
          try { quiet(room.localParticipant.publishData(enc({ t: 'promote', id: identity, allow: allow }), { reliable: true })); } catch (e) {}
          if (allow) { delete hands[identity]; if (P[identity]) P[identity].hand = false; }
          if (studio) { studioParts(); studioGallery(); } else { updateHandsBadge(); renderTray(); }
          setStatus(allow ? 'Bolne ki permission de di' : (broadcastMode ? 'Mic wapas le liya (camera chalu rahega)' : 'Wapas view-only'));
        } else setStatus('Permission badal nahi payi');
        return r;
      }).catch(function () { return { ok: false }; });
  }
  function setMuted(identity, muted) {
    if (!isHost) return;
    api('meet_mute', { identity: identity, muted: muted }).then(function (r) {
      if (!r || !r.ok) return; if (P[identity]) { P[identity].micOff = muted; if (studio) { studioParts(); studioGallery(); } else updateTile(identity); }
    });
  }
  function muteAll() {
    if (!isHost) return;
    api('meet_mute', { identity: '*', muted: true }).then(function (r) { setStatus(r && r.ok ? ((r.changed || 0) + ' logon ko mute kiya') : 'Mute nahi hua'); });
  }
  function api(action, body) {
    var h = { 'Content-Type': 'application/json', Accept: 'application/json' };
    var url = (cfg && cfg.mute_url) ? cfg.mute_url : '/api/app.php?a=' + action;
    if (cfg && cfg.csrf) h['X-CSRF-TOKEN'] = cfg.csrf; else { var t = localStorage.getItem('bc_token'); if (t) h.Authorization = 'Bearer ' + t; }
    return fetch(url, { method: 'POST', headers: h, body: JSON.stringify(body || {}) }).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
  }

  // ================= shared local media (best effort: a denied camera / mic never blocks the meeting) =================
  function SRC() { var LK = window.LivekitClient; return (LK && LK.Track && LK.Track.Source) ? LK.Track.Source : { Camera: 'camera', Microphone: 'microphone', ScreenShare: 'screen_share' }; }
  function localCamTrack() {
    var lp = room && room.localParticipant; if (!lp) return null;
    var pub = lp.getTrackPublication ? lp.getTrackPublication(SRC().Camera) : null;
    return pub && pub.track ? pub.track : null;
  }
  function camOptions() {
    var VP = (window.LivekitClient && window.LivekitClient.VideoPresets) || {};
    if (!studio) return [undefined, undefined];
    if (canPublish) return [VP.h540 ? { resolution: VP.h540.resolution } : undefined, undefined];   // host / speakers: sharp, simulcast
    // everyone else: a small, light picture for the gallery tile (keeps phone data and server load low)
    return [VP.h180 ? { resolution: VP.h180.resolution } : undefined, { simulcast: false, videoEncoding: { maxBitrate: 160000, maxFramerate: 15 } }];
  }
  function startCamera() {
    if (!room || mode !== 'video') return Promise.resolve(null);
    var o = camOptions();
    return room.localParticipant.setCameraEnabled(true, o[0], o[1]).then(function () {
      camOn = true; var me = upsert('me', cfg.name || 'आप', true); me.videoTrack = localCamTrack(); me.camOff = false; return me;
    }, function () { camOn = false; setStatus('📷 Camera nahi chala – aap phir bhi dekh aur sun sakte hain'); return null; });
  }
  function stopCamera() {
    camOn = false;
    return room.localParticipant.setCameraEnabled(false).then(null, function () {}).then(function () { if (P['me']) P['me'].videoTrack = null; });
  }
  function enableLocalMedia() {   // mic + camera for people who may speak
    upsert('me', cfg.name || 'आप', true);
    return room.localParticipant.setMicrophoneEnabled(true).then(function () { micOn = true; }, function () { micOn = false; setStatus('🎤 Mic nahi chala'); })
      .then(function () { if (P['me']) P['me'].micOff = !micOn; return mode === 'video' ? startCamera() : null; });
  }
  function toggleMic() {
    micOn = !micOn; quiet(room.localParticipant.setMicrophoneEnabled(micOn));
    if (P['me']) P['me'].micOff = !micOn;
    if (studio) { studioControls(); studioGallery(); studioParts(); } else paintMic();
  }
  function toggleCam() {
    (camOn ? stopCamera() : startCamera()).then(function () {
      if (studio) { studioControls(); studioRender(); return; }
      var v = P['me'] && P['me'].el && P['me'].el.querySelector('video'); if (v) v.dataset.att = ''; updateTile('me'); paintCam();
    });
    if (!studio) paintCam();
  }
  function toggleScreen() {
    if (!room || !canPublish) return; screenOn = !screenOn;
    room.localParticipant.setScreenShareEnabled(screenOn).then(function () { if (studio) studioControls(); }).catch(function () { screenOn = !screenOn; setStatus('Screen share nahi hua'); if (studio) studioControls(); });
  }
  // What may I publish? Reads the live LiveKit permission (changed by the host). null = unknown.
  function permFor(what) {
    var perm = room && room.localParticipant ? room.localParticipant.permissions : null;
    if (!perm) return null;
    if (!perm.canPublish) return false;
    var list = perm.canPublishSources || [];
    if (!list.length) return true;   // empty = every source
    var want = what === 'mic' ? [2, 'MICROPHONE', 'microphone'] : [1, 'CAMERA', 'camera'];
    return list.some(function (s) { return want.indexOf(s) >= 0; });
  }
  function unpublishMic() {
    var lp = room.localParticipant, pub = lp.getTrackPublication ? lp.getTrackPublication(SRC().Microphone) : null;
    if (pub && pub.track) { try { quiet(lp.unpublishTrack(pub.track)); } catch (e) {} }
  }
  function syncLocalPublish() {
    if (!room || !room.localParticipant) return;
    var mic = permFor('mic'), cam = permFor('cam');
    if (mic === null) mic = canPublish;
    if (cam === null) cam = canVideo || canPublish;
    if (mic === canPublish && cam === (canVideo || canPublish)) return;
    var promoted = mic && !canPublish, demoted = !mic && canPublish;
    canPublish = mic; canVideo = cam;
    if (promoted) {   // the host allowed me to speak: mic on (camera too if it was off)
      myHand = false; try { quiet(room.localParticipant.setAttributes({ hand: '' })); } catch (e) {}
      upsert('me', cfg.name || 'आप', true).hand = false;
      enableLocalMedia().then(refreshAll);
      setStatus('✅ Aap ab bol sakte hain');
    } else {   // mic taken back; the camera stays if still allowed
      micOn = false; quiet(room.localParticipant.setMicrophoneEnabled(false)); unpublishMic();
      if (P['me']) P['me'].micOff = true;
      if (!cam && camOn) quiet(stopCamera().then(refreshAll));
      if (!studio && !cam) delete P['me'];
      setStatus(cam ? '🔇 Mic band – aapka camera chalu hai' : '🔇 Aap wapas view-only hain');
    }
    refreshAll();
  }
  // Audio that arrived before my camera started may have been blocked; once the page uses the
  // camera the browser lets it play, so retry quietly before asking for a tap.
  function tryAutoAudio() {
    if (!room || room.canPlaybackAudio !== false) { paintAudioGate(); return; }
    var p; try { p = room.startAudio(); } catch (e) { p = null; }
    Promise.resolve(p).catch(function () {}).then(paintAudioGate);
  }
  // Browser blocked sound / video until a tap (autoplay rule): show one big button that starts it.
  function paintAudioGate() {
    if (!room) return;
    var blocked = room.canPlaybackAudio === false || room.canPlaybackVideo === false;
    var g = document.getElementById('mtGate');
    if (!blocked) { if (g) g.remove(); return; }
    if (g) return;
    g = document.createElement('button'); g.id = 'mtGate'; g.type = 'button'; g.className = 'mt-gate';
    g.textContent = '🔊 Awaaz aur video ke liye yahan tap karein';
    g.onclick = function () {
      var ps = [];
      try { ps.push(room.startAudio()); } catch (e) {}
      try { if (room.startVideo) ps.push(room.startVideo()); } catch (e) {}
      document.querySelectorAll('video, audio').forEach(function (m) { try { quiet(m.play()); } catch (e) {} });
      Promise.all(ps.map(function (p) { return Promise.resolve(p).catch(function () {}); })).then(function () { g.remove(); setTimeout(paintAudioGate, 300); });
    };
    document.body.appendChild(g);
  }

  // ================= SIMPLE meeting UI =================
  function build() {
    root = document.getElementById('meet') || document.body;
    root.innerHTML = '<div class="mt-top"><div class="mt-head"><div class="mt-title">Meeting</div><div class="mt-status">Connect ho raha hai…</div></div>' +
      '<div class="mt-live"></div><div class="mt-hands"></div><div class="mt-count">0</div></div>' +
      '<div class="mt-tray"></div><div class="mt-grid"></div><div class="mt-pager"></div>' +
      '<div class="mt-chat"><div class="mt-chat-log"></div><div class="mt-chat-in"><input type="text" maxlength="500" placeholder="Message likhein…"><button>Bhejein</button></div></div>' +
      '<div class="mt-bar"></div><div class="mt-audio" aria-hidden="true"></div>';
    grid = q('.mt-grid'); bar = q('.mt-bar'); pager = q('.mt-pager'); audioBox = q('.mt-audio'); statusEl = q('.mt-status');
    titleEl = q('.mt-title'); countEl = q('.mt-count'); handsEl = q('.mt-hands'); liveEl = q('.mt-live'); trayEl = q('.mt-tray');
    chatBox = q('.mt-chat'); chatLog = q('.mt-chat-log'); chatIn = q('.mt-chat-in input');
    q('.mt-chat-in button').onclick = sendChat; chatIn.onkeydown = function (e) { if (e.key === 'Enter') sendChat(); };
  }
  function makeTile(id) {
    var p = P[id], d = document.createElement('div'); d.className = 'mt-tile';
    d.innerHTML = '<video autoplay playsinline muted></video><div class="mt-ph">' + esc((p.name || '?').charAt(0).toUpperCase()) + '</div>' +
      '<div class="mt-name"><span class="mt-mic"></span><span class="mt-nm"></span></div><div class="mt-hand">✋</div>' +
      (isHost && !p.isLocal ? '<button class="mt-mod" title="Mute">🔇</button>' : '') + (isHost && broadcastMode && !p.isLocal ? '<button class="mt-demote" title="View-only">⬇️</button>' : '');
    d.onclick = function () { pinned = (pinned === id) ? null : id; render(); };
    var mod = d.querySelector('.mt-mod'); if (mod) mod.onclick = function (e) { e.stopPropagation(); setMuted(id, !P[id].micOff); };
    var dem = d.querySelector('.mt-demote'); if (dem) dem.onclick = function (e) { e.stopPropagation(); promote(id, false); };
    return d;
  }
  function updateTile(id) {
    var p = P[id]; if (!p || !p.el) return;
    p.el.querySelector('.mt-nm').textContent = p.name + (p.isLocal ? ' (aap)' : '');
    p.el.querySelector('.mt-mic').textContent = p.micOff ? '🔇 ' : '';
    p.el.classList.toggle('speaking', !!p.speaking); p.el.classList.toggle('raised', !!p.hand); p.el.classList.toggle('pinned', pinned === id);
    var v = p.el.querySelector('video'), tr = vidOf(p);
    if (tr) { if (v.dataset.att !== '1') { tr.attach(v); v.dataset.att = '1'; } p.el.classList.add('has-video'); } else p.el.classList.remove('has-video');
    var mod = p.el.querySelector('.mt-mod'); if (mod) mod.textContent = p.micOff ? '🔈' : '🔇';
  }
  function order() {
    return Object.keys(P).sort(function (a, b) { var pa = P[a], pb = P[b];
      if (!!pb.speaking !== !!pa.speaking) return pb.speaking ? 1 : -1; if (!!pb.hand !== !!pa.hand) return pb.hand ? 1 : -1;
      if (pa.isLocal !== pb.isLocal) return pa.isLocal ? -1 : 1; return String(pa.name).localeCompare(String(pb.name)); });
  }
  function render() {
    var ids = order(), vis;
    if (pinned && P[pinned]) {   // zoomed on one person: the hint is a real button that un-zooms
      vis = [pinned]; pager.innerHTML = '';
      var zb = document.createElement('button'); zb.type = 'button'; zb.className = 'mt-pg'; zb.textContent = '🔍 Zoom band karein';
      zb.onclick = function () { pinned = null; render(); }; pager.appendChild(zb);
    }
    else {
      var pages = Math.max(1, Math.ceil(ids.length / PER)); if (page > pages - 1) page = pages - 1;
      vis = ids.slice(page * PER, page * PER + PER); pager.innerHTML = '';
      if (pages > 1) { var mk = function (txt, to, dis) { var b = document.createElement('button'); b.className = 'mt-pg'; b.textContent = txt; b.disabled = !!dis; b.onclick = function () { page = to; render(); }; pager.appendChild(b); };
        mk('‹ Pichhle', page - 1, page === 0); var lbl = document.createElement('span'); lbl.className = 'mt-pglbl'; lbl.textContent = (page + 1) + ' / ' + pages; pager.appendChild(lbl); mk('Aage ›', page + 1, page >= pages - 1); }
    }
    ids.forEach(function (id) { var p = P[id]; if (vis.indexOf(id) < 0 && p.el && p.el.parentNode) { var v = p.el.querySelector('video'); if (p.videoTrack && v && v.dataset.att === '1') { try { p.videoTrack.detach(v); } catch (e) {} v.dataset.att = ''; } p.el.remove(); } });
    vis.forEach(function (id) { if (!P[id].el) P[id].el = makeTile(id); grid.appendChild(P[id].el); updateTile(id); });
    if (!ids.length) grid.innerHTML = '<div class="mt-wait">' + (broadcastMode ? 'Host connect ho raha hai… 📢' : 'Koi aur nahi juda.') + '</div>';
    grid.classList.toggle('zoomed', !!pinned);
    grid.style.gridTemplateColumns = 'repeat(' + (pinned ? 1 : (vis.length <= 1 ? 1 : (vis.length <= 4 ? 2 : 3))) + ', 1fr)';
    countEl.textContent = '👥 ' + ids.length; updateHandsBadge();
  }
  function updateHandsBadge() { if (!handsEl) return; var ids = Object.keys(hands); handsEl.textContent = ids.length ? '✋ ' + ids.length : ''; handsEl.title = ids.map(function (i) { return hands[i]; }).join(', '); }
  function renderTray() {
    if (!trayEl) return; var ids = Object.keys(hands);
    if (!isHost || !broadcastMode || !ids.length) { trayEl.className = 'mt-tray'; trayEl.innerHTML = ''; return; }
    trayEl.className = 'mt-tray open'; trayEl.innerHTML = '<div class="mt-tray-h">✋ Bolna chahte hain (' + ids.length + ')</div>';
    ids.forEach(function (id) { var r = document.createElement('div'); r.className = 'mt-tray-row'; var s = document.createElement('span'); s.textContent = hands[id]; r.appendChild(s); var b = document.createElement('button'); b.textContent = '🎤 Allow'; b.onclick = function () { promote(id, true); }; r.appendChild(b); trayEl.appendChild(r); });
  }
  function drop(identity) { var p = P[identity]; if (!p) return; if (p.el && p.el.parentNode) p.el.remove(); if (p.audioEl) p.audioEl.remove(); if (pinned === identity) pinned = null; delete P[identity]; }
  // Bottom bar buttons: icon + short caption. state 'off' = red tint (mic/camera off), 'on' = amber (hand raised).
  function setBtn(b, icon, label, state) {
    if (!b) return;
    b.innerHTML = '<b class="ic">' + icon + '</b><span>' + esc(label) + '</span>';
    b.title = label; b.setAttribute('aria-label', label);
    b.classList.toggle('off', state === 'off'); b.classList.toggle('on', state === 'on');
  }
  function btn(icon, label, onClick, cls) {
    var b = document.createElement('button'); b.type = 'button'; b.className = 'mt-btn' + (cls ? ' ' + cls : '');
    b.onclick = onClick; setBtn(b, icon, label); bar.appendChild(b); return b;
  }
  function paintMic() { setBtn(micBtn, micOn ? '🎤' : '🔇', 'Mic', micOn ? '' : 'off'); }
  function paintCam() { setBtn(camBtn, '📷', 'Camera', camOn ? '' : 'off'); }
  function paintHand() { setBtn(handBtn, '✋', 'Haath', myHand ? 'on' : ''); }
  function renderBar() {
    bar.innerHTML = ''; micBtn = camBtn = null;
    if (canPublish) {
      micBtn = btn('🎤', 'Mic', toggleMic, 'mic-btn'); paintMic();
      if (mode === 'video') { camBtn = btn('📷', 'Camera', toggleCam, 'cam-btn'); paintCam(); }
    }
    handBtn = btn('✋', 'Haath', toggleHand, 'hand'); paintHand();
    chatBtn = btn('💬', 'Chat', toggleChat, 'chat'); paintChatBtn();
    if (isHost) btn('🔇', 'Sab mute', muteAll, 'muteall');
    btn('📴', 'Leave', leave, 'red');
  }

  // ================= STUDIO (broadcast = group video call) UI =================
  var HMON = ['जनवरी', 'फ़रवरी', 'मार्च', 'अप्रैल', 'मई', 'जून', 'जुलाई', 'अगस्त', 'सितंबर', 'अक्टूबर', 'नवंबर', 'दिसंबर'];
  function t12(d) { var h = d.getHours(), m = String(d.getMinutes()).padStart(2, '0'); return (((h + 11) % 12) + 1) + ':' + m + ' ' + (h >= 12 ? 'PM' : 'AM'); }
  function clockTick() { if (!S.date) return; var d = new Date(); S.date.textContent = d.getDate() + ' ' + HMON[d.getMonth()] + ' ' + d.getFullYear(); S.time.textContent = t12(d); }
  function hostKey() { var hid = cfg && cfg.host_identity; if (!hid) return null; return hid === localId() ? 'me' : hid; }
  function nameOf(k) {
    if (k === 'me') return (P.me && P.me.name) || cfg.name || 'आप';
    return (P[k] && P[k].name) || (parts[k] && parts[k].name) || k;
  }
  function inRoom(k) { return k === 'me' ? !!room : !!(P[k] || parts[k]); }
  function hostLine() { return (cfg.host_name || 'Host') + (cfg.host_region && cfg.host_role ? ' · ' + cfg.host_role : ''); }
  function buildStudio() {
    root = document.getElementById('meet') || document.body;
    var brand = cfg.brand || 'BC Monitoring App', bank = cfg.bank || 'Central Bank of India';
    var title = cfg.title || 'BC समन्वय बैठक', sub = cfg.subtitle || 'APY, PMJJBY, PMSBY एवं PMJDY की प्रगति समीक्षा';
    var ro = cfg.host_region || '', who = (cfg.host_name || 'Host') + (cfg.host_role ? ' (' + cfg.host_role + ')' : '');
    root.innerHTML = '<div class="bcs">' +
      '<header class="bcs-top"><div class="bcs-brand"><div class="bcs-logo">' + (cfg.logo ? '<img src="' + esc(cfg.logo) + '" alt="">' : 'CBI') + '</div><div class="bcs-brand-t"><b>' + esc(brand) + '</b><span>' + esc(bank) + '</span></div></div>' +
      '<div class="bcs-live">LIVE</div><div class="bcs-ttl"><b>' + esc(title) + '</b><span>' + esc((ro ? ro + ' · ' : '') + sub) + '</span></div>' +
      '<div class="bcs-clock"><b class="bcs-date"></b><span class="bcs-time"></span></div></header>' +
      '<div class="bcs-ticker bcs-ticker-top"><span class="bcs-ticker-tag">● सुर्खियाँ</span><div class="bcs-ticker-wrap"><div class="bcs-ticker-move bcs-ticker-top-move"></div></div></div>' +
      '<div class="bcs-body"><div class="bcs-main">' +
        '<main class="bcs-stage"><video class="bcs-stagevid" autoplay playsinline muted></video>' +
          '<div class="bcs-stage-ph"><div class="av">●</div><div class="nm"></div><div class="sub">Host connect ho raha hai…</div></div>' +
          '<div class="bcs-pin"></div><div class="bcs-toast"></div>' +
          '<div class="bcs-lt"><span class="bcs-lt-live">LIVE</span><span class="bcs-lt-role"></span><span class="bcs-lt-main"></span></div>' +
          '<div class="bcs-ticker"><span class="bcs-ticker-tag">सूचना</span><div class="bcs-ticker-wrap"><div class="bcs-ticker-move bcs-ticker-bottom-move"></div></div></div></main>' +
        '<section class="bcs-gallery" aria-label="सभी की वीडियो"><div class="bcs-grid"></div><div class="bcs-gpager"></div></section>' +
        '<div class="bcs-scheme"></div></div>' +
      '<aside class="bcs-side">' +
        '<div class="bcs-card bcs-info"><h4>📋 मीटिंग जानकारी</h4><ul><li><b>विषय</b><span>' + esc(title) + '</span></li>' +
          (ro ? '<li><b>RO</b><span>' + esc(ro) + '</span></li>' : '') +
          '<li><b>समय</b><span class="bcs-itime"></span></li><li><b>आयोजक</b><span>' + esc(who) + '</span></li></ul></div>' +
        '<div class="bcs-card bcs-plist"><h4>👥 प्रतिभागी (<span class="bcs-pc">0</span>)</h4><div class="bcs-parts"></div></div>' +
        '<div class="bcs-card bcs-chat"><h4>💬 चैट</h4><div class="bcs-chatlog"></div><div class="bcs-chatin"><input type="text" maxlength="500" placeholder="संदेश लिखें…"><button>➤</button></div></div></aside></div>' +
      '<footer class="bcs-controls"></footer></div>';
    S.main = q('.bcs-main'); S.stage = q('.bcs-stage'); S.vid = q('.bcs-stagevid'); S.ph = q('.bcs-stage-ph');
    S.phName = S.ph.querySelector('.nm'); S.phSub = S.ph.querySelector('.sub'); S.phAv = S.ph.querySelector('.av');
    S.pin = q('.bcs-pin'); S.toast = q('.bcs-toast'); S.ltRole = q('.bcs-lt-role'); S.ltMain = q('.bcs-lt-main');
    S.gallery = q('.bcs-gallery'); S.grid = q('.bcs-grid'); S.gpager = q('.bcs-gpager');
    S.parts = q('.bcs-parts'); S.pc = q('.bcs-pc'); S.chatlog = q('.bcs-chatlog'); S.chatin = q('.bcs-chatin input');
    S.date = q('.bcs-date'); S.time = q('.bcs-time'); S.itime = q('.bcs-itime');
    S.controls = q('.bcs-controls'); S.scheme = q('.bcs-scheme'); S.side = q('.bcs-side');
    q('.bcs-chatin button').onclick = sendChat; S.chatin.onkeydown = function (e) { if (e.key === 'Enter') sendChat(); };
    S.stage.onclick = function () { if (stagePin) { stagePin = null; studioRender(); } };   // tap the stage = back to the host
    S.ltRole.textContent = ro || cfg.host_role || 'Host'; S.ltMain.textContent = hostLine();
    var notice = cfg.ticker || 'सभी BC साथी अपने-अपने क्षेत्र में योजनाओं की प्रगति रिपोर्ट समय पर अपडेट करें';
    q('.bcs-ticker-bottom-move').innerHTML = esc(notice) + ' &nbsp;&nbsp; <b>APY</b> • <b>PMJJBY</b> • <b>PMSBY</b> • <b>PMJDY</b> &nbsp;&nbsp; ' + esc(notice);
    var head = cfg.headlines || ('🔴 LIVE: ' + (ro ? ro + ' – ' : '') + title + '  •  ' + sub + '  •  ' + (ro ? ro + ' के ' : '') + 'सभी प्रतिभागी कैमरा चालू रखें और अपने क्षेत्र के आँकड़े तैयार रखें');
    q('.bcs-ticker-top-move').innerHTML = esc(head) + ' &nbsp;&nbsp; • &nbsp;&nbsp; ' + esc(head);
    if (S.itime) S.itime.textContent = t12(new Date());
    clockTick(); setInterval(clockTick, 20000);
    window.addEventListener('resize', function () { fitControls(); studioGallery(); });
  }

  // ----- stage: the host big (screen share > pinned > host camera > a speaker > host avatar > anyone) -----
  function pickStage() {
    var ids = Object.keys(P), h = hostKey();
    var ss = ids.find(function (id) { return P[id].screen; }); if (ss) return ss;
    if (stagePin && inRoom(stagePin)) return stagePin;
    if (h && vidOf(P[h])) return h;
    var sp = ids.find(function (id) { return id !== 'me' && P[id].speaking && P[id].audioEl && vidOf(P[id]); }); if (sp) return sp;
    if (h && inRoom(h)) return h;
    // host not here: a moderator / allowed speaker (has a mic) may hold the stage; plain viewers stay in the gallery
    var v = ids.find(function (id) { return id !== 'me' && P[id].audioEl && vidOf(P[id]); }); if (v) return v;
    if (P.me && (h === 'me' || (!h && canPublish))) return 'me';   // I am the host
    return null;                                                    // "Host connect ho raha hai…"
  }
  function studioStage() {
    var id = pickStage(), p = id ? P[id] : null, tr = p ? (p.screen || vidOf(p)) : null;
    stageId = id; stageIsCam = !!tr && !p.screen;
    if (tr !== S.stageTrack) {   // detach the old picture first, or its stream keeps coming at full size
      if (S.stageTrack) { try { S.stageTrack.detach(S.vid); } catch (e) {} }
      if (tr) { try { tr.attach(S.vid); } catch (e) {} }
      S.stageTrack = tr || null;
    }
    S.vid.classList.toggle('on', !!tr);
    S.vid.classList.toggle('mirror', !!tr && id === 'me' && stageIsCam);
    S.ph.style.display = tr ? 'none' : 'flex';
    var isH = !id || id === hostKey();
    if (!tr) {
      S.phAv.textContent = id ? (nameOf(id) || '?').charAt(0).toUpperCase() : '●';
      S.phName.textContent = id ? nameOf(id) : '';
      S.phSub.textContent = !id ? 'Host connect ho raha hai…' : (isH && cfg.host_region ? cfg.host_region + ' · camera band hai' : 'camera band hai');
    }
    if (isH) { S.ltRole.textContent = cfg.host_region || cfg.host_role || 'Host'; S.ltMain.textContent = hostLine(); }
    else { S.ltRole.textContent = p && p.screen ? '🖥️ Screen' : (stagePin === id ? '📌 Pin' : (p && p.speaking ? '🎤 बोल रहे हैं' : '👤')); S.ltMain.textContent = nameOf(id); }
    S.pin.textContent = stagePin && stagePin === id ? '📌 ' + nameOf(id) + ' · tap karke host par wapas' : '';
    S.pin.style.display = S.pin.textContent ? 'block' : 'none';
  }

  // ----- gallery: everyone's camera, like a video call (paged; only visible tiles use data) -----
  function gPer() { return 6; }   // phone: 3 x 2 grid, desktop: one filmstrip row of 6
  function gKeys() {
    var lid = localId(), keys = room ? ['me'] : [], h = hostKey();
    Object.keys(parts).forEach(function (id) { if (id !== lid) keys.push(id); });
    if (stageIsCam && stageId) keys = keys.filter(function (k) { return k !== stageId; });
    function rank(k) { var p = P[k]; if (k === 'me') return 0; if (k === h) return 1; if (hands[k]) return 2; if (p && p.audioEl) return 3; if (vidOf(p)) return 4; return 5; }
    keys.sort(function (a, b) { return (rank(a) - rank(b)) || String(nameOf(a)).localeCompare(String(nameOf(b))); });
    return keys;
  }
  function makeGTile(k) {
    var el = document.createElement('div'); el.className = 'bcs-tile';
    el.innerHTML = '<video autoplay playsinline muted></video><div class="av"></div><div class="nm"><span class="mic"></span><span class="tx"></span></div><div class="hand">✋</div>';
    var t = { el: el, vid: el.querySelector('video'), track: null, allow: null };
    if (isHost && k !== 'me') {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'allow'; b.textContent = '🎤 Allow';
      b.onclick = function (e) { e.stopPropagation(); promote(k, true); }; el.appendChild(b); t.allow = b;
    }
    el.onclick = function () { stagePin = (stagePin === k) ? null : k; studioRender(); };   // tap = show this person big
    G[k] = t; return t;
  }
  function dropGTile(k) { var t = G[k]; if (!t) return; if (t.track) { try { t.track.detach(t.vid); } catch (e) {} } t.el.remove(); delete G[k]; }
  function paintGTile(k) {
    var t = G[k], p = P[k], tr = vidOf(p), me = k === 'me', nm = nameOf(k);
    if (t.track !== tr) { if (t.track) { try { t.track.detach(t.vid); } catch (e) {} } if (tr) { try { tr.attach(t.vid); } catch (e) {} } t.track = tr; }
    var talking = me ? (canPublish && micOn) : !!(p && p.audioEl && !p.micOff), raised = me ? myHand : !!hands[k];
    t.el.classList.toggle('has-video', !!tr); t.el.classList.toggle('me', me);
    t.el.classList.toggle('speaking', !!(p && p.speaking && talking)); t.el.classList.toggle('raised', raised);
    t.el.classList.toggle('host', k === hostKey()); t.el.classList.toggle('pinned', stagePin === k);
    t.el.querySelector('.av').textContent = (nm || '?').charAt(0).toUpperCase();
    t.el.querySelector('.tx').textContent = nm + (me ? ' (आप)' : '');
    t.el.querySelector('.mic').textContent = talking ? '' : '🔇 ';
    if (t.allow) t.allow.style.display = raised ? 'block' : 'none';
  }
  function studioGallery() {
    if (!S.grid) return;
    var keys = gKeys(), per = gPer(), pages = Math.max(1, Math.ceil(keys.length / per));
    if (gpage > pages - 1) gpage = pages - 1; if (gpage < 0) gpage = 0;
    var vis = keys.slice(gpage * per, gpage * per + per);
    Object.keys(G).forEach(function (k) { if (vis.indexOf(k) < 0) dropGTile(k); });
    vis.forEach(function (k, i) { var t = G[k] || makeGTile(k); paintGTile(k); if (S.grid.children[i] !== t.el) S.grid.insertBefore(t.el, S.grid.children[i] || null); });
    S.gallery.classList.toggle('empty', !vis.length);
    var total = Object.keys(parts).length || (room ? 1 : 0);
    S.gpager.innerHTML = '<span class="cnt">👥 ' + total + ' जुड़े</span>';
    if (pages > 1) {
      var mk = function (txt, to, dis) { var b = document.createElement('button'); b.type = 'button'; b.textContent = txt; b.disabled = !!dis; b.onclick = function () { gpage = to; studioGallery(); }; S.gpager.appendChild(b); };
      mk('‹', gpage - 1, gpage === 0);
      var l = document.createElement('span'); l.textContent = (gpage + 1) + ' / ' + pages; S.gpager.appendChild(l);
      mk('›', gpage + 1, gpage >= pages - 1);
    }
  }
  function studioParts() {
    if (!S.parts) return;
    var lid = localId(), hid = cfg.host_identity, ids = Object.keys(parts);
    if (S.pc) S.pc.textContent = ids.length;
    ids.sort(function (a, b) { return ((b === hid ? 1 : 0) - (a === hid ? 1 : 0)) || ((hands[b] ? 1 : 0) - (hands[a] ? 1 : 0)) || String(parts[a].name).localeCompare(String(parts[b].name)); });
    S.parts.innerHTML = '';
    ids.slice(0, 100).forEach(function (id) {
      var me = id === lid, p = me ? P.me : P[id], nm = me ? nameOf('me') : nameOf(id);
      var talking = me ? (canPublish && micOn) : !!(p && p.audioEl && !p.micOff), raised = me ? myHand : !!hands[id];
      var row = document.createElement('div'); row.className = 'bcs-prow';
      row.innerHTML = '<span class="pav">' + esc((nm || '?').charAt(0).toUpperCase()) + '</span><span class="pn">' + esc(nm) + (me ? ' (आप)' : '') +
        (id === hid ? ' <span class="host-b">HOST</span>' : '') + (raised ? ' ✋' : '') + '</span>';
      if (isHost && !me && raised) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'pallow'; b.textContent = 'Allow'; b.onclick = function () { promote(id, true); }; row.appendChild(b);
      } else {
        var mi = document.createElement('span'); mi.className = 'pm ' + (talking ? 'on' : 'off'); mi.textContent = (vidOf(p) ? '📹' : '') + (talking ? '🎤' : '🔇'); row.appendChild(mi);
        if (isHost && !me && broadcastMode && p && p.audioEl && id !== hid) {   // take the mic back (camera stays)
          var d = document.createElement('button'); d.type = 'button'; d.className = 'pdemote'; d.title = 'Mic wapas lein'; d.textContent = '⬇️'; d.onclick = function () { promote(id, false); }; row.appendChild(d);
        }
      }
      S.parts.appendChild(row);
    });
  }
  function studioRender() { if (!S.grid) return; studioStage(); studioGallery(); studioParts(); }
  function sbtn(ic, label, cls, fn) { var b = document.createElement('button'); b.type = 'button'; b.className = 'bcs-btn' + (cls ? ' ' + cls : ''); b.innerHTML = '<i>' + ic + '</i><span>' + esc(label) + '</span>'; b.title = label; b.onclick = fn; S.controls.appendChild(b); return b; }
  function studioControls() {
    if (!S.controls) return; S.controls.innerHTML = '';
    if (canPublish) sbtn(micOn ? '🎤' : '🔇', micOn ? 'Mute' : 'Unmute', micOn ? '' : 'off', toggleMic);
    if (mode === 'video' && (canPublish || canVideo)) sbtn(camOn ? '📹' : '📷', 'Camera', camOn ? '' : 'off', toggleCam);
    if (canPublish) sbtn('🖥️', 'Share', screenOn ? 'active' : '', toggleScreen);
    if (!canPublish) sbtn('✋', 'Haath', myHand ? 'active' : '', toggleHand);   // raise hand = ask to speak
    sbtn('💬', 'Chat', '', function () { S.side.classList.toggle('open'); });
    sbtn('👥', 'लोग', '', function () { S.side.classList.toggle('open'); });
    if (isHost) sbtn('🔇', 'All mute', '', muteAll);
    sbtn('📊', 'प्रगति', S.scheme.classList.contains('open') ? 'active' : '', toggleScheme);
    sbtn('📴', isHost ? 'End' : 'Leave', 'end', leave);
    fitControls();
  }
  // the bar may wrap to two rows on a phone; the side panel (chat / people) must end above it
  function fitControls() { if (S.controls) document.documentElement.style.setProperty('--bcs-ctl', S.controls.offsetHeight + 'px'); }
  function toggleScheme() { S.scheme.classList.toggle('open'); if (S.scheme.classList.contains('open') && !schemeLoaded) loadScheme(); studioControls(); }
  function loadScheme() {
    schemeLoaded = true;
    var headers = { Accept: 'application/json' }, url;
    if (cfg.summary_url) { url = cfg.summary_url; } else { url = '/api/app.php?a=meet_summary'; var t = localStorage.getItem('bc_token'); if (t) headers.Authorization = 'Bearer ' + t; }
    S.scheme.innerHTML = '<h4>📊 आज की प्रस्तुति</h4><p style="font-size:12px;color:#5b6b86">लोड हो रहा है…</p>';
    fetch(url, { headers: headers }).then(function (r) { return r.json(); }).then(function (r) {
      if (!r || !r.ok || !r.rows || !r.rows.length) { S.scheme.innerHTML = '<h4>📊 आज की प्रस्तुति</h4><p style="font-size:12px;color:#5b6b86">अभी डेटा उपलब्ध नहीं।</p>'; return; }
      var col = { apy: 'var(--s-apy)', pmjjby: 'var(--s-jjby)', pmsby: 'var(--s-sby)', pmjdy: 'var(--s-jdy)' };
      var h = '<h4>📊 योजना अनुसार लक्ष्य एवं उपलब्धि</h4><table><tr><th>योजना</th><th>लक्ष्य</th><th>उपलब्धि</th><th>%</th><th>प्रगति</th></tr>';
      r.rows.forEach(function (x) { h += '<tr><td>' + esc(x.label) + '</td><td>' + (x.target | 0) + '</td><td>' + (x.done | 0) + '</td><td>' + (x.pct | 0) + '%</td><td><div class="bar"><i style="width:' + Math.min(100, x.pct | 0) + '%;background:' + (col[x.key] || '#2563b8') + '"></i></div></td></tr>'; });
      h += '</table>'; S.scheme.innerHTML = h;
    }).catch(function () { S.scheme.innerHTML = '<h4>📊 आज की प्रस्तुति</h4><p style="font-size:12px;color:#5b6b86">लोड नहीं हुआ।</p>'; });
  }

  // ================= bootstrap =================
  function config() {
    if (window.MEET_CONFIG) return Promise.resolve(window.MEET_CONFIG);
    var t = localStorage.getItem('bc_token') || '';
    if (!t) return Promise.resolve({ ok: false, error: 'Pehle app mein login karein.' });
    return fetch('/api/app.php?a=meet_token', { headers: { Authorization: 'Bearer ' + t, Accept: 'application/json' } }).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'Internet nahi hai.' }; });
  }
  function start() {
    if (!window.LivekitClient) { root = document.getElementById('meet') || document.body; fail('Meeting library load nahi hui. Internet check karein.'); return; }
    config().then(function (c) {
      if (!c || !c.ok) { root = document.getElementById('meet') || document.body; fail((c && c.error) || 'Meeting join nahi ho payi.'); return; }
      cfg = c; mode = c.mode === 'voice' ? 'voice' : 'video'; isHost = !!c.is_host; broadcastMode = !!c.is_broadcast; studio = broadcastMode;
      canPublish = (c.can_publish !== false);
      canVideo = mode === 'video' && (c.can_video === undefined ? canPublish : !!c.can_video);
      if (studio) { buildStudio(); joinStudio(); }
      else { build(); titleEl.textContent = (c.host_region ? c.host_region + ' · ' : '') + (c.title || 'Meeting'); if (broadcastMode) liveEl.textContent = '🔴 LIVE'; join(); }
    });
  }

  function commonRoom() {
    var LK = window.LivekitClient;
    return new LK.Room({ adaptiveStream: true, dynacast: true, audioCaptureDefaults: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      videoCaptureDefaults: { resolution: LK.VideoPresets && LK.VideoPresets.h360 ? LK.VideoPresets.h360.resolution : undefined }, publishDefaults: { dtx: true, red: true, simulcast: true } });
  }
  function onData(payload, rp) {
    var m; try { m = JSON.parse(new TextDecoder().decode(payload)); } catch (e) { return; } if (!m) return;
    if (m.t === 'hand' && rp) handRaised(rp.identity, m.n || rp.name || rp.identity, !!m.up);
    else if (m.t === 'promote' && room.localParticipant && m.id === room.localParticipant.identity) { if (m.allow) setStatus('✅ permission mili'); setTimeout(syncLocalPublish, 400); }
  }

  function join() {
    var LK = window.LivekitClient; room = commonRoom();
    room.on(LK.RoomEvent.TrackSubscribed, function (track, pub, rp) {
      var p = upsert(rp.identity, rp.name || rp.identity, false);
      if (track.kind === 'video') { p.videoTrack = track; p.camOff = !!pub.isMuted; }
      else {
        var a = document.createElement('audio'); a.autoplay = true; track.attach(a); audioBox.appendChild(a); p.audioEl = a; p.micOff = !!pub.isMuted;
        if (hands[rp.identity]) { delete hands[rp.identity]; updateHandsBadge(); renderTray(); }   // they got the mic
      }
      render();
    });
    room.on(LK.RoomEvent.TrackUnsubscribed, function (track, pub, rp) {
      var p = P[rp.identity]; if (!p) return;
      if (track.kind === 'video') { var v = p.el && p.el.querySelector('video'); if (v && v.dataset.att === '1') { try { track.detach(v); } catch (e) {} v.dataset.att = ''; } p.videoTrack = null; }
      else if (p.audioEl) { try { track.detach(p.audioEl); } catch (e) {} p.audioEl.remove(); p.audioEl = null; }
      if (broadcastMode && !p.videoTrack && !p.audioEl && !p.isLocal) drop(rp.identity); render();
    });
    room.on(LK.RoomEvent.TrackMuted, function (pub, rp) { var p = P[rp.identity]; if (!p) return; if (pub.kind === 'audio') p.micOff = true; else p.camOff = true; updateTile(rp.identity); });
    room.on(LK.RoomEvent.TrackUnmuted, function (pub, rp) { var p = P[rp.identity]; if (!p) return; if (pub.kind === 'audio') p.micOff = false; else p.camOff = false; updateTile(rp.identity); });
    room.on(LK.RoomEvent.ParticipantConnected, function (rp) { if (broadcastMode) { viewers++; countEl.textContent = '👁 ' + viewers; } else { upsert(rp.identity, rp.name || rp.identity, false); setStatus('Connected'); render(); } });
    room.on(LK.RoomEvent.ParticipantDisconnected, function (rp) { if (hands[rp.identity]) { delete hands[rp.identity]; updateHandsBadge(); renderTray(); } drop(rp.identity); render(); });
    room.on(LK.RoomEvent.ActiveSpeakersChanged, function (sp) { var on = {}, me = localId(); sp.forEach(function (s) { on[s.identity] = true; }); Object.keys(P).forEach(function (id) { P[id].speaking = !!on[id === 'me' ? me : id]; }); render(); });
    if (LK.RoomEvent.ParticipantAttributesChanged) room.on(LK.RoomEvent.ParticipantAttributesChanged, function (ch, rp) { if (rp === room.localParticipant) return; if (ch && typeof ch.hand !== 'undefined') handRaised(rp.identity, rp.name || rp.identity, ch.hand === '1'); });
    if (LK.RoomEvent.ParticipantPermissionsChanged) room.on(LK.RoomEvent.ParticipantPermissionsChanged, function (pr, pt) { if (!pt || pt === room.localParticipant || pt.isLocal) syncLocalPublish(); });
    if (LK.RoomEvent.AudioPlaybackStatusChanged) room.on(LK.RoomEvent.AudioPlaybackStatusChanged, paintAudioGate);
    if (LK.RoomEvent.VideoPlaybackStatusChanged) room.on(LK.RoomEvent.VideoPlaybackStatusChanged, paintAudioGate);
    room.on(LK.RoomEvent.DataReceived, onData);
    room.on(LK.RoomEvent.Disconnected, function () { if (chatTimer) { clearInterval(chatTimer); chatTimer = null; } setStatus('Meeting khatam'); setTimeout(goBack, 1200); });
    room.connect(cfg.url, cfg.token).then(function () { viewers = (room.remoteParticipants ? room.remoteParticipants.size : 0) + 1; setStatus('Connected'); if (canPublish) { upsert('me', cfg.name || 'Main', true); return enableLocalMedia(); } return null; })
      .then(function () { renderBar(); render(); startChat(); tryAutoAudio(); }).catch(function (e) { fail('Join nahi ho payi: ' + (e && e.message ? e.message : 'camera/mic ya network problem')); });
  }

  function joinStudio() {
    var LK = window.LivekitClient; room = commonRoom();
    var SS = SRC().ScreenShare;
    room.on(LK.RoomEvent.TrackSubscribed, function (track, pub, rp) {
      var p = upsert(rp.identity, rp.name || rp.identity, false); if (!parts[rp.identity]) parts[rp.identity] = { name: p.name };
      if (track.kind === 'video') {
        if (pub.source === SS || track.source === SS) p.screen = track; else { p.videoTrack = track; p.camOff = !!pub.isMuted; }
      } else {
        var a = document.createElement('audio'); a.autoplay = true; track.attach(a); document.body.appendChild(a); p.audioEl = a; p.micOff = !!pub.isMuted;
        if (hands[rp.identity]) delete hands[rp.identity];   // they got the mic
      }
      studioRender();
    });
    room.on(LK.RoomEvent.TrackUnsubscribed, function (track, pub, rp) {
      var p = P[rp.identity]; if (!p) return;
      if (track.kind === 'video') { if (p.screen === track) p.screen = null; else if (p.videoTrack === track) p.videoTrack = null; }
      else if (p.audioEl) { try { track.detach(p.audioEl); } catch (e) {} p.audioEl.remove(); p.audioEl = null; }
      studioRender();
    });
    // a camera turned off arrives as "muted": show the avatar instead of a frozen picture
    room.on(LK.RoomEvent.TrackMuted, function (pub, rp) { var p = P[rp.identity]; if (!p) return; if (pub.kind === 'audio') p.micOff = true; else if (pub.source !== SS) p.camOff = true; studioRender(); });
    room.on(LK.RoomEvent.TrackUnmuted, function (pub, rp) { var p = P[rp.identity]; if (!p) return; if (pub.kind === 'audio') p.micOff = false; else if (pub.source !== SS) p.camOff = false; studioRender(); });
    room.on(LK.RoomEvent.ParticipantConnected, function (rp) { parts[rp.identity] = { name: rp.name || rp.identity }; studioRender(); });
    room.on(LK.RoomEvent.ParticipantDisconnected, function (rp) {
      var p = P[rp.identity]; if (p && p.audioEl) p.audioEl.remove();
      delete parts[rp.identity]; delete hands[rp.identity]; delete P[rp.identity]; if (stagePin === rp.identity) stagePin = null; studioRender();
    });
    room.on(LK.RoomEvent.ActiveSpeakersChanged, function (sp) { var on = {}, me = localId(); sp.forEach(function (s) { on[s.identity] = true; }); Object.keys(P).forEach(function (id) { P[id].speaking = !!on[id === 'me' ? me : id]; }); studioStage(); studioGallery(); });
    if (LK.RoomEvent.ParticipantAttributesChanged) room.on(LK.RoomEvent.ParticipantAttributesChanged, function (ch, rp) { if (rp === room.localParticipant) return; if (ch && typeof ch.hand !== 'undefined') handRaised(rp.identity, rp.name || rp.identity, ch.hand === '1'); });
    if (LK.RoomEvent.ParticipantPermissionsChanged) room.on(LK.RoomEvent.ParticipantPermissionsChanged, function (pr, pt) { if (!pt || pt === room.localParticipant || pt.isLocal) syncLocalPublish(); });
    if (LK.RoomEvent.AudioPlaybackStatusChanged) room.on(LK.RoomEvent.AudioPlaybackStatusChanged, function () { if (camOn || micOn) tryAutoAudio(); else paintAudioGate(); });
    if (LK.RoomEvent.VideoPlaybackStatusChanged) room.on(LK.RoomEvent.VideoPlaybackStatusChanged, paintAudioGate);
    room.on(LK.RoomEvent.DataReceived, onData);
    room.on(LK.RoomEvent.Disconnected, function () { if (chatTimer) { clearInterval(chatTimer); chatTimer = null; } setStatus('Broadcast samapt'); setTimeout(goBack, 1400); });
    room.connect(cfg.url, cfg.token).then(function () {
      parts[room.localParticipant.identity] = { name: cfg.name || 'आप', local: true };
      // people already in the room before I joined (ParticipantConnected only fires for later joiners)
      room.remoteParticipants.forEach(function (rp) { if (!parts[rp.identity]) parts[rp.identity] = { name: rp.name || rp.identity }; });
      upsert('me', cfg.name || 'आप', true);
      if (canPublish) return enableLocalMedia();   // host / moderators / allowed speakers: mic + camera
      if (canVideo) return startCamera();          // everyone else: camera on, like a video call
      return null;
    }).then(function () { studioControls(); studioRender(); startChat(); tryAutoAudio(); })
      .catch(function (e) { fail('Join nahi ho payi: ' + (e && e.message ? e.message : 'network problem')); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
