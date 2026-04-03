(() => {
  window.ROXY_ARCADE_GAMES = window.ROXY_ARCADE_GAMES || [];

  window.ROXY_ARCADE_GAMES.push({
    key: 'popcorn',
    title: 'Popcorn Panic',
    create: ({ canvas, ctx, getPointerX, getKeys }) => {
      let score=0, over=false, bucketX=canvas.width/2;
      let drops=[], spawn=0;
      let timeLeft=30.0;

      const MAX_DROPS = 70;

      function drawKernel(x, y, s, rot, bad=false){
        ctx.save();
        ctx.translate(x,y);
        ctx.rotate(rot);

        ctx.fillStyle = bad ? '#00e5ff' : '#fff2b5';
        ctx.beginPath();
        ctx.moveTo(-s*0.2, -s*0.6);
        ctx.bezierCurveTo(-s*0.9, -s*0.6, -s*0.9,  s*0.2, -s*0.2,  s*0.4);
        ctx.bezierCurveTo( 0,     s*0.8,  s*0.8,  s*0.7,  s*0.6,  0);
        ctx.bezierCurveTo( s*0.9, -s*0.8, 0, -s*0.9, -s*0.2, -s*0.6);
        ctx.closePath();
        ctx.fill();

        ctx.globalAlpha = 0.12;
        ctx.fillStyle = '#000';
        ctx.beginPath();
        ctx.ellipse(0, s*0.55, s*0.55, s*0.20, 0, 0, Math.PI*2);
        ctx.fill();

        ctx.restore();
        ctx.globalAlpha = 1;
      }

      function drawPopcornBucket(x){
        const topY = canvas.height - 95;
        const bottomY = canvas.height - 45;

        const topW = 170;
        const bottomW = 130;
        const leftTop = x - topW/2;
        const rightTop = x + topW/2;
        const leftBot = x - bottomW/2;
        const rightBot = x + bottomW/2;

        // Body
        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.moveTo(leftTop, topY);
        ctx.lineTo(rightTop, topY);
        ctx.lineTo(rightBot, bottomY);
        ctx.lineTo(leftBot, bottomY);
        ctx.closePath();
        ctx.fill();

        // Stripes
        const stripes = 7;
        for (let i=0;i<stripes;i++){
          const t0 = i/stripes;
          const t1 = (i+1)/stripes;
          const x0Top = leftTop + (rightTop-leftTop)*t0;
          const x1Top = leftTop + (rightTop-leftTop)*t1;
          const x0Bot = leftBot + (rightBot-leftBot)*t0;
          const x1Bot = leftBot + (rightBot-leftBot)*t1;

          ctx.fillStyle = (i%2===0) ? '#d0002a' : '#ffffff';
          ctx.beginPath();
          ctx.moveTo(x0Top, topY);
          ctx.lineTo(x1Top, topY);
          ctx.lineTo(x1Bot, bottomY);
          ctx.lineTo(x0Bot, bottomY);
          ctx.closePath();
          ctx.fill();
        }

        // Rim + outline
        ctx.strokeStyle = '#111';
        ctx.lineWidth = 2;
        ctx.beginPath(); ctx.moveTo(leftTop, topY); ctx.lineTo(rightTop, topY); ctx.stroke();

        ctx.beginPath();
        ctx.moveTo(leftTop, topY);
        ctx.lineTo(rightTop, topY);
        ctx.lineTo(rightBot, bottomY);
        ctx.lineTo(leftBot, bottomY);
        ctx.closePath();
        ctx.stroke();

        // Label
        ctx.fillStyle = '#111';
        ctx.font = 'bold 14px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('POPCORN', x, topY + 28);

        // Puffs
        for (let i=0;i<9;i++){
          const px = x - 70 + i*17;
          const py = topY - 8 - (i%2)*6;
          drawKernel(px, py, 12, (i%2===0 ? -0.15 : 0.12), false);
        }
      }

      function reset(){
        score=0; over=false; bucketX=canvas.width/2;
        drops=[]; spawn=0; timeLeft=30.0;
      }

      function update(dt){
        if (over) return;

        timeLeft -= dt;
        if (timeLeft <= 0) {
          timeLeft = 0;
          over = true;
          return;
        }

        const keys = getKeys();
        const pointerX = getPointerX();

        bucketX += (pointerX - bucketX) * Math.min(1, dt*8);
        if (keys.left) bucketX -= 520*dt;
        if (keys.right) bucketX += 520*dt;
        bucketX = Math.max(110, Math.min(canvas.width-110, bucketX));

        // Increased spawn rate
        spawn -= dt;
        if (spawn <= 0) {
          const base = 0.10; // faster than before
          spawn = base + Math.random()*0.10;

          if (drops.length < MAX_DROPS) {
            const bad = Math.random() < 0.10;
            const diagonal = Math.random() < 0.35; // some drift
            const vx = diagonal ? (Math.random() < 0.5 ? -1 : 1) * (40 + Math.random()*70) : 0;

            drops.push({
              x: 20 + Math.random()*(canvas.width-40),
              y: -10,
              vy: 260 + Math.random()*220,
              vx,
              bad,
              rot: (Math.random()-0.5) * 0.6
            });
          }
        }

        drops.forEach(d => {
          d.y += d.vy*dt;
          d.x += d.vx*dt;

          // soft bounce on edges
          if (d.x < 16) { d.x = 16; d.vx = Math.abs(d.vx); }
          if (d.x > canvas.width-16) { d.x = canvas.width-16; d.vx = -Math.abs(d.vx); }
        });

        const catchY = canvas.height - 85;
        const catchW = 150;

        drops = drops.filter(d => {
          if (d.y > catchY-10 && d.y < catchY+25 && Math.abs(d.x - bucketX) < catchW/2) {
            if (d.bad) score = Math.max(0, score - 25);
            else score += 10;
            return false;
          }
          // no misses now; falling off just disappears
          return d.y <= canvas.height + 30;
        });
      }

      function draw(){
        ctx.fillStyle = '#0b0b0b';
        ctx.fillRect(0,0,canvas.width,canvas.height);

        drawPopcornBucket(bucketX);
        drops.forEach(d => drawKernel(d.x, d.y, 14, d.rot, d.bad));

        ctx.fillStyle = '#fff';
        ctx.font = '16px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(`Popcorn Panic — Score: ${score}`, 16, 24);
        ctx.fillText(`Time Left: ${Math.ceil(timeLeft)}s`, 16, 46);

        if (over) {
          ctx.textAlign = 'center';
          ctx.font = 'bold 40px sans-serif';
          ctx.fillText(`TIME'S UP!`, canvas.width/2, canvas.height/2 - 10);
          ctx.font = '18px sans-serif';
          ctx.fillText('Press Start/Submit to submit score', canvas.width/2, canvas.height/2 + 26);
        }
      }

      return { reset, update, draw, isOver:()=>over, getScore:()=>score };
    }
  });
})();
