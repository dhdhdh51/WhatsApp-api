/* BC Daily Report – in-app 1:1 video/voice calling (self-hosted WebRTC + PHP signaling). */
(function () {
  'use strict';
  var API = '/api/app.php';
  function token() { return localStorage.getItem('bc_token') || ''; }
  function api(action, opts) {
    opts = opts || {};
    var init = { method: opts.body ? 'POST' : 'GET', headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + token() } };
    if (opts.body) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
    return fetch(API + '?a=' + action + (opts.qs || ''), init).then(function (r) { return r.json().catch(function () { return { ok: false }; }); }, function () { return { ok: false }; });
  }

  var S = null; // active call state

  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }

  function buildUI(mode, titleText, incoming) {
    var ov = el('div', 'call-ov');
    ov.innerHTML =
      '<video class="call-remote" autoplay playsinline></video>' +
      '<video class="call-local" autoplay playsinline muted></video>' +
      '<div class="call-top"><div class="call-name"></div><div class="call-status"></div></div>' +
      '<div class="call-bar"></div>';
    document.body.appendChild(ov);
    ov.querySelector('.call-name').textContent = titleText || '';
    var bar = ov.querySelector('.call-bar');
    if (incoming) {
      bar.innerHTML = '<button class="cbtn green" data-act="accept">✅ ' + (mode === 'voice' ? 'Uthayein' : 'Accept') + '</button>' +
        '<button class="cbtn red" data-act="reject">❌ Reject</button>';
    } else {
      bar.innerHTML = '<button class="cbtn" data-act="mic">🎤</button>' +
        (mode === 'video' ? '<button class="cbtn" data-act="cam">📷</button>' : '') +
        '<button class="cbtn red" data-act="end">📴 End</button>';
    }
    return ov;
  }
  function setStatus(t) { if (S && S.ov) { var s = S.ov.querySelector('.call-status'); if (s) s.textContent = t; } }

  function makePC(ice) {
    var pc = new RTCPeerConnection({ iceServers: ice || [] });
    pc.onicecandidate = function (e) { if (e.candidate) signal({ candidate: e.candidate }); };
    pc.ontrack = function (e) { var r = S.ov.querySelector('.call-remote'); if (r && r.srcObject !== e.streams[0]) r.srcObject = e.streams[0]; setStatus('Connected'); };
    pc.onconnectionstatechange = function () {
      if (!S) return;
      if (pc.connectionState === 'connected') setStatus('Connected');
      else if (pc.connectionState === 'failed') setStatus('Connection failed');
      else if (pc.connectionState === 'disconnected') setStatus('Reconnecting…');
    };
    return pc;
  }

  function getMedia(mode) {
    return navigator.mediaDevices.getUserMedia({ audio: true, video: mode === 'video' }).then(function (stream) {
      S.local = stream;
      var lv = S.ov.querySelector('.call-local'); lv.srcObject = stream;
      if (mode !== 'video') lv.style.display = 'none';
      stream.getTracks().forEach(function (t) { S.pc.addTrack(t, stream); });
    });
  }

  function signal(obj) { if (S) api('call_signal', { body: { call_id: S.callId, data: JSON.stringify(obj) } }); }

  function poll() {
    if (!S) return;
    api('call_state', { qs: '&call_id=' + S.callId + '&after=' + S.lastSig }).then(function (r) {
      if (!S) return;
      if (!r.ok) { S.pollT = setTimeout(poll, 1500); return; }
      if (r.status === 'ended' || r.status === 'rejected') { setStatus(r.status === 'rejected' ? 'Call declined' : 'Call ended'); return teardown(1200); }
      (r.signals || []).forEach(function (s) {
        S.lastSig = Math.max(S.lastSig, s.id);
        var m; try { m = JSON.parse(s.data); } catch (e) { return; }
        if (m.type === 'offer') {
          S.pc.setRemoteDescription(new RTCSessionDescription(m)).then(function () { return S.pc.createAnswer(); })
            .then(function (ans) { return S.pc.setLocalDescription(ans).then(function () { signal({ type: 'answer', sdp: ans.sdp }); }); })
            .then(flushCand);
        } else if (m.type === 'answer') {
          S.pc.setRemoteDescription(new RTCSessionDescription(m)).then(flushCand);
        } else if (m.candidate) {
          if (S.pc.remoteDescription && S.pc.remoteDescription.type) S.pc.addIceCandidate(new RTCIceCandidate(m.candidate)).catch(function () {});
          else S.cand.push(m.candidate);
        }
      });
      S.pollT = setTimeout(poll, 1200);
    });
  }
  function flushCand() { if (!S) return; S.cand.forEach(function (c) { S.pc.addIceCandidate(new RTCIceCandidate(c)).catch(function () {}); }); S.cand = []; }

  function bind(ov) {
    ov.querySelectorAll('[data-act]').forEach(function (b) {
      b.onclick = function () {
        var a = b.getAttribute('data-act');
        if (a === 'end') { api('call_end', { body: { call_id: S.callId } }); teardown(0); }
        else if (a === 'reject') { api('call_reject', { body: { call_id: S.callId } }); teardown(0); }
        else if (a === 'accept') doAccept();
        else if (a === 'mic') { var t = S.local && S.local.getAudioTracks()[0]; if (t) { t.enabled = !t.enabled; b.style.opacity = t.enabled ? 1 : .45; } }
        else if (a === 'cam') { var v = S.local && S.local.getVideoTracks()[0]; if (v) { v.enabled = !v.enabled; b.style.opacity = v.enabled ? 1 : .45; } }
      };
    });
  }

  function teardown(delay) {
    var s = S; S = null;
    setTimeout(function () {
      if (!s) return;
      try { if (s.pollT) clearTimeout(s.pollT); } catch (e) {}
      try { if (s.pc) s.pc.close(); } catch (e) {}
      try { if (s.local) s.local.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
      try { if (s.ov) s.ov.remove(); } catch (e) {}
    }, delay || 0);
  }

  // ---- caller ----
  function start(toType, toId, mode, toName) {
    if (S) return;
    mode = mode === 'voice' ? 'voice' : 'video';
    api('call_invite', { body: { to_type: toType, to_id: toId, mode: mode } }).then(function (r) {
      if (!r.ok) { alert(r.error || 'Call shuru nahi ho payi'); return; }
      S = { callId: r.call_id, role: 'caller', mode: mode, lastSig: 0, cand: [], pc: null, local: null, ov: null };
      S.ov = buildUI(mode, toName || 'Call', false); bind(S.ov);
      setStatus(mode === 'voice' ? 'Voice call…' : 'Calling…');
      S.pc = makePC(r.ice);
      getMedia(mode).then(function () { return S.pc.createOffer(); })
        .then(function (off) { return S.pc.setLocalDescription(off).then(function () { signal({ type: 'offer', sdp: off.sdp }); }); })
        .then(function () { poll(); })
        .catch(function (e) { setStatus('Camera/mic error'); console.error(e); });
    });
  }

  // ---- callee ----
  function incoming(call) {
    if (S) return;
    S = { callId: call.call_id, role: 'callee', mode: call.mode, lastSig: 0, cand: [], pc: null, local: null, ov: null, pending: true };
    S.ov = buildUI(call.mode, call.from_name || 'Incoming', true); bind(S.ov);
    setStatus((call.mode === 'voice' ? 'Voice' : 'Video') + ' call aa rahi hai…');
  }
  function doAccept() {
    if (!S) return;
    var mode = S.mode;
    api('call_accept', { body: { call_id: S.callId } });
    api('call_config').then(function (cfg) {
      S.ov.querySelector('.call-bar').innerHTML =
        '<button class="cbtn" data-act="mic">🎤</button>' + (mode === 'video' ? '<button class="cbtn" data-act="cam">📷</button>' : '') + '<button class="cbtn red" data-act="end">📴 End</button>';
      bind(S.ov);
      setStatus('Connecting…');
      S.pc = makePC(cfg.ice || []);
      getMedia(mode).then(function () { poll(); }).catch(function (e) { setStatus('Camera/mic error'); console.error(e); });
    });
  }

  window.BCall = { start: start, incoming: incoming, active: function () { return !!S; } };
})();
