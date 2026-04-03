(() => {
  window.ROXY_ARCADE_GAMES = window.ROXY_ARCADE_GAMES || [];

  window.ROXY_ARCADE_GAMES.push({
    key: 'marquee',
    title: 'Marquee Dash',
    create: ({ canvas, ctx, getPointerX, getKeys }) => {
      let t=0, score=0, bonus=0;
      let playerX=canvas.width/2;
      let obstacles=[], reels=[];
      let over=false;
      let spawn=0, reelSpawn=1.5;

      const MAX_OBS = 42;

      function reset() {
        t=0; score=0; bonus=0;
        playerX=canvas.width/2;
        obstacles=[]; reels=[];
        over=false;
        spawn=0; reelSpawn=1.5;
      }

      function drawFilmReel(x,y,r){
        ctx.fillStyle = '#cfcfcf';
        ctx.beginPath(); ctx.arc(x,y,r,0,Math.PI*2); ctx.fill();

        ctx.fillStyle = '#8a8a8a';
        ctx.beginPath(); ctx.arc(x,y,r*0.45,0,Math.PI*2); ctx.fill();

        ctx.fillStyle = '#0b0b0b';
        const holeR = r*0.16;
        for (let k=0;k<6;k++){
          const a = (Math.PI*2)*k/6;
          ctx.beginPath();
          ctx.arc(x + Math.cos(a)*r*0.62, y + Math.sin(a)*r*0.62, holeR, 0, Math.PI*2);
          ctx.fill();
        }
      }

      function spawnObstacleWave(speedMult){
        // Waves increase over time: 1 -> 2 -> 3 (cap)
        const wave = Math.min(3, 1 + Math.floor(t / 18)); // ramp roughly every ~18s
        const baseX = 70 + Math.random()*(canvas.width-140);

        for (let i=0; i<wave; i++){
          if (obstacles.length >= MAX_OBS) break;
          const offset = (i - (wave-1)/2) * 60;
          const x = Math.max(60, Math.min(canvas.width-60, baseX + offset + (Math.random()-0.5)*18));
          obstacles.push({
            x,
            y: -30 - i*22,
            r: 18 + Math.random()*14,
            vy: (240 + Math.random()*170) * speedMult
          });
        }
      }

      function update(dt) {
        if (over) return;
        t += dt;

        // Speed ramps as before
        const speedMult = 1 + Math.min(1.4, t / 85);

        const keys = getKeys();
        const pointerX = getPointerX();

        score = Math.floor(t * 10) + bonus;

        if (keys.left)  playerX -= (380 * speedMult) * dt;
        if (keys.right) playerX += (380 * speedMult) * dt;
        if (!keys.left && !keys.right) playerX += (pointerX - playerX) * Math.min(1, dt*6);

        playerX = Math.max(40, Math.min(canvas.width-40, playerX));

        // Spawn interval decreases over time (more orbs)
        // Starts ~0.65–1.15s and moves toward ~0.22–0.45s
        const minSpawn = 0.22;
        const maxSpawn = 0.65;
        const spawnBias = Math.max(0, Math.min(1, t / 70)); // 0..1
        const base = maxSpawn - (maxSpawn - minSpawn) * spawnBias;

        spawn -= dt;
        if (spawn <= 0) {
          spawn = base + Math.random() * (base * 0.8);
          spawnObstacleWave(speedMult);
        }

        // Reels stay similar cadence
        reelSpawn -= dt;
        if (reelSpawn <= 0) {
          reelSpawn = 2.0 + Math.random()*1.5;
          reels.push({
            x: 60 + Math.random()*(canvas.width-120),
            y: -30,
            r: 16,
            vy: (190 + Math.random()*130) * (0.9 + speedMult*0.22)
          });
        }

        obstacles.forEach(o => o.y += o.vy*dt);
        reels.forEach(r => r.y += r.vy*dt);

        obstacles = obstacles.filter(o => o.y < canvas.height + 60);
        reels = reels.filter(r => r.y < canvas.height + 60);

        // Collision: obstacle ends run
        for (const o of obstacles) {
          const dx = o.x - playerX;
          const dy = o.y - (canvas.height - 70);
          if (Math.hypot(dx, dy) < (o.r + 18)) { over = true; break; }
        }

        // Collision: reel adds points
        if (!over) {
          for (let i=reels.length-1; i>=0; i--) {
            const r = reels[i];
            const dx = r.x - playerX;
            const dy = r.y - (canvas.height - 70);
            if (Math.hypot(dx, dy) < (r.r + 18)) {
              reels.splice(i, 1);
              bonus += 50;
            }
          }
        }
      }

      function draw() {
        ctx.fillStyle = '#090909';
        ctx.fillRect(0,0,canvas.width,canvas.height);

        ctx.fillStyle = '#111';
        for (let i=0;i<10;i++) ctx.fillRect(0, i*60 + ((Date.now()/30)%60), canvas.width, 2);

        // Player
        ctx.fillStyle = '#ffffff';
        ctx.beginPath(); ctx.arc(playerX, canvas.height-70, 18, 0, Math.PI*2); ctx.fill();

        ctx.fillStyle = '#e0002a';
        ctx.font = 'bold 18px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('R', playerX, canvas.height-64);

        // Obstacles
        ctx.fillStyle = '#ffcc00';
        obstacles.forEach(o => { ctx.beginPath(); ctx.arc(o.x, o.y, o.r, 0, Math.PI*2); ctx.fill(); });

        // Reels
        reels.forEach(r => drawFilmReel(r.x, r.y, r.r));

        // HUD
        ctx.fillStyle = '#fff';
        ctx.textAlign = 'left';
        ctx.font = '16px sans-serif';
        ctx.fillText(`Marquee Dash — Score: ${score}`, 16, 24);
        ctx.fillText(`Film Reel Bonus: ${bonus}`, 16, 46);

        if (over) {
          ctx.textAlign = 'center';
          ctx.font = 'bold 36px sans-serif';
          ctx.fillText('SHOW OVER!', canvas.width/2, canvas.height/2 - 10);
          ctx.font = '18px sans-serif';
          ctx.fillText('Press Start/Submit to submit score', canvas.width/2, canvas.height/2 + 26);
        }
      }

      return { reset, update, draw, isOver: () => over, getScore: () => score };
    }
  });
})();
