(() => {
  const canvas = document.getElementById('roxyArcadeCanvas');
  if (!canvas) return;

  const ctx = canvas.getContext('2d');

  const tabsEl = document.getElementById('roxyArcadeTabs');
  const statusEl = document.getElementById('roxyArcadeStatus');
  const combinedEl = document.getElementById('roxyCombinedBoard');
  const gameEl = document.getElementById('roxyGameBoard');
  const champEl = document.getElementById('roxyChampion');
  const awardRuleEl = document.getElementById('roxyAwardRule');

  const startBtn = document.getElementById('roxyStartBtn');
  const restartBtn = document.getElementById('roxyRestartBtn');

  // Global registry populated by game files
  const registry = (window.ROXY_ARCADE_GAMES || []);
  if (!registry.length) {
    statusEl.textContent = 'No games registered.';
    return;
  }

  // Shared input
  let pointerX = canvas.width / 2;
  let keys = { left:false, right:false, up:false, down:false };

  window.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') keys.left = true;
    if (e.key === 'ArrowRight') keys.right = true;
    if (e.key === 'ArrowUp') keys.up = true;
    if (e.key === 'ArrowDown') keys.down = true;
  });
  window.addEventListener('keyup', (e) => {
    if (e.key === 'ArrowLeft') keys.left = false;
    if (e.key === 'ArrowRight') keys.right = false;
    if (e.key === 'ArrowUp') keys.up = false;
    if (e.key === 'ArrowDown') keys.down = false;
  });

  const pointerMove = (clientX) => {
    const rect = canvas.getBoundingClientRect();
    const x = (clientX - rect.left) / rect.width * canvas.width;
    pointerX = Math.max(0, Math.min(canvas.width, x));
  };

  canvas.addEventListener('mousemove', (e) => pointerMove(e.clientX));
  canvas.addEventListener('touchstart', (e) => { if (e.touches[0]) pointerMove(e.touches[0].clientX); }, {passive:true});
  canvas.addEventListener('touchmove', (e) => { if (e.touches[0]) pointerMove(e.touches[0].clientX); }, {passive:true});

  function canvasXYFromEvent(e) {
    const rect = canvas.getBoundingClientRect();
    const clientX = (e.touches && e.touches[0] && e.touches[0].clientX) || e.clientX;
    const clientY = (e.touches && e.touches[0] && e.touches[0].clientY) || e.clientY;
    return {
      x: (clientX - rect.left) / rect.width * canvas.width,
      y: (clientY - rect.top) / rect.height * canvas.height
    };
  }

  const gameMap = new Map();
  registry.forEach(def => gameMap.set(def.key, def));

  let activeGameKey = registry[0].key;
  let activeGame = null;
  let running = false;

  function buildTabs() {
    tabsEl.innerHTML = '';
    registry.forEach(def => {
      const btn = document.createElement('button');
      btn.className = 'roxy-tab' + (def.key === activeGameKey ? ' is-active' : '');
      btn.textContent = def.title;
      btn.dataset.game = def.key;
      btn.addEventListener('click', () => setGame(def.key));
      tabsEl.appendChild(btn);
    });
  }

  function setStatus(msg){ statusEl.textContent = msg || ''; }

  async function fetchLeaderboards() {
    const url = `${ROXY_ARCADE.restUrl}/leaderboards?game=${encodeURIComponent(activeGameKey)}`;
    const res = await fetch(url);
    const data = await res.json();
    renderBoards(data);
  }

  async function submitScore(score) {
    const res = await fetch(`${ROXY_ARCADE.restUrl}/score`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ROXY_ARCADE.nonce },
      body: JSON.stringify({ game: activeGameKey, score })
    });
    const data = await res.json();
    setStatus(data.message || '');
    if (data.leaderboards) renderBoards({ ...data.leaderboards });
    else await fetchLeaderboards();
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  }
  function toList(arr) {
    if (!arr || !arr.length) return '<div>—</div>';
    return `<ol>${arr.map(r => `<li>${escapeHtml(r.name)} — <strong>${r.score}</strong></li>`).join('')}</ol>`;
  }
  function renderBoards(data) {
    combinedEl.innerHTML = toList(data.combined || []);
    gameEl.innerHTML = toList(data.game || []);
    const c = data.champion || { name:'—', score:0 };
    champEl.innerHTML = `<div><strong>${escapeHtml(c.name)}</strong></div><div>${c.score} pts</div>`;
  }

  function setGame(key) {
    const def = gameMap.get(key);
    if (!def) return;

    activeGameKey = key;
    buildTabs();

    activeGame = def.create({
      canvas, ctx,
      getPointerX: () => pointerX,
      getKeys: () => keys,
    });

    activeGame.reset();
    running = false;
    setStatus('');
    fetchLeaderboards();
  }

  canvas.addEventListener('click', (e) => {
    if (!activeGame || !activeGame.onPointer) return;
    const {x,y} = canvasXYFromEvent(e);
    activeGame.onPointer({ type: 'click', x, y });
  });

  canvas.addEventListener('touchend', (e) => {
    if (!activeGame || !activeGame.onPointer) return;
    const t = e.changedTouches && e.changedTouches[0];
    if (!t) return;
    const rect = canvas.getBoundingClientRect();
    const x = (t.clientX - rect.left) / rect.width * canvas.width;
    const y = (t.clientY - rect.top) / rect.height * canvas.height;
    activeGame.onPointer({ type: 'click', x, y });
  }, {passive:true});

  let last = performance.now();
  function loop(now){
    const dt = Math.min(0.033, (now-last)/1000);
    last = now;

    if (activeGame) {
      if (running) activeGame.update(dt);
      activeGame.draw();
    }

    requestAnimationFrame(loop);
  }
  requestAnimationFrame(loop);

  startBtn.addEventListener('click', async () => {
    if (!activeGame) return;

    if (activeGame.isOver()) {
      running = false;
      await submitScore(activeGame.getScore());
      activeGame.reset();
      return;
    }

    running = !running;
    setStatus(running ? 'Playing…' : 'Paused.');
  });

  restartBtn.addEventListener('click', () => {
    if (!activeGame) return;
    activeGame.reset();
    running = false;
    setStatus('');
  });

  awardRuleEl.textContent = ROXY_ARCADE.awardRuleText || '';
  buildTabs();
  setGame(activeGameKey);
})();
