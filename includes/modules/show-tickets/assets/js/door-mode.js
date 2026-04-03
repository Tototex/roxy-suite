(function(){
  const cfg = window.RoxyDoorMode || {};
  const video = document.getElementById('roxy-door-video');
  const startBtn = document.getElementById('roxy-door-start');
  const stopBtn = document.getElementById('roxy-door-stop');
  const torchBtn = document.getElementById('roxy-door-torch');
  const autoResume = document.getElementById('roxy-door-auto-resume');
  const note = document.getElementById('roxy-door-camera-note');
  const overlay = document.getElementById('roxy-door-overlay-text');
  const resultEl = document.getElementById('roxy-door-result');
  const recentList = document.getElementById('roxy-door-recent-list');
  const modal = document.getElementById('roxy-door-modal');
  const flash = document.getElementById('roxy-door-flash');
  const showingLock = document.getElementById('roxy-door-showing-lock');
  const attendanceEl = document.getElementById('roxy-door-attendance');
  if (!video || !startBtn || !resultEl || !modal) return;

  let stream = null, detector = null, timer = null, busy = false, scanning = false, torchOn = false, audioCtx = null;
  let jsQRLib = null, nfcReader = null, wakeLock = null;
  const canvas = document.createElement('canvas');
  const ctx = canvas.getContext('2d', {willReadFrequently:true});

  function setNote(msg){ if (note) note.textContent = msg; }
  function setOverlay(msg){ if (overlay) overlay.textContent = msg; }
  function escapeHtml(s){ return String(s || '').replace(/[&<>\"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }
  // MySQL datetime (2024-03-15 14:30:00) → human-readable, with Safari-safe ISO parsing
  function formatDateTime(dateStr){
    if (!dateStr) return '';
    try { const d = new Date(String(dateStr).replace(' ','T')); return isNaN(d.getTime()) ? String(dateStr) : d.toLocaleString([],{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}); }
    catch(e){ return String(dateStr); }
  }
  function timeAgo(dateStr){
    if (!dateStr) return '';
    try {
      const diffMs = Date.now() - new Date(String(dateStr).replace(' ','T')).getTime();
      if (isNaN(diffMs)) return '';
      const s = Math.floor(diffMs/1000), m = Math.floor(s/60), h = Math.floor(m/60), d = Math.floor(h/24);
      if (s < 90) return 'just now';
      if (m < 60) return m + ' min ago';
      if (h < 24) return h + ' hr ago';
      if (d === 1) return 'yesterday';
      return d + ' days ago';
    } catch(e){ return ''; }
  }
  // Screen Wake Lock — keeps display on while camera is active
  async function acquireWakeLock(){
    if (!('wakeLock' in navigator)) return;
    try { wakeLock = await navigator.wakeLock.request('screen'); wakeLock.addEventListener('release', () => { wakeLock = null; }); } catch(e){ wakeLock = null; }
  }
  function releaseWakeLock(){ if (wakeLock){ wakeLock.release(); wakeLock = null; } }
  // Re-acquire if the page becomes visible again while camera is still running (lock releases on hide)
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && stream) acquireWakeLock(); });
  // Offline banner
  const offlineBanner = document.createElement('div');
  offlineBanner.id = 'roxy-door-offline-banner';
  offlineBanner.textContent = '⚠ No connection — validation paused';
  offlineBanner.hidden = true;  // rely on events only — navigator.onLine has false-positive issues
  document.body.appendChild(offlineBanner);
  window.addEventListener('offline', () => { offlineBanner.hidden = false; });
  window.addEventListener('online',  () => { offlineBanner.hidden = true; });
  function showModal(){ modal.classList.add('is-open'); modal.setAttribute('aria-hidden','false'); document.body.classList.add('roxy-door-modal-open'); }
  function currentLockShowingId(){ return showingLock ? parseInt(showingLock.value || '0', 10) || 0 : 0; }
  function currentLockShowingLabel(){ return showingLock && showingLock.selectedIndex >= 0 ? (showingLock.options[showingLock.selectedIndex].text || '') : ''; }
  function triggerFlash(kind){
    if (!flash) return;
    flash.className = 'roxy-door-flash roxy-door-flash-' + kind;
    window.clearTimeout(triggerFlash._timer);
    triggerFlash._timer = window.setTimeout(() => { flash.className = 'roxy-door-flash'; }, 420);
  }
  function attendanceHtml(data){
    if (!data || !data.showing_id) return '<div class="roxy-door-attendance-empty">' + escapeHtml(cfg.noEventSelectedText || 'Select an event to view attendance.') + '</div>';
    const remaining = data.remaining === null || typeof data.remaining === 'undefined' ? 'Unlimited' : escapeHtml(String(data.remaining));
    const capacity = data.capacity === null || typeof data.capacity === 'undefined' ? 'Unlimited' : escapeHtml(String(data.capacity));
    return '<div class="roxy-door-attendance-header"><div><div class="roxy-door-attendance-kicker">Live Attendance</div><div class="roxy-door-attendance-title">' + escapeHtml(data.showing_title || '') + '</div></div></div>' +
      '<div class="roxy-door-attendance-stats">' +
      '<div><span>Checked In</span><strong>' + escapeHtml(String(data.checked_in || 0)) + '</strong></div>' +
      '<div><span>Sold</span><strong>' + escapeHtml(String(data.sold || 0)) + '</strong></div>' +
      '<div><span>Capacity</span><strong>' + capacity + '</strong></div>' +
      '<div><span>Remaining</span><strong>' + remaining + '</strong></div>' +
      '</div>';
  }
  function renderAttendance(data){ if (attendanceEl) attendanceEl.innerHTML = attendanceHtml(data); }
  function hideModal(){ modal.classList.remove('is-open'); modal.setAttribute('aria-hidden','true'); document.body.classList.remove('roxy-door-modal-open'); }
  function autoResumeEnabled(){ return !!(autoResume && autoResume.checked); }
  function currentTrack(){ return stream && stream.getVideoTracks && stream.getVideoTracks()[0] ? stream.getVideoTracks()[0] : null; }
  function updateTorchVisibility(){
    if (!torchBtn) return;
    const track = currentTrack();
    let supported = false;
    try {
      const caps = track && track.getCapabilities ? track.getCapabilities() : null;
      supported = !!(caps && caps.torch);
    } catch(e) { supported = false; }
    torchBtn.hidden = !supported;
    if (!supported) {
      torchOn = false;
      torchBtn.setAttribute('aria-pressed', 'false');
      torchBtn.classList.remove('is-on');
    }
  }
  function initAudio(){
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
    } catch(e) {}
  }
  function beep(freq, duration, type, gainValue, when){
    if (!audioCtx) return;
    const t = audioCtx.currentTime + (when || 0);
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.type = type || 'sine';
    osc.frequency.setValueAtTime(freq, t);
    gain.gain.setValueAtTime(0.0001, t);
    gain.gain.exponentialRampToValueAtTime(gainValue || 0.05, t + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, t + duration);
    osc.connect(gain).connect(audioCtx.destination);
    osc.start(t); osc.stop(t + duration + 0.02);
  }
  function playSound(kind){
    initAudio();
    if (!audioCtx) return;
    if (kind === 'valid') { beep(880, 0.10, 'sine', 0.05, 0); beep(1175, 0.16, 'sine', 0.04, 0.11); }
    else if (kind === 'used') { beep(520, 0.12, 'triangle', 0.05, 0); beep(420, 0.18, 'triangle', 0.04, 0.14); }
    else if (kind === 'invalid') { beep(220, 0.18, 'sawtooth', 0.05, 0); beep(180, 0.22, 'sawtooth', 0.04, 0.19); }
  }
  function stateHtml(payload){
    if (payload && payload.credential_type === 'member') {
      const active = payload.status === 'valid';
      const photo = payload.photo_url ? `<div class="roxy-door-member-photo-wrap"><img class="roxy-door-member-photo" src="${escapeHtml(payload.photo_url)}" alt="Member photo"></div>` : '';
      const qty = payload.membership_qty ? `<div><dt>Memberships</dt><dd>${escapeHtml(String(payload.membership_qty))}</dd></div>` : '';
      const since = payload.member_since ? `<div class="roxy-door-meta"><strong>Member since:</strong> ${escapeHtml(payload.member_since)}</div>` : '';
      const lastVisit = payload.last_visit ? `<div class="roxy-door-meta"><strong>Last visit:</strong> ${escapeHtml(payload.last_visit)}</div>` : '';
      const nextPay = payload.next_payment ? `<div class="roxy-door-meta"><strong>Next payment:</strong> ${escapeHtml(payload.next_payment)}</div>` : '';
      const statusLabel = payload.status_label ? `<div class="roxy-door-meta"><strong>Status:</strong> ${escapeHtml(payload.status_label)}</div>` : '';
      return `<div class="roxy-door-state roxy-door-state-${active ? 'valid' : 'invalid'}"><div class="roxy-door-kicker">${active ? 'Member Verified' : 'Membership Needs Review'}</div><div class="roxy-door-title" id="roxy-door-modal-title">${escapeHtml(payload.headline || 'Membership')}</div><p>${escapeHtml(payload.subline || '')}</p>${photo}<dl class="roxy-door-details"><div><dt>Member</dt><dd>${escapeHtml(payload.member_name || payload.customer_name || 'Unknown')}</dd></div><div><dt>Subscription</dt><dd>#${escapeHtml(payload.subscription_id || '')}</dd></div>${qty}</dl>${statusLabel}${since}${lastVisit}${nextPay}<div class="roxy-door-token">${escapeHtml(payload.member_check_url || payload.token || '')}</div><div class="roxy-door-result-actions"><button type="button" class="button button-primary roxy-door-rescan">Done / Next Scan</button>${payload.member_check_url ? `<a href="${escapeHtml(payload.member_check_url)}" target="_blank" rel="noopener" class="button">Open Member Check</a>` : ''}</div></div>`;
    }
    if (!payload || payload.found === false || (payload.status === 'invalid' && !payload.ticket_id)) {
      const token = escapeHtml((payload && payload.token) || '');
      return `<div class="roxy-door-state roxy-door-state-invalid"><div class="roxy-door-kicker">Not Found</div><div class="roxy-door-title" id="roxy-door-modal-title">Invalid Ticket</div><p>That code was not found. Try again or open Roxy Check-In for manual lookup.</p>${token?`<div class="roxy-door-token">${token}</div>`:''}<div class="roxy-door-result-actions"><button type="button" class="button button-primary roxy-door-rescan">Try Again</button><a href="${cfg.manualPage || '#'}&s=${encodeURIComponent((payload&&payload.token)||'')}" class="button">Open in Roxy Check-In</a></div></div>`;
    }
    const status = escapeHtml(payload.status || 'valid');
    const kicker = status === 'valid' ? 'Ready to Admit' : (status === 'used' ? 'Already Used' : 'Review');
    return `<div class="roxy-door-state roxy-door-state-${status}"><div class="roxy-door-kicker">${kicker}</div><div class="roxy-door-title" id="roxy-door-modal-title">${escapeHtml(payload.headline || 'Ticket')}</div><p>${escapeHtml(payload.subline || '')}</p><dl class="roxy-door-details"><div><dt>Guest</dt><dd>${escapeHtml(payload.customer_name || 'Unknown')}</dd></div><div><dt>Event</dt><dd>${escapeHtml(payload.showing_title || '')}</dd></div><div><dt>Ticket</dt><dd>${escapeHtml(payload.ticket_label || '')}</dd></div><div><dt>Order</dt><dd>#${escapeHtml(payload.order_number || '')}</dd></div></dl>${payload.customer_email ? `<div class="roxy-door-meta"><strong>Email:</strong> ${escapeHtml(payload.customer_email)}</div>`:''}${payload.checked_in_at ? `<div class="roxy-door-meta"><strong>Checked in:</strong> ${escapeHtml(formatDateTime(payload.checked_in_at))} <span class="roxy-door-time-ago">${escapeHtml(timeAgo(payload.checked_in_at))}</span></div>`:''}${payload.token ? `<div class="roxy-door-token">${escapeHtml(payload.token)}</div>`:''}<div class="roxy-door-result-actions">${payload.can_check_in ? `<button type="button" class="button button-primary roxy-door-admit" data-ticket-id="${escapeHtml(String(payload.ticket_id||''))}">Admit / Check In</button>` : ''}${payload.can_undo ? `<button type="button" class="button roxy-door-undo" data-ticket-id="${escapeHtml(String(payload.ticket_id||''))}" data-undo="1">Undo Check-In</button>` : ''}<button type="button" class="button roxy-door-rescan">Rescan</button><a href="${cfg.manualPage || '#'}&s=${encodeURIComponent(payload.token || '')}" class="button">Manual Check-In</a></div></div>`;
  }
  function render(payload){ resultEl.innerHTML = stateHtml(payload); bindResultActions(); }

  function timeLabel(){
    try { return new Date().toLocaleTimeString([], {hour:'numeric', minute:'2-digit', second:'2-digit'}); }
    catch(e) { return ''; }
  }
  function recentStatusLabel(status){
    if (status === 'admitted') return 'Admitted';
    if (status === 'ready') return 'Ready';
    if (status === 'used') return 'Already Checked In';
    if (status === 'wrong_event') return 'Wrong Event';
    if (status === 'invalid') return 'Invalid Ticket';
    if (status === 'undone') return 'Check-In Removed';
    if (status === 'member_active') return 'Member Active';
    if (status === 'member_inactive') return 'Member Inactive';
    return 'Scan';
  }
  function addRecentScan(status, payload){
    if (!recentList) return;
    const row = document.createElement('div');
    row.className = 'roxy-door-recent-item roxy-door-recent-' + status;
    const name = escapeHtml((payload && (payload.member_name || payload.customer_name)) || 'Unknown');
    const eventTitle = escapeHtml((payload && (payload.showing_title || (payload.credential_type === 'member' ? 'Friends of the Roxy Membership' : 'Unknown event'))) || 'Unknown event');
    const ticket = escapeHtml((payload && (payload.ticket_label || (payload.credential_type === 'member' && payload.membership_qty ? ('Qty ' + payload.membership_qty) : ''))) || '');
    row.innerHTML = '<div class="roxy-door-recent-top"><span class="roxy-door-recent-badge">' + recentStatusLabel(status) + '</span><span class="roxy-door-recent-time">' + timeLabel() + '</span></div>' +
      '<div class="roxy-door-recent-name">' + name + '</div>' +
      '<div class="roxy-door-recent-meta">' + eventTitle + (ticket ? ' • ' + ticket : '') + '</div>';
    const empty = recentList.querySelector('.roxy-door-recent-empty');
    if (empty) empty.remove();
    recentList.prepend(row);
    while (recentList.children.length > 8) {
      recentList.removeChild(recentList.lastElementChild);
    }
  }
  async function refreshAttendance(showingId){
    const sid = typeof showingId === 'number' ? showingId : currentLockShowingId();
    if (!sid) { renderAttendance(null); return; }
    try {
      const json = await post({action:'roxy_st_door_stats', nonce:cfg.nonce, showing_id:String(sid)});
      if (json && json.success) renderAttendance(json.data || null);
    } catch (e) {}
  }
  function idle(){ resultEl.innerHTML = '<div class="roxy-door-state roxy-door-state-idle"><div class="roxy-door-kicker">Ready</div><div class="roxy-door-title" id="roxy-door-modal-title">Waiting for next ticket</div><p>Use the camera for normal flow. Manual Check-In remains available for edge cases.</p></div>'; bindResultActions(); hideModal(); }
  function bindResultActions(){
    resultEl.querySelectorAll('.roxy-door-rescan').forEach((btn) => btn.addEventListener('click', () => { idle(); resumeScanning(); }));
    const admit = resultEl.querySelector('.roxy-door-admit');
    if (admit) admit.addEventListener('click', () => doCheckin(admit.dataset.ticketId, false, true));
    const undo = resultEl.querySelector('.roxy-door-undo');
    if (undo) undo.addEventListener('click', () => doCheckin(undo.dataset.ticketId, true, false));
  }
  async function post(data){ const body = new URLSearchParams(data); const r = await fetch(cfg.ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString(), credentials:'same-origin'}); return r.json(); }
  async function validateToken(token){
    if (!token || busy) return;
    busy = true; pauseScanning(); setOverlay('Ticket found — review result'); setNote('Validating ticket…');
    try {
      const json = await post({action:'roxy_st_door_validate', nonce:cfg.nonce, token, lock_showing_id:String(currentLockShowingId())});
      if (!json || !json.success) throw new Error((json && json.data && json.data.message) || 'Validation failed');
      const payload = json.data || {};
      render(payload); showModal();
      if (payload.attendance) renderAttendance(payload.attendance);
      if (payload.credential_type === 'member') {
        const activeMember = payload.status === 'valid';
        addRecentScan(activeMember ? 'member_active' : 'member_inactive', payload);
        triggerFlash(activeMember ? 'valid' : 'invalid');
        playSound(activeMember ? 'valid' : 'invalid');
        if (activeMember && autoResumeEnabled()) {
          setOverlay('Member verified');
          setNote('Membership verified. Returning to scan mode…');
          window.setTimeout(() => { idle(); resumeScanning(); }, 3000);
        } else {
          setNote(activeMember ? 'Membership verified.' : 'Membership needs review.');
        }
      } else if (payload.status === 'valid') {
        triggerFlash('valid');
        playSound('valid');
        if (autoResumeEnabled() && payload.can_check_in && payload.ticket_id) {
          setOverlay('Valid ticket — admitting automatically');
          setNote('Valid ticket detected. Admitting automatically…');
          busy = false;
          await doCheckin(String(payload.ticket_id), false, true, true);
          return;
        }
        addRecentScan('ready', payload);
        setNote('Review details, then tap Admit or Rescan.');
      } else if (payload.status === 'used') {
        addRecentScan('used', payload);
        triggerFlash('used');
        playSound('used');
        setNote('Already checked in. Review before proceeding.');
      } else if (payload.status === 'wrong_event') {
        addRecentScan('wrong_event', payload);
        triggerFlash('invalid');
        playSound('invalid');
        setNote('Wrong event for current Door Mode lock.');
      } else {
        addRecentScan('invalid', payload);
        triggerFlash('invalid');
        playSound('invalid');
        setNote('Ticket needs manual review.');
      }
    } catch (e) {
      triggerFlash('invalid');
      playSound('invalid');
      addRecentScan('invalid', {token: token, customer_name: 'Unknown', showing_title: 'Ticket not found', ticket_label: ''});
      render({found:false, status:'invalid', token}); showModal();
      setNote(e.message || 'Validation failed.');
    } finally { busy = false; }
  }
  async function doCheckin(ticketId, undo, fromButton, force){
    if (!ticketId || (busy && !force)) return;
    busy = true; setNote(undo ? 'Undoing check-in…' : 'Checking in ticket…');
    try {
      const json = await post({action:'roxy_st_door_checkin', nonce:cfg.checkInNonce, ticket_id:ticketId, undo: undo ? '1' : '', lock_showing_id:String(currentLockShowingId())});
      if (!json || !json.success) throw new Error((json && json.data && json.data.message) || 'Update failed');
      const payload = json.data || {};
      render(payload); showModal();
      if (payload.attendance) renderAttendance(payload.attendance);
      if (undo) {
        addRecentScan('undone', payload);
        triggerFlash('used');
        setOverlay('Check-in removed');
        setNote('Check-in removed.');
        playSound('used');
      } else {
        addRecentScan('admitted', payload);
        triggerFlash('valid');
        setOverlay('Ticket admitted');
        setNote(autoResumeEnabled() ? 'Ticket admitted. Returning to scan mode…' : 'Ticket admitted. Tap Rescan for the next guest.');
        playSound('valid');
        if (autoResumeEnabled()) {
          window.setTimeout(() => { idle(); resumeScanning(); }, 3000);
        }
      }
    } catch (e) { triggerFlash('invalid'); setNote(e.message || 'Update failed.'); }
    finally { busy = false; }
  }
  function pauseScanning(){ scanning = false; if (timer) { clearInterval(timer); timer = null; } }
  function stopCamera(){ pauseScanning(); if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; } video.srcObject = null; torchOn = false; updateTorchVisibility(); setOverlay('Camera stopped'); setNote('Camera stopped.'); releaseWakeLock(); document.body.classList.remove('roxy-door-camera-active'); if (startBtn) startBtn.hidden = false; if (stopBtn) stopBtn.hidden = true; }
  let scanCount = 0;
  function resumeScanning(){ if (!stream || (!detector && !jsQRLib)) return; if (scanning) return; scanning = true; scanCount = 0; setOverlay('Present ticket QR code'); setNote('Aim camera at the QR code.'); timer = setInterval(scanFrame, 650); }
  async function scanFrame(){
    if (!scanning || busy || !video.videoWidth) return;
    // Pulse the note every ~3 seconds on jsQR path so staff can confirm scanner is running
    if (jsQRLib && !detector) { scanCount++; if (scanCount % 5 === 0) setNote('Scanning… (' + scanCount + ')'); }
    try {
      let rawValue = null;
      if (detector) {
        canvas.width = video.videoWidth; canvas.height = video.videoHeight;
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        const codes = await detector.detect(canvas);
        if (codes && codes.length && codes[0].rawValue) rawValue = codes[0].rawValue;
      } else if (jsQRLib) {
        // Downsample to max 640px wide — jsQR is pure JS and bogs down at full camera resolution
        const scale = Math.min(1, 640 / video.videoWidth);
        const sw = Math.round(video.videoWidth * scale);
        const sh = Math.round(video.videoHeight * scale);
        canvas.width = sw; canvas.height = sh;
        ctx.drawImage(video, 0, 0, sw, sh);
        const imageData = ctx.getImageData(0, 0, sw, sh);
        const code = jsQRLib(imageData.data, imageData.width, imageData.height, {inversionAttempts: 'attemptBoth'});
        if (code && code.data) rawValue = code.data;
      }
      if (rawValue) validateToken(rawValue.trim());
    } catch(e){}
  }
  async function loadJsQR(){
    if (typeof jsQR !== 'undefined') { jsQRLib = jsQR; return true; }
    return new Promise((resolve) => {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';
      script.onload = () => { jsQRLib = window.jsQR || null; resolve(!!jsQRLib); };
      script.onerror = () => resolve(false);
      document.head.appendChild(script);
    });
  }
  async function toggleTorch(){
    const track = currentTrack();
    if (!track || !track.applyConstraints) return;
    try {
      torchOn = !torchOn;
      await track.applyConstraints({advanced:[{torch: torchOn}]});
      if (torchBtn) {
        torchBtn.setAttribute('aria-pressed', torchOn ? 'true' : 'false');
        torchBtn.classList.toggle('is-on', torchOn);
      }
    } catch(e) {
      torchOn = false;
      if (torchBtn) { torchBtn.setAttribute('aria-pressed', 'false'); torchBtn.classList.remove('is-on'); }
    }
  }
  async function startNfcIfAvailable(){
    if (!('NDEFReader' in window)) return; // Android Chrome only; iOS silently skips
    if (nfcReader) return; // already running
    try {
      nfcReader = new NDEFReader();
      await nfcReader.scan();
      nfcReader.onreading = (event) => {
        const records = (event.message && event.message.records) ? Array.from(event.message.records) : [];
        for (const record of records) {
          const value = decodeNfcRecord(record);
          if (value) { validateToken(value.trim()); break; }
        }
      };
      nfcReader.onerror = () => { nfcReader = null; };
    } catch(e) {
      nfcReader = null;
    }
  }
  async function startCamera(){
    initAudio();
    if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
      setNote('Camera requires a secure HTTPS connection. Contact your site administrator to enable HTTPS.');
      return;
    }
    if (!('mediaDevices' in navigator) || !navigator.mediaDevices.getUserMedia) {
      setNote('Camera access is not supported on this browser. Use manual lookup or Roxy Check-In.');
      return;
    }
    setNote('Requesting camera access…');
    try {
      try {
        stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}}, audio:false});
      } catch(e) {
        stream = await navigator.mediaDevices.getUserMedia({video:true, audio:false});
      }
    } catch(e) {
      setNote((e.name === 'NotAllowedError' || e.name === 'PermissionDeniedError')
        ? 'Camera access denied. Please allow camera permissions and try again.'
        : 'Could not access camera: ' + (e.message || 'Permission denied or no camera.'));
      return;
    }
    video.srcObject = stream;
    video.muted = true;
    // Wait for loadedmetadata BEFORE calling play() — iOS WebKit runs an internal load()
    // when srcObject is set; calling play() before it finishes throws AbortError.
    // Muted + playsinline does NOT require a user gesture so this await is safe.
    if (video.readyState < 1) {
      await new Promise(resolve => {
        video.addEventListener('loadedmetadata', resolve, {once: true});
        setTimeout(resolve, 3000);
      });
    }
    try {
      await video.play();
    } catch(e) {
      // Don't stop stream — frame polling may still detect frames (common on some browsers)
    }
    if ('BarcodeDetector' in window) {
      detector = new BarcodeDetector({formats:['qr_code']});
    } else {
      setNote('Loading QR scanner…');
      const loaded = await loadJsQR();
      if (!loaded) {
        stream.getTracks().forEach(t => t.stop()); stream = null;
        video.srcObject = null;
        setNote('QR scanner unavailable. Use manual lookup or Roxy Check-In.');
        return;
      }
    }
    setNote('Starting camera…');
    try {
      await new Promise((resolve, reject) => {
        let poll;
        const timeout = setTimeout(() => {
          clearInterval(poll);
          reject(new Error('Camera timed out. Try stopping and restarting, or use manual lookup.'));
        }, 8000);
        const done = () => { clearTimeout(timeout); clearInterval(poll); resolve(); };
        poll = setInterval(() => { if (video.videoWidth > 0 && video.readyState >= 2) done(); }, 200);
        video.addEventListener('playing', done, {once: true});
      });
    } catch(e) {
      stream.getTracks().forEach(t => t.stop()); stream = null;
      video.srcObject = null;
      setNote(e.message || 'Camera failed to start.');
      return;
    }
    updateTorchVisibility(); idle(); resumeScanning();
    startNfcIfAvailable();
    acquireWakeLock();
    document.body.classList.add('roxy-door-camera-active');
    if (startBtn) startBtn.hidden = true;
    if (stopBtn) stopBtn.hidden = false;
  }
  startBtn.addEventListener('click', startCamera);
  if (stopBtn) stopBtn.addEventListener('click', stopCamera);
  if (torchBtn) torchBtn.addEventListener('click', toggleTorch);
  if (showingLock) {
    showingLock.addEventListener('change', () => {
      refreshAttendance();
      setNote(currentLockShowingId() ? 'Door Mode locked to ' + currentLockShowingLabel() + '.' : (cfg.noEventSelectedText || 'Showing all events.'));
    });
  }

  function decodeNfcRecord(record){
    try {
      if (record.recordType === 'url' && record.data) {
        return new TextDecoder().decode(record.data);
      }
      if ((record.recordType === 'text' || record.recordType === 'unknown') && record.data) {
        const raw = new Uint8Array(record.data);
        if (record.recordType === 'text' && raw.length > 3) {
          const langLen = raw[0] & 0x3F;
          return new TextDecoder().decode(raw.slice(1 + langLen));
        }
        return new TextDecoder().decode(raw);
      }
    } catch(e) {}
    return '';
  }
  const manualToken = resultEl.dataset.manualToken;
  refreshAttendance();
  if (manualToken) { showModal(); bindResultActions(); }
  else { idle(); }
})();
