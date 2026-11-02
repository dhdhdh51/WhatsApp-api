/* BC Daily Report – mobile app UI (runs inside the Android WebView and in any browser). */
(function () {
  'use strict';
  var API = '/api/app.php';
  var $app = document.getElementById('app');
  var native = window.BCNative || null; // bridge provided by the Android app
  var state = { tab: 'home', today: null, chatTimer: null, lastChatId: 0 };

  function token() { return localStorage.getItem('bc_token') || ''; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmt(s) { return esc(s).replace(/\*([^*\n]+)\*/g, '<b>$1</b>'); }
  function pad(n) { n = parseInt(n || 0, 10); return (n < 10 ? '0' : '') + n; }
  function toast(t) { var el = document.getElementById('toast'); el.textContent = t; el.className = 'toast show'; setTimeout(function () { el.className = 'toast'; }, 2600); }
  function meetBanner(m) {
    if (!m || !m.url) return '';
    var bc = !!m.is_broadcast;
    var icon = bc ? '📢' : (m.mode === 'voice' ? '🎙️' : '📹');
    var sub = (m.region ? m.region + ' · ' : '') + (bc ? 'LIVE Broadcast – tap karke judein' : ((m.mode === 'voice' ? 'Voice' : 'Video') + ' meeting live hai – tap karke judein'));
    return '<a class="meet-banner' + (bc ? ' broadcast' : '') + '" target="_blank" rel="noopener" href="' + esc(m.url) + '">' +
      '<div class="mb-info"><div class="mb-title">' + icon + ' ' + esc(m.title || 'Meeting') + '</div>' +
      '<div class="mb-sub">' + sub + '</div></div><span class="mb-join">Join</span></a>';
  }
  function openExt(url) {
    var a = document.createElement('a'); a.href = url; a.target = '_blank'; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); a.remove();
  }
  function cbcStartMeeting(mode, scope, bcId, bcIds) {
    toast('Meeting shuru ho rahi hai…');
    api('start_meeting', { body: { mode: mode, scope: scope, bc_id: bcId || 0, bc_ids: bcIds || [] } }).then(function (j) {
      if (!j.ok) { toast(j.error || 'Error'); return; }
      toast(j.invited ? (j.invited + ' BC ko bulaya gaya') : 'Meeting shuru');
      openExt(j.url);
      setTimeout(function () { renderCbc('status'); }, 1200);
    });
  }

  /** Pick which BCs to invite, then start the meeting. */
  function renderInvite(mode) {
    stopChat();
    state.tab = ''; state.cbcTab = 'status';
    $app.innerHTML = top('Kise bulayein?', (mode === 'voice' ? 'Voice' : 'Video') + ' meeting') +
      '<div class="page"><div class="card muted">Load ho raha hai…</div></div>';
    api('status').then(function (d) {
      if (!d.ok) { $app.querySelector('.page').innerHTML = '<div class="err">' + esc(d.error || 'Error') + '</div>'; return; }
      var list = d.bcs || [];
      var h = '<div class="card"><div class="inv-head"><span><b id="invCount">' + list.length + '</b> BC chune gaye</span>' +
        '<button class="inv-toggle" id="invAll">Sabhi hatayein</button></div>';
      if (!list.length) h += '<p class="muted">Aapke paas koi active BC nahi hai.</p>';
      list.forEach(function (b) {
        h += '<label class="inv-row"><input type="checkbox" class="inv-cb" value="' + (b.id || 0) + '" checked>' +
          '<span class="inv-name">' + esc(b.name) + ' <span class="muted">' + esc(b.bc_code || '') + '</span>' +
          (b.branch ? '<br><span class="muted sm">' + esc(b.branch) + '</span>' : '') + '</span>' + statusChip(b.status) + '</label>';
      });
      h += '</div>';
      $app.querySelector('.page').innerHTML = h;
      var pg = $app.querySelector('.page');
      pg.classList.add('chatpage');
      var dock = document.createElement('div');
      dock.className = 'dock';
      dock.innerHTML = '<div class="inv-bar"><button class="btn light" id="invBack" style="margin:0">Wapas</button>' +
        '<button class="btn" id="invGo" style="margin:0">' + (mode === 'voice' ? '🎙️' : '📹') + ' Meeting shuru</button></div>';
      $app.appendChild(dock);

      function ids() { return Array.prototype.slice.call(pg.querySelectorAll('.inv-cb')).filter(function (c) { return c.checked; }).map(function (c) { return parseInt(c.value, 10); }); }
      function sync() { document.getElementById('invCount').textContent = ids().length; }
      Array.prototype.forEach.call(pg.querySelectorAll('.inv-cb'), function (c) { c.onchange = sync; });
      document.getElementById('invAll').onclick = function () {
        var anyOn = ids().length > 0;
        Array.prototype.forEach.call(pg.querySelectorAll('.inv-cb'), function (c) { c.checked = !anyOn; });
        this.textContent = anyOn ? 'Sabhi chunein' : 'Sabhi hatayein';
        sync();
      };
      document.getElementById('invBack').onclick = function () { dock.remove(); renderCbc('status'); };
      document.getElementById('invGo').onclick = function () {
        var sel = ids();
        if (!sel.length) { toast('Kam se kam ek BC chunein'); return; }
        dock.remove();
        cbcStartMeeting(mode, 'all', 0, sel.length === (d.bcs || []).length ? [] : sel);
      };
    });
  }
  function cbcEndMeeting() {
    api('end_meeting', { body: {} }).then(function () { toast('Meeting end ho gayi'); renderCbc('status'); });
  }
  var callWatch = null, meetWatch = null, meetSeen = null, meetTick = 0;
  function startCallWatch() {
    if (callWatch) return;
    callWatch = setInterval(function () {
      if (!token()) { clearInterval(callWatch); callWatch = null; return; }
      if (window.BCall && window.BCall.active()) return;
      api('call_incoming').then(function (r) {
        if (r && r.ok && r.call && window.BCall && !window.BCall.active()) window.BCall.incoming(r.call);
      });
      // every ~5th tick (20s): is a meeting live? ring once per meeting
      if (++meetTick % 5 === 0) checkMeeting();
    }, 4000);
    checkMeeting();
  }
  function checkMeeting() {
    if (document.querySelector('.meet-ring')) return;
    if (window.BCall && window.BCall.active()) return;
    api('meet_status').then(function (r) {
      var m = r && r.ok ? r.meeting : null;
      if (!m || !m.room) { meetSeen = null; return; }
      if (meetSeen === m.room) return;        // already offered this one
      meetSeen = m.room;
      ringMeeting(m);
    });
  }
  /** Full screen "meeting shuru hui" prompt with ringtone. */
  function ringMeeting(m) {
    var d = document.createElement('div');
    d.className = 'meet-ring';
    var bc = !!m.is_broadcast;
    d.innerHTML = '<div class="mr-box"><div class="mr-ic">' + (bc ? '📢' : (m.mode === 'voice' ? '🎙️' : '📹')) + '</div>' +
      '<div class="mr-t">' + esc(m.title || (bc ? 'Broadcast' : 'Meeting')) + '</div>' +
      (m.region ? '<div class="mr-s"><b>' + esc(m.region) + '</b></div>' : '') +
      '<div class="mr-s">' + (bc ? 'LIVE Broadcast shuru ho gaya hai – camera ke saath judein' : ((m.mode === 'voice' ? 'Voice' : 'Video') + ' meeting shuru ho gayi hai – judein')) + '</div>' +
      '<a class="btn" id="mrJoin" target="_blank" rel="noopener" href="' + esc(m.url) + '">Join karein</a>' +
      '<button class="btn light" id="mrLater">Baad mein</button></div>';
    document.body.appendChild(d);
    if (window.BCRing) window.BCRing.start();
    var close = function () { if (window.BCRing) window.BCRing.stop(); d.remove(); };
    document.getElementById('mrJoin').onclick = function () { close(); };
    document.getElementById('mrLater').onclick = close;
    setTimeout(function () { if (window.BCRing) window.BCRing.stop(); }, 30000);
  }

  function api(action, opts) {
    opts = opts || {};
    var url = API + '?a=' + action + (opts.qs || '');
    var init = { method: opts.body ? 'POST' : 'GET', headers: { 'Accept': 'application/json' } };
    if (token()) init.headers['Authorization'] = 'Bearer ' + token();
    if (opts.body) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
    return fetch(url, init).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Server error (' + r.status + ')' }; }).then(function (j) {
        if (r.status === 401 && action !== 'login') { logout(true); throw new Error(j.error || 'Login expired'); }
        return j;
      });
    }, function () { return { ok: false, error: 'Internet nahi hai. Dobara koshish karein.' }; });
  }

  function saveSession(tok, last) {
    localStorage.setItem('bc_token', tok);
    if (native && native.setSession) native.setSession(tok, location.origin, String(last || 0));
  }
  function logout(silent) {
    if (!silent && token()) api('logout', { body: {} });
    localStorage.removeItem('bc_token');
    if (native && native.clearSession) native.clearSession();
    stopChat();
    renderLogin(silent ? 'Login expired. Dobara login karein.' : '');
  }

  // ------------------------------------------------------------------ login
  function renderLogin(msg) {
    $app.innerHTML =
      '<div class="login"><div class="logo">BC</div><h1>BC Daily Report</h1>' +
      (msg ? '<div class="err">' + esc(msg) + '</div>' : '') +
      '<div class="field mb"><label>Mobile number</label><input id="ph" type="tel" inputmode="numeric" maxlength="13" placeholder="98765 43210" autocomplete="username"></div>' +
      '<div class="field mb"><label>Password</label><input id="pw" type="password" autocomplete="current-password"></div>' +
      '<button class="btn" id="go">Login</button>' +
      '<p class="muted" style="text-align:center;margin-top:18px">Password nahi pata? Apne CBC / Admin se poochhein.</p></div>';
    var go = document.getElementById('go');
    go.onclick = function () {
      go.disabled = true;
      api('login', { body: { phone: document.getElementById('ph').value, password: document.getElementById('pw').value, device: navigator.userAgent.slice(0, 255) } })
        .then(function (j) {
          go.disabled = false;
          if (!j.ok) { renderLogin(j.error); return; }
          saveSession(j.token, j.last_notification);
          startCallWatch();
          if (j.type === 'cbc') { staffHome(j.role_key); return; }
          if (j.must_change) renderPassword(true); else show('home');
        });
    };
  }

  function renderPassword(forced) {
    stopChat();
    $app.innerHTML = top(forced ? 'Naya password banayein' : 'Password badlein') + '<div class="page"><div class="card">' +
      (forced ? '<div class="warn">Suraksha ke liye pehli baar apna naya password banayein.</div>' : '') +
      '<div id="pm"></div>' +
      '<div class="field mb"><label>Purana password</label><input id="c" type="password"></div>' +
      '<div class="field mb"><label>Naya password (kam se kam 6 akshar)</label><input id="n" type="password"></div>' +
      '<div class="field mb"><label>Naya password dobara</label><input id="n2" type="password"></div>' +
      '<button class="btn" id="sv">Save</button>' + (forced ? '' : '<button class="btn light" id="bk">Wapas</button>') + '</div></div>';
    if (!forced) document.getElementById('bk').onclick = function () { show('more'); };
    document.getElementById('sv').onclick = function () {
      var n = document.getElementById('n').value;
      if (n !== document.getElementById('n2').value) { document.getElementById('pm').innerHTML = '<div class="err">Dono naye password ek jaise nahi hain.</div>'; return; }
      api('password', { body: { current: document.getElementById('c').value, new: n } }).then(function (j) {
        if (!j.ok) { document.getElementById('pm').innerHTML = '<div class="err">' + esc(j.error) + '</div>'; return; }
        toast('Password badal gaya ✅'); show('home');
      });
    };
  }

  // ------------------------------------------------------------------ layout
  function top(title, sub) { return '<div class="top"><div style="flex:1"><h1>' + esc(title) + '</h1>' + (sub ? '<div class="sub">' + esc(sub) + '</div>' : '') + '</div></div>'; }
  function tabs() {
    var t = state.tab, u = state.today && state.today.chat_unread ? '<span class="dot">' + state.today.chat_unread + '</span>' : '';
    function b(k, i, l, extra) { return '<button data-tab="' + k + '" class="' + (t === k ? 'on' : '') + '"><b>' + i + '</b>' + l + (extra || '') + '</button>'; }
    return '<div class="tabs">' + b('home', 'R', 'Report') + b('history', 'H', 'History') + b('chat', 'C', 'Chat', u) + b('notices', 'S', 'Suchna') + b('more', 'M', 'More') + '</div>';
  }
  function bindTabs() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-tab]'), function (el) { el.onclick = function () { show(el.getAttribute('data-tab')); }; });
  }
  function show(tab) {
    state.tab = tab; stopChat();
    ({ home: renderHome, history: renderHistory, chat: renderChat, notices: renderNotices, more: renderMore })[tab]();
  }

  // ------------------------------------------------------------------ home / report
  function renderHome() {
    $app.innerHTML = top('Aaj ki report', '') + '<div class="page"><div class="card muted">Load ho raha hai…</div></div>' + tabs(); bindTabs();
    api('today').then(function (d) {
      if (!d.ok) { $app.querySelector('.page').innerHTML = '<div class="err">' + esc(d.error) + '</div>'; return; }
      state.today = d;
      if (d.report && native && native.setReportDone) native.setReportDone(d.date);
      var h = '';
      h += meetBanner(d.meeting);
      if (d.report) h += '<div class="ok">✅ Aaj ki report jama hai (' + esc(d.updated_at) + '). Badlav ke liye number badal kar dobara Save karein.</div>';
      else if (d.open) h += '<div class="warn">⏳ Aaj ki report abhi baaki hai. Antim samay: ' + esc(d.cutoff) + '</div>';
      if (!d.open) h += '<div class="err">⛔ Aaj ka samay khatam. Ab badlav sirf Admin kar sakte hain.</div>';
      h += '<div class="card"><h2>📋 Report – ' + esc(d.date_text) + '</h2><div id="rm"></div><div class="grid">';
      d.fields.forEach(function (f) {
        var v = d.report ? d.report[f.key] : '';
        h += '<div class="field' + (f.offline ? ' off' : '') + '"><label>' + esc(f.label) + '</label><input type="number" inputmode="numeric" min="0" step="1" data-k="' + f.key + '" value="' + esc(v) + '" placeholder="0"' + (d.open ? '' : ' disabled') + '></div>';
      });
      h += '</div>' + (d.open ? '<button class="btn" id="save">' + (d.report ? 'Update karein' : 'Report bhejein') + '</button>' : '') +
        '<p class="muted">Kuch nahi kiya to khali chhod dein ya 0 likhein.</p></div>';

      var dt = d.daily_target;
      h += '<div class="card"><h2>🎯 Aaj ka lakshya</h2><table><tr><th>APY</th><th>PMJJBY</th><th>PMSBY</th><th>PMJDY</th></tr><tr>' +
        ['apy', 'pmjjby', 'pmsby', 'pmjdy'].map(function (k) { return '<td><b>' + pad(dt[k]) + '</b></td>'; }).join('') + '</tr></table>';
      if (d.yesterday.report) {
        h += '<p class="muted" style="margin:10px 0 4px">Kal (' + esc(d.yesterday.date_text) + ') ka pradarshan:</p><table><tr><th>APY</th><th>PMJJBY</th><th>PMSBY</th><th>PMJDY</th></tr><tr>' +
          ['apy', 'pmjjby', 'pmsby', 'pmjdy'].map(function (k) { return '<td>' + pad(d.yesterday.report[k]) + '</td>'; }).join('') + '</tr></table>';
      } else {
        h += '<p class="muted" style="margin-top:10px">Kal (' + esc(d.yesterday.date_text) + ') ki report nahi aayi thi.</p>';
      }
      h += '</div>';
      if (d.monthly.length) {
        h += '<div class="card"><h2>📈 ' + esc(d.month_text) + ' ka target</h2><table>';
        d.monthly.forEach(function (m) {
          var p = Math.min(100, Math.round(100 * m.done / m.target));
          h += '<tr><td>' + esc(m.label) + '<div class="bar"><i style="width:' + p + '%"></i></div></td><td class="n">' + m.done + ' / ' + m.target + '<br><span class="muted">' + p + '%</span></td></tr>';
        });
        h += '</table></div>';
      }
      $app.querySelector('.page').innerHTML = h;
      $app.querySelector('.tabs').outerHTML = tabs(); bindTabs();
      var sb = document.getElementById('save');
      if (sb) sb.onclick = function () { submit(false); };
    });
  }

  function submit(confirmed) {
    var vals = {}, bad = '';
    Array.prototype.forEach.call(document.querySelectorAll('input[data-k]'), function (i) {
      var v = i.value.trim();
      if (v !== '' && !/^\d+$/.test(v)) bad = 'Sirf poore number likhein (0, 1, 2 …)';
      vals[i.getAttribute('data-k')] = v === '' ? 0 : parseInt(v, 10);
    });
    var rm = document.getElementById('rm');
    if (bad) { rm.innerHTML = '<div class="err">' + bad + '</div>'; return; }
    var sb = document.getElementById('save'); sb.disabled = true;
    api('report', { body: { values: vals, confirm: confirmed ? 1 : 0 } }).then(function (j) {
      sb.disabled = false;
      if (j.need_confirm) {
        if (confirm(j.error + '\n\nHaan, sahi hai to OK dabayein.')) submit(true);
        return;
      }
      if (!j.ok) { rm.innerHTML = '<div class="err">' + esc(j.error) + '</div>'; return; }
      if (native && native.setReportDone && state.today) native.setReportDone(state.today.date);
      toast(j.message); renderHome();
    });
  }

  // ------------------------------------------------------------------ history
  function renderHistory() {
    $app.innerHTML = top('Meri reports') + '<div class="page"><div class="card muted">Load ho raha hai…</div></div>' + tabs(); bindTabs();
    api('history').then(function (d) {
      if (!d.ok) return;
      if (!d.rows.length) { $app.querySelector('.page').innerHTML = '<div class="card muted">Abhi koi report nahi.</div>'; return; }
      var h = '<div class="card" style="overflow-x:auto"><table><tr><th>Tarikh</th><th class="n">APY</th><th class="n">JJBY</th><th class="n">SBY</th><th class="n">JDY</th><th class="n">Off</th></tr>';
      d.rows.forEach(function (r) {
        h += '<tr><td>' + esc(r.date_text) + '</td><td class="n">' + r.apy + '</td><td class="n">' + r.pmjjby + '</td><td class="n">' + r.pmsby + '</td><td class="n">' + r.pmjdy + '</td><td class="n">' + (r.apy_off + r.pmjjby_off + r.pmsby_off) + '</td></tr>';
      });
      $app.querySelector('.page').innerHTML = h + '</table><p class="muted">Off = APY + JJBY + SBY offline</p></div>';
    });
  }

  // ------------------------------------------------------------------ chat
  // keep the chat list clear of the bottom dock (composer + navigation), also when the keyboard opens
  function fitDock() {
    var d = document.querySelector('.dock'), p = document.querySelector('.chatpage');
    if (d && p) { p.style.paddingBottom = (d.offsetHeight + 12) + 'px'; }
  }
  window.addEventListener('resize', function () { fitDock(); if (state.tab === 'chat') window.scrollTo(0, document.body.scrollHeight); });
  function stopChat() { if (state.chatTimer) { clearInterval(state.chatTimer); state.chatTimer = null; } }
  function renderChat() {
    state.lastChatId = 0;
    $app.innerHTML = top('Chat', 'Apne CBC / Admin se baat karein') + '<div class="page chatpage"><div class="chat" id="msgs"><div class="muted">Load ho raha hai…</div></div></div>' +
      '<div class="dock"><div class="composer"><textarea id="tx" placeholder="Message likhein…" maxlength="2000" rows="1"></textarea><button id="send" aria-label="Send">Send</button></div>' + tabs() + '</div>';
    fitDock();
    bindTabs();
    loadChat(true);
    state.chatTimer = setInterval(function () { loadChat(false); }, 8000);
    document.getElementById('send').onclick = function () {
      var t = document.getElementById('tx'), v = t.value.trim();
      if (!v) return;
      t.value = '';
      api('chat', { body: { body: v }, qs: '&after=' + state.lastChatId }).then(function (j) { if (j.ok) addMsgs(j.rows); else toast(j.error); });
    };
  }
  function loadChat(first) {
    api('chat', { qs: '&after=' + state.lastChatId }).then(function (j) {
      if (!j.ok) return;
      if (first) { document.getElementById('msgs').innerHTML = j.rows.length ? '' : '<div class="muted" id="empty">Abhi koi message nahi. Kuch bhi poochhna ho to yahan likhein.</div>'; }
      addMsgs(j.rows);
      if (state.today) state.today.chat_unread = 0;
    });
  }
  function addMsgs(rows) {
    var box = document.getElementById('msgs'); if (!box || !rows.length) return;
    var e = document.getElementById('empty'); if (e) e.remove();
    rows.forEach(function (m) {
      if (m.id <= state.lastChatId) return;
      state.lastChatId = m.id;
      var d = document.createElement('div');
      d.className = 'msg ' + (m.mine ? 'me' : 'them');
      d.innerHTML = (m.mine ? '' : '<div class="who">' + esc(m.name) + '</div>') + fmt(m.body) + '<div class="meta">' + esc(m.time) + (m.mine ? (m.read ? ' ✓✓' : ' ✓') : '') + '</div>';
      box.appendChild(d);
    });
    fitDock();
    window.scrollTo(0, document.body.scrollHeight);
  }

  // ------------------------------------------------------------------ notices
  function renderNotices() {
    $app.innerHTML = top('Suchna / Notifications') + '<div class="page"><div class="card muted">Load ho raha hai…</div></div>' + tabs(); bindTabs();
    api('notices').then(function (d) {
      if (!d.ok) return;
      $app.querySelector('.page').innerHTML = d.rows.length ? d.rows.map(function (n) {
        return '<div class="card"><h2>' + esc(n.title) + '</h2><div class="notice">' + esc(n.body) + '</div><p class="muted">' + esc(n.time) + '</p></div>';
      }).join('') : '<div class="card muted">Abhi koi suchna nahi.</div>';
    });
  }

  // ------------------------------------------------------------------ more
  function renderMore() {
    $app.innerHTML = top('Profile') + '<div class="page"><div class="card muted">Load ho raha hai…</div></div>' + tabs(); bindTabs();
    api('me').then(function (d) {
      if (!d.ok) return;
      $app.querySelector('.page').innerHTML = '<div class="card"><h2>' + esc(d.name) + '</h2>' +
        '<p class="muted">BC ID: ' + esc(d.bc_code) + '<br>Mobile: ' + esc(d.phone) + (d.branch ? '<br>Branch: ' + esc(d.branch) : '') + (d.cbc ? '<br>CBC: ' + esc(d.cbc) : '') + '</p></div>' +
        (d.cbc ? '<div class="card"><h2>📞 CBC se baat karein</h2><p class="muted">' + esc(d.cbc) + '</p><button class="btn" id="cv">📹 Video call</button><button class="btn light" id="cvo">🎙️ Voice call</button></div>' : '') +
        '<div class="card"><p class="muted">Har 15 minute mein app nayi suchna check karta hai (internet chahiye).</p>' +
        '<button class="btn light" id="pw">Password badlein</button></div>';
      document.getElementById('pw').onclick = function () { renderPassword(false); };
      if (d.cbc) {
        var cv = document.getElementById('cv'), cvo = document.getElementById('cvo');
        if (cv) cv.onclick = function () { if (window.BCall) window.BCall.start('cbc', 0, 'video', d.cbc); };
        if (cvo) cvo.onclick = function () { if (window.BCall) window.BCall.start('cbc', 0, 'voice', d.cbc); };
      }
    });
  }

  // ------------------------------------------------------------------ update popup
  // Installed app version comes from the WebView user agent ("BCReportApp/1.0.N" -> N).
  function checkUpdate() {
    var m = navigator.userAgent.match(/BCReportApp\/(\d+)\.(\d+)\.(\d+)/);
    if (!m) return; // plain browser – nothing to update
    var mine = parseInt(m[3], 10);
    fetch('/downloads/version.json?t=' + Date.now(), { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (v) {
      if (!v || !v.code || mine >= v.code) return;
      var note = v.note
        ? esc(v.note)
        : 'Download hone par file kholkar <b>Install / Update</b> dabayein. Aapka login aur data waise hi rahega.';
      var d = document.createElement('div');
      d.className = 'upd';
      d.innerHTML = '<div class="updbox"><div class="update-mark">UP</div><h2>Naya update aaya hai</h2>' +
        '<p>Aapke phone mein app ka purana version (1.0.' + mine + ') hai. Naya version <b>' + esc(v.name) + '</b> install karein – ' +
        'call, alarm aur turant notification ke liye zaroori hai.</p>' +
        '<a class="btn" id="updgo" href="/app-download.php">Update karein</a>' +
        '<button class="btn light" id="updlater">Baad mein</button>' +
        '<p class="muted" style="margin-top:10px">' + note + '</p></div>';
      document.body.appendChild(d);
      document.getElementById('updlater').onclick = function () { d.remove(); };
    }).catch(function () {});
  }
  checkUpdate();

  // ------------------------------------------------------------------ CBC (supervisor) view
  function statusChip(s) {
    var m = { submitted: ['Jama ✅', 'g'], pending: ['Baaki ⏳', 'r'], leave: ['Chhutti', 'b'], holiday: ['Avkash', 'b'], weekly_off: ['Weekly off', 'b'] };
    var x = m[s] || [s, 'b'];
    return '<span class="chip ' + x[1] + '">' + esc(x[0]) + '</span>';
  }
  function cbcTabs() {
    var t = state.cbcTab;
    function b(k, i, l) { return '<button data-ctab="' + k + '" class="' + (t === k ? 'on' : '') + '"><b>' + i + '</b>' + l + '</button>'; }
    return '<div class="tabs">' + b('status', 'B', 'BC Status') + b('more', 'M', 'More') + '</div>';
  }
  function bindCbcTabs() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-ctab]'), function (el) { el.onclick = function () { renderCbc(el.getAttribute('data-ctab')); }; });
  }
  function renderCbc(sub) {
    stopChat();
    state.tab = ''; state.cbcTab = sub || 'status';
    $app.innerHTML = top('BC Status', 'Aapke BC ki aaj ki report') + '<div class="page"><div class="card muted">Load ho raha hai…</div></div>' + cbcTabs();
    bindCbcTabs();
    if (state.cbcTab === 'more') { renderCbcMore(); return; }
    api('status').then(function (d) {
      if (!d.ok) { $app.querySelector('.page').innerHTML = '<div class="err">' + esc(d.error || 'Error') + '</div>'; return; }
      var c = d.counts, h = '';
      if (d.meeting && d.meeting.is_host) {
        h += '<div class="host-card"><div class="host-live">🟢 Aapki ' + (d.meeting.mode === 'voice' ? 'voice' : 'video') + ' meeting live hai</div>' +
          '<div class="host-btns"><button class="btn" data-join="1">Join</button><button class="btn red" data-end="1">End meeting</button></div></div>';
      } else {
        h += meetBanner(d.meeting);
        h += '<div class="host-card"><div class="host-title">📹 Meeting – apne BC chunkar bulayein</div>' +
          '<div class="host-btns"><button class="btn" data-start="video">📹 Video</button><button class="btn light" data-start="voice">🎙️ Voice</button></div></div>';
      }
      h += '<div class="cbc-sum">' +
        '<div class="cbc-stat r"><b>' + c.pending + '</b><span>Baaki</span></div>' +
        '<div class="cbc-stat g"><b>' + c.submitted + '</b><span>Jama</span></div>' +
        '<div class="cbc-stat n"><b>' + c.off + '</b><span>Chhutti/Off</span></div></div>';
      if (!d.open) h += '<div class="warn">Aaj report ka samay khatam ho gaya hai.</div>';
      else if (c.pending > 0) h += '<div class="warn">⏳ ' + c.pending + ' BC ne abhi report nahi ki. Unhe call/WhatsApp karke yaad dilayein.</div>';
      else h += '<div class="ok">✅ Aapke sabhi BC ne aaj report kar di hai. Shabash!</div>';
      h += '<div class="card"><h2>📋 BC list – ' + esc(d.date_text) + '</h2>';
      if (!d.bcs.length) h += '<p class="muted">Aapke paas koi active BC nahi hai.</p>';
      d.bcs.forEach(function (b) {
        var ph = String(b.phone || '').replace(/\D/g, '');
        var nm = esc(b.name);
        var act = '<button class="rbtn meet" data-call="' + (b.id || 0) + '" data-mode="video" data-nm="' + nm + '">📹 Video</button>' +
          '<button class="rbtn meetv" data-call="' + (b.id || 0) + '" data-mode="voice" data-nm="' + nm + '">🎙️ Voice</button>';
        if (b.status === 'pending' && ph) {
          act += '<a class="rbtn call" href="tel:+91' + esc(ph) + '">📞 Phone</a>' +
            '<a class="rbtn wa" target="_blank" rel="noopener" href="https://wa.me/91' + esc(ph) + '">WhatsApp</a>';
        }
        var sub2 = [b.branch, b.block].filter(Boolean).join(' · ');
        h += '<div class="cbc-row"><div class="cbc-head"><div class="cbc-name">' + esc(b.name) +
          ' <span class="muted">' + esc(b.bc_code || '') + '</span></div>' + statusChip(b.status) + '</div>' +
          (sub2 ? '<div class="muted sm">' + esc(sub2) + '</div>' : '') +
          '<div class="cbc-act">' + act + '</div></div>';
      });
      h += '</div>';
      $app.querySelector('.page').innerHTML = h;
      var pg = $app.querySelector('.page');
      var jb = pg.querySelector('[data-join]'); if (jb) jb.onclick = function () { openExt(d.meeting.url); };
      var eb = pg.querySelector('[data-end]'); if (eb) eb.onclick = function () { if (confirm('Meeting end karein?')) cbcEndMeeting(); };
      Array.prototype.forEach.call(pg.querySelectorAll('[data-start]'), function (x) { x.onclick = function () { renderInvite(x.getAttribute('data-start')); }; });
      Array.prototype.forEach.call(pg.querySelectorAll('[data-call]'), function (x) { x.onclick = function () { if (window.BCall) window.BCall.start('bc', parseInt(x.getAttribute('data-call'), 10), x.getAttribute('data-mode'), x.getAttribute('data-nm')); }; });
    });
  }
  function renderCbcMore() {
    api('me').then(function (d) {
      if (!d.ok) return;
      $app.querySelector('.page').innerHTML =
        '<div class="card"><h2>' + esc(d.name || 'CBC') + '</h2><p class="muted">Role: CBC<br>Mobile: ' + esc(d.phone || '') + '<br>BC: ' + (d.bc_count || 0) + '</p></div>' +
        '<div class="card"><p class="muted">Jab aapke kisi BC ki report baaki hogi, app samay par alarm bajayega (internet chahiye).</p></div>';
    });
  }

  // ------------------------------------------------------------------ staff home: CBC / BC Supervisor vs Branch Manager
  function staffHome(roleKey) {
    state.role = roleKey || 'cbc';
    if (state.role === 'manager') renderBm('report'); else renderCbc('status');
  }

  // ------------------------------------------------------------------ Branch Manager view (daily branch report)
  function bmTabs() {
    var t = state.bmTab;
    function b(k, i, l) { return '<button data-btab="' + k + '" class="' + (t === k ? 'on' : '') + '"><b>' + i + '</b>' + l + '</button>'; }
    return '<div class="tabs">' + b('report', 'R', 'Report') + b('history', 'H', 'History') + b('bcs', 'B', 'BC Status') + b('more', 'M', 'More') + '</div>';
  }
  function renderBm(sub) {
    stopChat();
    state.tab = ''; state.bmTab = sub || 'report';
    var head = { report: ['Branch Report', 'Aaj ki shakha report'], history: ['Meri reports', 'Pichhli branch reports'],
      bcs: ['BC Status', 'Branch ke BC ki aaj ki report'], more: ['Profile', 'Branch Manager'] }[state.bmTab];
    $app.innerHTML = top(head[0], head[1]) + '<div class="page"><div class="card muted">Load ho raha hai…</div></div>' + bmTabs();
    Array.prototype.forEach.call(document.querySelectorAll('[data-btab]'), function (el) { el.onclick = function () { renderBm(el.getAttribute('data-btab')); }; });
    ({ report: bmReport, history: bmHistory, bcs: bmBcs, more: bmMore })[state.bmTab]();
  }
  function bmPage(html) { var pg = $app.querySelector('.page'); if (pg) pg.innerHTML = html; return pg; }
  function bmNum(f, v) {
    if (v === null || v === undefined || v === '') return '';
    return f.unit === 'amount' ? String(Math.round(parseFloat(v) * 100) / 100) : String(parseInt(v, 10));
  }
  function bmReport() {
    api('bm_today', { qs: state.bmBranch ? '&branch_id=' + state.bmBranch : '' }).then(function (d) {
      if (!d.ok) { bmPage('<div class="err">' + esc(d.error || 'Error') + '</div>'); return; }
      state.bmBranch = d.branch_id;
      var h = meetBanner(d.meeting);
      if (d.branches.length > 1) {
        h += '<div class="card"><div class="field"><label>Branch chunein</label><select id="bmBr" class="bm-sel">' + d.branches.map(function (b) {
          var mark = b.status === 'submitted' ? ' ✅' : (b.status === 'pending' ? ' ⏳' : '');
          return '<option value="' + b.id + '"' + (b.id === d.branch_id ? ' selected' : '') + '>' + esc(b.name) + mark + '</option>';
        }).join('') + '</select></div></div>';
      }
      if (d.report) h += '<div class="ok">✅ Aaj ki branch report jama hai (' + esc(d.updated_at) + '). Badlav ke liye number badal kar dobara Save karein.</div>';
      else if (!d.working) h += '<div class="ok">🏖️ Aaj ' + esc(d.off_text) + ' hai – report zaroori nahi. Chahein to bhar sakte hain.</div>';
      else if (d.open) h += '<div class="warn">⏳ Aaj ki branch report abhi baaki hai. Antim samay: ' + esc(d.cutoff) + '</div>';
      if (!d.open) h += '<div class="err">⛔ Aaj ka samay khatam. Ab badlav sirf Admin / BC Supervisor kar sakte hain.</div>';
      h += '<div class="card"><h2>🏦 ' + esc(d.branch_name) + ' – ' + esc(d.date_text) + '</h2><div id="bmMsg"></div><div class="grid">';
      d.items.forEach(function (f, i) {
        var amt = f.unit === 'amount';
        h += '<div class="field' + (amt ? ' amt' : '') + '"><label>' + (i + 1) + '. ' + esc(f.label) + (amt ? ' <span class="unit">₹ लाख</span>' : '') + '</label>' +
          '<input type="number" min="0" step="' + (amt ? '0.01' : '1') + '" inputmode="' + (amt ? 'decimal' : 'numeric') + '" data-bk="' + f.key + '" data-amt="' + (amt ? 1 : 0) + '"' +
          ' value="' + esc(d.report ? bmNum(f, d.report[f.key]) : '') + '" placeholder="0"' + (d.open ? '' : ' disabled') + '></div>';
      });
      h += '</div>' + (d.open ? '<button class="btn" id="bmSave">' + (d.report ? 'Update karein' : 'Report bhejein') + '</button>' : '') +
        '<p class="muted">Kuch nahi hua to khali chhod dein ya 0 likhein. ₹ wale khane <b>lakh</b> mein (2.5 = ₹2.5 lakh).</p></div>';
      if (d.month) {
        var rows = d.items.filter(function (f) { return parseFloat(d.month[f.key]) > 0; });
        h += '<div class="card"><h2>📈 ' + esc(d.month_text) + ' – ab tak (' + d.month_days + ' din)</h2>' + (rows.length ? '<table>' + rows.map(function (f) {
          return '<tr><td>' + esc(f.label) + '</td><td class="n"><b>' + esc(bmNum(f, d.month[f.key])) + '</b>' + (f.unit === 'amount' ? ' <span class="muted">₹ लाख</span>' : '') + '</td></tr>';
        }).join('') + '</table>' : '<p class="muted">Abhi sab 0 hai.</p>') + '</div>';
      }
      var pg = bmPage(h);
      var sel = document.getElementById('bmBr'); if (sel) sel.onchange = function () { state.bmBranch = parseInt(sel.value, 10); renderBm('report'); };
      var sb = document.getElementById('bmSave'); if (sb) sb.onclick = function () { bmSubmit(d, pg); };
    });
  }
  function bmSubmit(d, pg) {
    var vals = {}, bad = '';
    Array.prototype.forEach.call(pg.querySelectorAll('input[data-bk]'), function (i) {
      var v = i.value.trim(), amt = i.getAttribute('data-amt') === '1';
      if (v !== '' && !(amt ? /^\d+(\.\d{1,2})?$/ : /^\d+$/).test(v)) bad = amt ? '₹ wale khane mein sahi number likhein (jaise 2.5)' : 'Sankhya wale khane mein sirf poore number likhein (0, 1, 2 …)';
      vals[i.getAttribute('data-bk')] = v === '' ? '0' : v;
    });
    var m = document.getElementById('bmMsg');
    if (bad) { m.innerHTML = '<div class="err">' + esc(bad) + '</div>'; m.scrollIntoView({ block: 'center' }); return; }
    var sb = document.getElementById('bmSave'); sb.disabled = true;
    api('bm_report', { body: { branch_id: state.bmBranch, values: vals } }).then(function (j) {
      sb.disabled = false;
      if (!j.ok) { m.innerHTML = '<div class="err">' + esc(j.error || 'Error') + '</div>'; m.scrollIntoView({ block: 'center' }); return; }
      if (j.all_done && native && native.setReportDone) native.setReportDone(d.date); // stop today's call / alarm
      toast(j.message); renderBm('report');
    });
  }
  function bmHistory() {
    api('bm_history').then(function (d) {
      if (!d.ok) { bmPage('<div class="err">' + esc(d.error || 'Error') + '</div>'); return; }
      if (!d.rows.length) { bmPage('<div class="card muted">Abhi koi report nahi.</div>'); return; }
      bmPage(d.rows.map(function (r) {
        var filled = d.items.filter(function (f) { return parseFloat(r.values[f.key]) > 0; });
        return '<div class="card"><h2>' + esc(r.date_text) + (d.multi ? ' <span class="muted">· ' + esc(r.branch) + '</span>' : '') + '</h2>' +
          (filled.length ? '<table>' + filled.map(function (f) {
            return '<tr><td>' + esc(f.label) + '</td><td class="n">' + esc(bmNum(f, r.values[f.key])) + (f.unit === 'amount' ? ' <span class="muted">₹ लाख</span>' : '') + '</td></tr>';
          }).join('') + '</table>' : '<p class="muted">Sab 0</p>') + '</div>';
      }).join(''));
    });
  }
  function bmBcs() {
    api('status').then(function (d) {
      if (!d.ok) { bmPage('<div class="err">' + esc(d.error || 'Error') + '</div>'); return; }
      var c = d.counts, h = '<div class="cbc-sum">' +
        '<div class="cbc-stat r"><b>' + c.pending + '</b><span>Baaki</span></div>' +
        '<div class="cbc-stat g"><b>' + c.submitted + '</b><span>Jama</span></div>' +
        '<div class="cbc-stat n"><b>' + c.off + '</b><span>Chhutti/Off</span></div></div>';
      h += '<div class="card"><h2>📋 BC list – ' + esc(d.date_text) + '</h2>';
      if (!d.bcs.length) h += '<p class="muted">Aapki branch mein koi active BC nahi hai.</p>';
      d.bcs.forEach(function (b) {
        var ph = String(b.phone || '').replace(/\D/g, '');
        var act = (b.status === 'pending' && ph) ? '<div class="cbc-act"><a class="rbtn call" href="tel:+91' + esc(ph) + '">📞 Phone</a>' +
          '<a class="rbtn wa" target="_blank" rel="noopener" href="https://wa.me/91' + esc(ph) + '">WhatsApp</a></div>' : '';
        h += '<div class="cbc-row"><div class="cbc-head"><div class="cbc-name">' + esc(b.name) + ' <span class="muted">' + esc(b.bc_code || '') + '</span></div>' +
          statusChip(b.status) + '</div>' + (b.branch ? '<div class="muted sm">' + esc(b.branch) + '</div>' : '') + act + '</div>';
      });
      bmPage(h + '</div>');
    });
  }
  function bmMore() {
    api('me').then(function (d) {
      if (!d.ok) return;
      bmPage('<div class="card"><h2>' + esc(d.name || 'Branch Manager') + '</h2><p class="muted">Role: ' + esc(d.role || 'Branch Manager') +
        '<br>Mobile: ' + esc(d.phone || '') + '<br>Branch: ' + esc((d.branches || []).join(', ') || '—') + '<br>BC: ' + (d.bc_count || 0) + '</p></div>' +
        '<div class="card"><p class="muted">Jab tak aaj ki branch report baaki hai, app samay par call aur alarm bajayega (internet chahiye). Report bhejte hi band ho jayega.</p></div>');
    });
  }

  // ------------------------------------------------------------------ start
  if (!token()) { renderLogin(''); return; }
  api('me').then(function (d) {
    if (!d.ok) return;
    if (native && native.setSession) native.setSession(token(), location.origin, '');
    startCallWatch();
    if (d.type === 'cbc') { staffHome(d.role_key); return; }
    if (d.must_change) renderPassword(true); else show(location.hash === '#chat' ? 'chat' : location.hash === '#notices' ? 'notices' : 'home');
  });
})();
