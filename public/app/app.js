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
      '<div class="login"><div class="logo">📋</div><h1>BC Daily Report</h1>' +
      (msg ? '<div class="err">' + esc(msg) + '</div>' : '') +
      '<div class="field mb"><label>Mobile number</label><input id="ph" type="tel" inputmode="numeric" maxlength="13" placeholder="98765 43210" autocomplete="username"></div>' +
      '<div class="field mb"><label>Password</label><input id="pw" type="password" autocomplete="current-password"></div>' +
      '<button class="btn" id="go">Login</button>' +
      '<p class="muted" style="text-align:center;margin-top:18px">Password nahi pata? Apne CBC / Admin se poochhein.</p></div>';
    var go = document.getElementById('go');
    go.onclick = function () {
      go.disabled = true;
      api('login', { body: { phone: document.getElementById('ph').value, password: document.getElementById('pw').value, device: navigator.userAgent.slice(0, 100) } })
        .then(function (j) {
          go.disabled = false;
          if (!j.ok) { renderLogin(j.error); return; }
          saveSession(j.token, j.last_notification);
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
    return '<div class="tabs">' + b('home', '📋', 'Report') + b('history', '📅', 'History') + b('chat', '💬', 'Chat', u) + b('notices', '🔔', 'Suchna') + b('more', '☰', 'More') + '</div>';
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
      var h = '';
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
  function stopChat() { if (state.chatTimer) { clearInterval(state.chatTimer); state.chatTimer = null; } }
  function renderChat() {
    state.lastChatId = 0;
    $app.innerHTML = top('Chat', 'Apne CBC / Admin se baat karein') + '<div class="page"><div class="chat" id="msgs"><div class="muted">Load ho raha hai…</div></div></div>' +
      '<div class="composer"><textarea id="tx" placeholder="Message likhein…" maxlength="2000"></textarea><button id="send">➤</button></div>' + tabs();
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
        '<div class="card"><p class="muted">Har 15 minute mein app nayi suchna check karta hai (internet chahiye). Notification aane ke liye app ko notification ki permission dein.</p>' +
        '<button class="btn light" id="pw">Password badlein</button><button class="btn red" id="lo">Logout</button></div>';
      document.getElementById('pw').onclick = function () { renderPassword(false); };
      document.getElementById('lo').onclick = function () { if (confirm('Logout karein?')) logout(false); };
    });
  }

  // ------------------------------------------------------------------ start
  if (!token()) { renderLogin(''); return; }
  api('me').then(function (d) {
    if (!d.ok) return;
    if (native && native.setSession) native.setSession(token(), location.origin, '');
    if (d.must_change) renderPassword(true); else show(location.hash === '#chat' ? 'chat' : location.hash === '#notices' ? 'notices' : 'home');
  });
})();
