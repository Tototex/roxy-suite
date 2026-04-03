(() => {
  window.ROXY_ARCADE_GAMES = window.ROXY_ARCADE_GAMES || [];

  window.ROXY_ARCADE_GAMES.push({
    key: 'projector',
    title: 'Projector Defender',
    create: ({ canvas, ctx }) => {
      let t=0;
      let score=0, over=false, enemies=[], spawn=0, health=10;

      // Effects
      let zaps = []; // {x1,y1,x2,y2,ttl}
      let pops = []; // {x,y,r,ttl}

      const MAX_ENEMIES = 36;
      const MAX_EFFECTS = 40;

      function reset(){ 
        t=0;
        score=0; over=false; enemies=[]; spawn=0; health=10; 
        zaps=[]; pops=[];
      }

      function difficultyMult(){
        // Gradual ramp over ~90 seconds
        return 1 + Math.min(1.6, t / 55);
      }

      function spawnEnemy(mult){
        if (enemies.length >= MAX_ENEMIES) return;

        const angle = Math.random() * Math.PI*2;
        const r = 380;
        const baseSpeed = 80 + Math.random()*55;
        const speed = baseSpeed * mult;

        enemies.push({
          x: canvas.width/2 + Math.cos(angle)*r,
          y: canvas.height/2 + Math.sin(angle)*r,
          vx: -Math.cos(angle)*speed,
          vy: -Math.sin(angle)*speed,
          r: 14
        });
      }

      function update(dt){
        if (over) return;

        t += dt;
        const mult = difficultyMult();

        // Spawn faster over time
        const baseInterval = 0.75;
        const minInterval = 0.28;
        const interval = Math.max(minInterval, baseInterval / mult);

        spawn -= dt;
        if (spawn <= 0) {
          spawn = interval * (0.75 + Math.random()*0.7);

          // Occasionally spawn an extra enemy as time progresses
          const extraChance = Math.min(0.35, t / 90);
          spawnEnemy(mult);
          if (Math.random() < extraChance) spawnEnemy(mult * 0.95);
        }

        enemies.forEach(e => { e.x += e.vx*dt; e.y += e.vy*dt; });

        const cx = canvas.width/2, cy = canvas.height/2;
        enemies = enemies.filter(e => {
          if (Math.hypot(e.x-cx, e.y-cy) < 30) {
            health--;
            return false;
          }
          return true;
        });

        if (health <= 0) over = true;

        // Effects decay
        zaps.forEach(z => z.ttl -= dt);
        pops.forEach(p => { p.ttl -= dt; p.r += 240*dt; });
        zaps = zaps.filter(z => z.ttl > 0);
        pops = pops.filter(p => p.ttl > 0);
      }

      function draw(){
        ctx.fillStyle = '#070707';
        ctx.fillRect(0,0,canvas.width,canvas.height);

        const cx = canvas.width/2, cy = canvas.height/2;

        // Pops behind enemies
        pops.forEach(p => {
          const a = Math.max(0, Math.min(1, p.ttl / 0.22));
          ctx.globalAlpha = a;
          ctx.strokeStyle = '#00e5ff';
          ctx.lineWidth = 3;
          ctx.beginPath();
          ctx.arc(p.x, p.y, p.r, 0, Math.PI*2);
          ctx.stroke();
          ctx.globalAlpha = 1;
        });

        // Enemies
        ctx.fillStyle = '#ffcc00';
        enemies.forEach(e => { ctx.beginPath(); ctx.arc(e.x, e.y, e.r, 0, Math.PI*2); ctx.fill(); });

        // Lens
        ctx.fillStyle = '#ffffff';
        ctx.beginPath(); ctx.arc(cx, cy, 26, 0, Math.PI*2); ctx.fill();
        ctx.fillStyle = '#00e5ff';
        ctx.beginPath(); ctx.arc(cx, cy, 16, 0, Math.PI*2); ctx.fill();

        // Zaps on top
        zaps.forEach(z => {
          const a = Math.max(0, Math.min(1, z.ttl / 0.08));
          ctx.globalAlpha = a;
          ctx.strokeStyle = '#00e5ff';
          ctx.lineWidth = 4;
          ctx.beginPath();
          ctx.moveTo(z.x1, z.y1);
          ctx.lineTo(z.x2, z.y2);
          ctx.stroke();

          // little spark
          ctx.lineWidth = 2;
          ctx.beginPath();
          ctx.moveTo(z.x2-6, z.y2-6);
          ctx.lineTo(z.x2+6, z.y2+6);
          ctx.moveTo(z.x2-6, z.y2+6);
          ctx.lineTo(z.x2+6, z.y2-6);
          ctx.stroke();
          ctx.globalAlpha = 1;
        });

        // HUD
        ctx.fillStyle = '#fff';
        ctx.font = '16px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(`Projector Defender — Score: ${score}`, 16, 24);
        ctx.fillText(`Lens Health: ${health}/10`, 16, 46);

        if (over) {
          ctx.textAlign = 'center';
          ctx.font = 'bold 36px sans-serif';
          ctx.fillText('LENS LOST!', canvas.width/2, canvas.height/2 - 10);
          ctx.font = '18px sans-serif';
          ctx.fillText('Press Start/Submit to submit score', canvas.width/2, canvas.height/2 + 26);
        }
      }

      function rayCircleHit(x1,y1,x2,y2,cx,cy,r){
        // closest distance from circle center to segment
        const dx = x2-x1, dy = y2-y1;
        const l2 = dx*dx + dy*dy;
        if (l2 === 0) return Math.hypot(cx-x1, cy-y1) <= r;
        let t = ((cx-x1)*dx + (cy-y1)*dy) / l2;
        t = Math.max(0, Math.min(1, t));
        const px = x1 + t*dx, py = y1 + t*dy;
        return Math.hypot(cx-px, cy-py) <= r;
      }

      // Shooter-like: clicking fires from lens toward click point
      function onPointer(evt){
        if (evt.type !== 'click' || over) return;

        const x1 = canvas.width/2, y1 = canvas.height/2;
        const x2 = evt.x, y2 = evt.y;

        // Add zap effect always
        if (zaps.length < MAX_EFFECTS) zaps.push({x1,y1,x2,y2,ttl:0.08});

        // Find a hit (closest enemy along the beam)
        let hitIndex = -1;
        let hitDist = Infinity;

        for (let i=0;i<enemies.length;i++){
          const e = enemies[i];
          if (rayCircleHit(x1,y1,x2,y2,e.x,e.y,e.r+4)) {
            const d = Math.hypot(e.x-x1, e.y-y1);
            if (d < hitDist) { hitDist = d; hitIndex = i; }
          }
        }

        if (hitIndex >= 0) {
          const e = enemies[hitIndex];
          enemies.splice(hitIndex,1);
          score += 15;

          if (pops.length < MAX_EFFECTS) pops.push({x:e.x,y:e.y,r:6,ttl:0.22});
        }
      }

      return { reset, update, draw, onPointer, isOver:()=>over, getScore:()=>score };
    }
  });
})();
