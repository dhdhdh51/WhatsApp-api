/* BC Monitoring App — group video / voice + LIVE broadcast (LiveKit).
   Used by /app/meet.html (app users) and /meet.php (panel staff).

   Normal meeting  -> simple tile grid (buildSimple/join).
   Broadcast        -> news-studio layout (buildStudio/joinStudio): Central Bank of India
                       branding, LIVE badge, host stage + lower-third + ticker, participant
                       tiles, meeting info, participants list, persistent chat, scheme panel.
   Broadcast audience is view-only (token canPublish=false); host can promote a raised hand. */
(function () {
  'use strict';
  var root, grid, bar, pager, audioBox, statusEl, titleEl, countEl, chatBox, chatLog, chatIn, handsEl, liveEl, trayEl;
  var room = null, cfg = null, isHost = false, mode = 'video';
  var canPublish = true, broadcastMode = false, studio = false, myHand = false, viewers = 1;
  var screenOn = false, schemeLoaded = false, stageKey = null;
  var P = {};                 // identity -> publisher tile { name,isLocal,videoTrack,screen,audioEl,speaking,micOff,hand,el }
  var parts = {};             // identity -> { name, host } (everyone, for the participant list)
  var hands = {};             // identity -> name (raised hands)
  var page = 0, pinned = null, chatOpen = false, unread = 0;
  var lastChatId = 0, chatTimer = null, micOn = false, camOn = false, chatBtn = null, handBtn = null, micBtn = null, camBtn = null;
  var S = {};                 // studio DOM refs
  var PER = Math.max(1, Math.min(12, parseInt((location.search.match(/[?&]per=(\d+)/) || [])[1] || '6', 10) || 6));

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function enc(o) { return new TextEncoder().encode(JSON.stringify(o)); }
  function q(s) { return root.querySelector(s); }
  function setStatus(t) { if (statusEl) statusEl.textContent = t; else if (studio && S.phSub && S.vid && !S.vid.classList.contains('on')) S.phSub.textContent = t; }
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
    if (!P[identity]) P[identity] = { name: name || identity, isLocal: !!isLocal, videoTrack: null, screen: null, el: null, speaking: false, micOff: false, hand: false };
    else if (name) P[identity].name = name;
    return P[identity];
  }

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
    try { room.localParticipant.setAttributes({ hand: myHand ? '1' : '' }); } catch (e) {}
    try { room.localParticipant.publishData(enc({ t: 'hand', up: myHand, n: cfg.name || 'Main' }), { reliable: true }); } catch (e) {}
    if (studio) { studioControls(); } else { paintHand(); if (P['me']) updateTile('me'); render(); }
    if (myHand && broadcastMode && !canPublish) setStatus('✋ Haath uthaya – host se permission ka intezaar…');
  }
  function handRaised(identity, name, up) {
    if (!identity) return;
    if (up) hands[identity] = name || (parts[identity] && parts[identity].name) || 'Koi'; else delete hands[identity];
    if (P[identity]) { P[identity].hand = !!up; }
    if (studio) studioParts(); else { if (P[identity]) updateTile(identity); updateHandsBadge(); renderTray(); }
  }
  function promote(identity, allow) {
    var headers = { 'Content-Type': 'application/json', Accept: 'application/json' }, url;
    if (cfg.promote_url) { url = cfg.promote_url; if (cfg.csrf) headers['X-CSRF-TOKEN'] = cfg.csrf; }
    else { url = '/api/app.php?a=meet_promote'; var t = localStorage.getItem('bc_token'); if (t) headers.Authorization = 'Bearer ' + t; }
    return fetch(url, { method: 'POST', headers: headers, body: JSON.stringify({ identity: identity, allow: allow }) })
      .then(function (r) { return r.json(); }).then(function (r) {
        if (r && r.ok) {
          try { room.localParticipant.publishData(enc({ t: 'promote', id: identity, allow: allow }), { reliable: true }); } catch (e) {}
          if (allow) { delete hands[identity]; if (P[identity]) P[identity].hand = false; }
          if (studio) studioParts(); else { updateHandsBadge(); renderTray(); }
          setStatus(allow ? 'Bolne ki permission de di' : 'Wapas view-only');
        } else setStatus('Permission badal nahi payi');
        return r;
      }).catch(function () { return { ok: false }; });
  }
  function setMuted(identity, muted) {
    if (!isHost) return;
    api('meet_mute', { identity: identity, muted: muted }).then(function (r) {
      if (!r || !r.ok) return; if (P[identity]) { P[identity].micOff = muted; if (studio) studioParts(); else updateTile(identity); }
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

  // ================= shared local media =================
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
  function toggleMic() { micOn = !micOn; room.localParticipant.setMicrophoneEnabled(micOn); if (studio) studioControls(); else paintMic(); }
  function toggleCam() {
    camOn = !camOn;
    room.localParticipant.setCameraEnabled(camOn).then(function () {
      var pubs = Array.from(room.localParticipant.videoTrackPublications.values());
      if (P['me']) P['me'].videoTrack = (camOn && pubs.length && pubs[0].track) ? pubs[0].track : null;
      if (studio) { studioStage(); studioTiles(); } else { var v = P['me'] && P['me'].el && P['me'].el.querySelector('video'); if (v) v.dataset.att = ''; updateTile('me'); }
    });
    if (!studio) paintCam();
  }
  function toggleScreen() {
    if (!room || !canPublish) return; screenOn = !screenOn;
    room.localParticipant.setScreenShareEnabled(screenOn).then(function () { if (studio) studioControls(); }).catch(function () { screenOn = !screenOn; setStatus('Screen share nahi hua'); if (studio) studioControls(); });
  }
  function syncLocalPublish() {
    if (!room || !room.localParticipant) return;
    var perm = room.localParticipant.permissions, allowed = perm ? !!perm.canPublish : canPublish;
    if (allowed === canPublish) return; canPublish = allowed;
    if (allowed) {
      upsert('me', cfg.name || 'आप', true); myHand = false; if (P['me']) P['me'].hand = false;
      try { room.localParticipant.setAttributes({ hand: '' }); } catch (e) {}
      enableLocalMedia().then(function () { if (studio) { studioStage(); studioTiles(); } else { updateTile('me'); render(); } });
      setStatus('✅ Aap ab bol sakte hain');
    } else {
      micOn = camOn = false;
      try { room.localParticipant.setMicrophoneEnabled(false); } catch (e) {}
      try { room.localParticipant.setCameraEnabled(false); } catch (e) {}
      delete P['me'];
      setStatus('🔇 Aap wapas view-only hain');
    }
    if (studio) { studioControls(); studioRender(); } else { renderBar(); render(); }
  }

  // ================= SIMPLE meeting UI (unchanged) =================
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
    var v = p.el.querySelector('video');
    if (p.videoTrack) { if (v.dataset.att !== '1') { p.videoTrack.attach(v); v.dataset.att = '1'; } p.el.classList.add('has-video'); } else p.el.classList.remove('has-video');
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

  // ================= STUDIO (broadcast) UI =================
  var HMON = ['जनवरी', 'फ़रवरी', 'मार्च', 'अप्रैल', 'मई', 'जून', 'जुलाई', 'अगस्त', 'सितंबर', 'अक्टूबर', 'नवंबर', 'दिसंबर'];
  function t12(d) { var h = d.getHours(), m = String(d.getMinutes()).padStart(2, '0'); return (((h + 11) % 12) + 1) + ':' + m + ' ' + (h >= 12 ? 'PM' : 'AM'); }
  function clockTick() { if (!S.date) return; var d = new Date(); S.date.textContent = d.getDate() + ' ' + HMON[d.getMonth()] + ' ' + d.getFullYear(); S.time.textContent = t12(d); }
  function buildStudio() {
    root = document.getElementById('meet') || document.body;
    var brand = cfg.brand || 'BC Monitoring App', bank = cfg.bank || 'Central Bank of India';
    var title = cfg.title || 'BC समन्वय बैठक', sub = cfg.subtitle || 'APY, PMJJBY, PMSBY एवं PMJDY की प्रगति समीक्षा';
    root.innerHTML = '<div class="bcs">' +
      '<header class="bcs-top"><div class="bcs-brand"><div class="bcs-logo">' + (cfg.logo ? '<img src="' + esc(cfg.logo) + '" alt="">' : 'CBI') + '</div><div><b>' + esc(brand) + '</b><span>' + esc(bank) + '</span></div></div>' +
      '<div class="bcs-live">LIVE</div><div class="bcs-ttl"><b>' + esc(title) + '</b><span>' + esc(sub) + '</span></div>' +
      '<div class="bcs-clock"><b class="bcs-date"></b><span class="bcs-time"></span></div></header>' +
      '<div class="bcs-ticker bcs-ticker-top"><span class="bcs-ticker-tag">● सुर्खियाँ</span><div class="bcs-ticker-wrap"><div class="bcs-ticker-move bcs-ticker-top-move"></div></div></div>' +
      '<div class="bcs-body"><main class="bcs-stage"><video class="bcs-stagevid" autoplay playsinline></video>' +
      '<div class="bcs-stage-ph"><div class="av">●</div><div class="nm"></div><div class="sub">Host connect ho raha hai…</div></div>' +
      '<div class="bcs-scheme"></div>' +
      '<div class="bcs-lt"><span class="bcs-lt-live">LIVE</span><span class="bcs-lt-role"></span><span class="bcs-lt-main">' + esc(title) + '</span></div>' +
      '<div class="bcs-ticker"><span class="bcs-ticker-tag">सूचना</span><div class="bcs-ticker-wrap"><div class="bcs-ticker-move"></div></div></div></main>' +
      '<aside class="bcs-side"><div class="bcs-tiles"></div>' +
      '<div class="bcs-card bcs-info"><h4>📋 मीटिंग जानकारी</h4><ul><li><b>विषय</b><span>' + esc(title) + '</span></li><li><b>समय</b><span class="bcs-itime"></span></li><li><b>आयोजक</b><span>' + esc(cfg.host_name || 'Host') + '</span></li></ul></div>' +
      '<div class="bcs-card bcs-plist"><h4>👥 प्रतिभागी (<span class="bcs-pc">0</span>)</h4><div class="bcs-parts"></div></div>' +
      '<div class="bcs-card bcs-chat"><h4>💬 चैट</h4><div class="bcs-chatlog"></div><div class="bcs-chatin"><input type="text" maxlength="500" placeholder="संदेश लिखें…"><button>➤</button></div></div></aside></div>' +
      '<footer class="bcs-controls"></footer></div>';
    S.stage = q('.bcs-stage'); S.vid = q('.bcs-stagevid'); S.ph = q('.bcs-stage-ph'); S.phName = S.ph.querySelector('.nm'); S.phSub = S.ph.querySelector('.sub'); S.phAv = S.ph.querySelector('.av');
    S.tiles = q('.bcs-tiles'); S.parts = q('.bcs-parts'); S.pc = q('.bcs-pc'); S.chatlog = q('.bcs-chatlog'); S.chatin = q('.bcs-chatin input');
    S.ltRole = q('.bcs-lt-role'); S.ticker = q('.bcs-ticker-move'); S.date = q('.bcs-date'); S.time = q('.bcs-time'); S.itime = q('.bcs-itime');
    S.controls = q('.bcs-controls'); S.scheme = q('.bcs-scheme'); S.side = q('.bcs-side');
    q('.bcs-chatin button').onclick = sendChat; S.chatin.onkeydown = function (e) { if (e.key === 'Enter') sendChat(); };
    S.ltRole.textContent = (cfg.host_role ? cfg.host_role + ', ' : '') + (cfg.host_name || 'Host');
    var notice = cfg.ticker || 'सभी BC साथी अपने-अपने क्षेत्र में योजनाओं की प्रगति रिपोर्ट समय पर अपडेट करें';
    S.ticker.innerHTML = esc(notice) + ' &nbsp;&nbsp; <b>APY</b> • <b>PMJJBY</b> • <b>PMSBY</b> • <b>PMJDY</b> &nbsp;&nbsp; ' + esc(notice);
    var topT = q('.bcs-ticker-top-move');
    if (topT) { var head = cfg.headlines || ('🔴 LIVE: ' + title + '  •  ' + sub + '  •  सभी प्रतिभागी कृपया समय पर जुड़ें और अपने क्षेत्र के आँकड़े तैयार रखें'); topT.innerHTML = esc(head) + ' &nbsp;&nbsp; • &nbsp;&nbsp; ' + esc(head); }
    if (S.itime) S.itime.textContent = t12(new Date());
    clockTick(); setInterval(clockTick, 20000);
    window.addEventListener('resize', fitControls);
  }
  function pickStage() {
    var ids = Object.keys(P);
    var ss = ids.find(function (id) { return P[id].screen; }); if (ss) return ss;
    var sp = ids.find(function (id) { return P[id].speaking && P[id].videoTrack; }); if (sp) return sp;
    var v = ids.find(function (id) { return P[id].videoTrack; }); if (v) return v;
    return ids[0] || null;
  }
  function studioStage() {
    var id = pickStage();
    if (!id) { S.vid.classList.remove('on'); S.ph.style.display = 'flex'; S.phName.textContent = ''; S.phSub.textContent = 'Host connect ho raha hai…'; stageKey = null; return; }
    var p = P[id], tr = p.screen || p.videoTrack;
    if (tr) { var key = id + (p.screen ? '_s' : '_v'); if (stageKey !== key) { try { tr.attach(S.vid); } catch (e) {} stageKey = key; } S.vid.classList.add('on'); S.ph.style.display = 'none'; }
    else { S.vid.classList.remove('on'); S.ph.style.display = 'flex'; S.phAv.textContent = (p.name || '?').charAt(0).toUpperCase(); S.phName.textContent = p.name; S.phSub.textContent = ''; stageKey = null; }
  }
  function studioTiles() {
    var ids = Object.keys(P); S.tiles.innerHTML = '';
    ids.slice(0, 6).forEach(function (id, i) {
      var p = P[id], t = document.createElement('div'); t.className = 'bcs-tile' + (i === 0 ? ' host' : '') + ((p.videoTrack || p.screen) ? ' has-video' : '') + (p.speaking ? ' speaking' : '');
      t.innerHTML = '<video autoplay playsinline muted></video><div class="av">' + esc((p.name || '?').charAt(0).toUpperCase()) + '</div><div class="nm">' + esc(p.name) + (p.isLocal ? ' (आप)' : '') + (p.host ? '' : '') + '</div>';
      var v = t.querySelector('video'), tr = p.videoTrack || p.screen; if (tr) { try { tr.attach(v); } catch (e) {} }
      S.tiles.appendChild(t);
    });
    if (ids.length > 6) { var m = document.createElement('div'); m.className = 'bcs-tile'; m.innerHTML = '<div class="more">+' + (ids.length - 6) + ' और</div>'; S.tiles.appendChild(m); }
  }
  function studioParts() {
    if (!S.parts) return; var ids = Object.keys(parts); if (S.pc) S.pc.textContent = ids.length;
    ids.sort(function (a, b) { return (parts[b].host ? 1 : 0) - (parts[a].host ? 1 : 0); });
    S.parts.innerHTML = '';
    ids.slice(0, 60).forEach(function (id) {
      var p = parts[id], pub = P[id], row = document.createElement('div'); row.className = 'bcs-prow';
      var micCls = pub && !pub.micOff ? 'on' : 'off', micIc = pub && !pub.micOff ? '🎤' : '🔇';
      row.innerHTML = '<span class="pav">' + esc((p.name || '?').charAt(0).toUpperCase()) + '</span><span class="pn">' + esc(p.name) + (p.host ? ' <span class="host-b">HOST</span>' : '') + (hands[id] ? ' ✋' : '') + '</span>';
      if (isHost && hands[id]) { var b = document.createElement('button'); b.textContent = 'Allow'; b.style.cssText = 'border:0;background:#16a34a;color:#fff;border-radius:999px;font-size:10px;font-weight:800;padding:3px 9px;flex:none'; b.onclick = function () { promote(id, true); }; row.appendChild(b); }
      else { var mi = document.createElement('span'); mi.className = 'pm ' + micCls; mi.textContent = micIc; row.appendChild(mi); }
      S.parts.appendChild(row);
    });
  }
  function studioRender() { studioStage(); studioTiles(); studioParts(); }
  function sbtn(ic, label, cls, fn) { var b = document.createElement('button'); b.className = 'bcs-btn' + (cls ? ' ' + cls : ''); b.innerHTML = '<i>' + ic + '</i><span>' + label + '</span>'; b.onclick = fn; S.controls.appendChild(b); return b; }
  function studioControls() {
    if (!S.controls) return; S.controls.innerHTML = '';
    if (canPublish) {
      sbtn(micOn ? '🎤' : '🔇', micOn ? 'Mute' : 'Unmute', micOn ? '' : 'off', toggleMic);
      if (mode === 'video') sbtn(camOn ? '📹' : '📷', 'Video', camOn ? '' : 'off', toggleCam);
      sbtn('🖥️', 'Share', screenOn ? 'active' : '', toggleScreen);
    }
    sbtn('✋', 'Hand', myHand ? 'active' : '', toggleHand);
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
      cfg = c; mode = c.mode === 'voice' ? 'voice' : 'video'; isHost = !!c.is_host; broadcastMode = !!c.is_broadcast; studio = broadcastMode; canPublish = (c.can_publish !== false);
      if (studio) { buildStudio(); joinStudio(); } else { build(); titleEl.textContent = c.title || 'Meeting'; if (broadcastMode) liveEl.textContent = '🔴 LIVE'; join(); }
    });
  }

  function commonRoom() {
    var LK = window.LivekitClient;
    return new LK.Room({ adaptiveStream: true, dynacast: true, audioCaptureDefaults: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      videoCaptureDefaults: { resolution: LK.VideoPresets && LK.VideoPresets.h360 ? LK.VideoPresets.h360.resolution : undefined }, publishDefaults: { dtx: true, red: true, simulcast: true } });
  }

  function join() {
    var LK = window.LivekitClient; room = commonRoom();
    room.on(LK.RoomEvent.TrackSubscribed, function (track, pub, rp) {
      var p = upsert(rp.identity, rp.name || rp.identity, false);
      if (track.kind === 'video') p.videoTrack = track;
      else { var a = document.createElement('audio'); a.autoplay = true; track.attach(a); audioBox.appendChild(a); p.audioEl = a; p.micOff = !!pub.isMuted; }
      if (hands[rp.identity]) { delete hands[rp.identity]; updateHandsBadge(); renderTray(); }
      render();
    });
    room.on(LK.RoomEvent.TrackUnsubscribed, function (track, pub, rp) {
      var p = P[rp.identity]; if (!p) return;
      if (track.kind === 'video') { var v = p.el && p.el.querySelector('video'); if (v && v.dataset.att === '1') { try { track.detach(v); } catch (e) {} v.dataset.att = ''; } p.videoTrack = null; }
      else if (p.audioEl) { try { track.detach(p.audioEl); } catch (e) {} p.audioEl.remove(); p.audioEl = null; }
      if (broadcastMode && !p.videoTrack && !p.audioEl && !p.isLocal) drop(rp.identity); render();
    });
    room.on(LK.RoomEvent.TrackMuted, function (pub, rp) { if (P[rp.identity] && pub.kind === 'audio') { P[rp.identity].micOff = true; updateTile(rp.identity); } });
    room.on(LK.RoomEvent.TrackUnmuted, function (pub, rp) { if (P[rp.identity] && pub.kind === 'audio') { P[rp.identity].micOff = false; updateTile(rp.identity); } });
    room.on(LK.RoomEvent.ParticipantConnected, function (rp) { if (broadcastMode) { viewers++; countEl.textContent = '👁 ' + viewers; } else { upsert(rp.identity, rp.name || rp.identity, false); setStatus('Connected'); render(); } });
    room.on(LK.RoomEvent.ParticipantDisconnected, function (rp) { if (hands[rp.identity]) { delete hands[rp.identity]; updateHandsBadge(); renderTray(); } drop(rp.identity); render(); });
    room.on(LK.RoomEvent.ActiveSpeakersChanged, function (sp) { var on = {}; sp.forEach(function (s) { on[s.identity] = true; }); Object.keys(P).forEach(function (id) { P[id].speaking = !!on[id]; }); render(); });
    if (LK.RoomEvent.ParticipantAttributesChanged) room.on(LK.RoomEvent.ParticipantAttributesChanged, function (ch, rp) { if (rp === room.localParticipant) return; if (ch && typeof ch.hand !== 'undefined') handRaised(rp.identity, rp.name || rp.identity, ch.hand === '1'); });
    if (LK.RoomEvent.ParticipantPermissionsChanged) room.on(LK.RoomEvent.ParticipantPermissionsChanged, function (pr, pt) { if (!pt || pt === room.localParticipant || pt.isLocal) syncLocalPublish(); });
    room.on(LK.RoomEvent.DataReceived, function (payload, rp) { var m; try { m = JSON.parse(new TextDecoder().decode(payload)); } catch (e) { return; } if (!m) return; if (m.t === 'hand' && rp) handRaised(rp.identity, m.n || rp.name || rp.identity, !!m.up); else if (m.t === 'promote' && room.localParticipant && m.id === room.localParticipant.identity) { if (m.allow) setStatus('✅ permission mili'); setTimeout(syncLocalPublish, 400); } });
    room.on(LK.RoomEvent.Disconnected, function () { if (chatTimer) { clearInterval(chatTimer); chatTimer = null; } setStatus('Meeting khatam'); setTimeout(goBack, 1200); });
    room.connect(cfg.url, cfg.token).then(function () { viewers = (room.remoteParticipants ? room.remoteParticipants.size : 0) + 1; setStatus('Connected'); if (canPublish) { upsert('me', cfg.name || 'Main', true); return enableLocalMedia(); } return null; })
      .then(function () { renderBar(); render(); startChat(); }).catch(function (e) { fail('Join nahi ho payi: ' + (e && e.message ? e.message : 'camera/mic ya network problem')); });
  }

  function joinStudio() {
    var LK = window.LivekitClient; room = commonRoom();
    var SRC = (LK.Track && LK.Track.Source) ? LK.Track.Source : {};
    room.on(LK.RoomEvent.TrackSubscribed, function (track, pub, rp) {
      var p = upsert(rp.identity, rp.name || rp.identity, false); if (!parts[rp.identity]) parts[rp.identity] = { name: p.name };
      if (track.kind === 'video') { if (pub.source === SRC.ScreenShare || track.source === SRC.ScreenShare) p.screen = track; else p.videoTrack = track; }
      else { var a = document.createElement('audio'); a.autoplay = true; track.attach(a); document.body.appendChild(a); p.audioEl = a; p.micOff = !!pub.isMuted; }
      if (hands[rp.identity]) delete hands[rp.identity];
      studioRender();
    });
    room.on(LK.RoomEvent.TrackUnsubscribed, function (track, pub, rp) {
      var p = P[rp.identity]; if (!p) return;
      if (track.kind === 'video') { if (p.screen && pub.source === SRC.ScreenShare) p.screen = null; else p.videoTrack = null; }
      else if (p.audioEl) { try { track.detach(p.audioEl); } catch (e) {} p.audioEl.remove(); p.audioEl = null; }
      if (!p.videoTrack && !p.screen && !p.audioEl && !p.isLocal) delete P[rp.identity];
      studioRender();
    });
    room.on(LK.RoomEvent.TrackMuted, function (pub, rp) { if (P[rp.identity] && pub.kind === 'audio') { P[rp.identity].micOff = true; studioParts(); } });
    room.on(LK.RoomEvent.TrackUnmuted, function (pub, rp) { if (P[rp.identity] && pub.kind === 'audio') { P[rp.identity].micOff = false; studioParts(); } });
    room.on(LK.RoomEvent.ParticipantConnected, function (rp) { parts[rp.identity] = { name: rp.name || rp.identity }; studioParts(); });
    room.on(LK.RoomEvent.ParticipantDisconnected, function (rp) { delete parts[rp.identity]; if (hands[rp.identity]) delete hands[rp.identity]; if (P[rp.identity]) delete P[rp.identity]; studioRender(); });
    room.on(LK.RoomEvent.ActiveSpeakersChanged, function (sp) { var on = {}; sp.forEach(function (s) { on[s.identity] = true; }); Object.keys(P).forEach(function (id) { P[id].speaking = !!on[id]; }); studioStage(); studioTiles(); });
    if (LK.RoomEvent.ParticipantAttributesChanged) room.on(LK.RoomEvent.ParticipantAttributesChanged, function (ch, rp) { if (rp === room.localParticipant) return; if (ch && typeof ch.hand !== 'undefined') handRaised(rp.identity, rp.name || rp.identity, ch.hand === '1'); });
    if (LK.RoomEvent.ParticipantPermissionsChanged) room.on(LK.RoomEvent.ParticipantPermissionsChanged, function (pr, pt) { if (!pt || pt === room.localParticipant || pt.isLocal) syncLocalPublish(); });
    room.on(LK.RoomEvent.DataReceived, function (payload, rp) { var m; try { m = JSON.parse(new TextDecoder().decode(payload)); } catch (e) { return; } if (!m) return; if (m.t === 'hand' && rp) handRaised(rp.identity, m.n || rp.name || rp.identity, !!m.up); else if (m.t === 'promote' && room.localParticipant && m.id === room.localParticipant.identity) { if (m.allow) setStatus('✅ permission mili'); setTimeout(syncLocalPublish, 400); } });
    room.on(LK.RoomEvent.Disconnected, function () { if (chatTimer) { clearInterval(chatTimer); chatTimer = null; } setStatus('Broadcast samapt'); setTimeout(goBack, 1400); });
    room.connect(cfg.url, cfg.token).then(function () {
      var me = room.localParticipant; parts[me.identity] = { name: cfg.name || 'आप', host: isHost };
      if (canPublish) { var p = upsert('me', cfg.name || 'आप', true); p.host = isHost; parts['me'] = { name: cfg.name || 'आप', host: isHost }; return enableLocalMedia(); }
      return null;
    }).then(function () { studioControls(); studioRender(); startChat(); }).catch(function (e) { fail('Join nahi ho payi: ' + (e && e.message ? e.message : 'network/camera problem')); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
