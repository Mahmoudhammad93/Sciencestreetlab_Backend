(function () {
  const challenges = [
    { prompt: 'Click the organelle that contains DNA', options: ['Nucleus','Ribosome','Cytoplasm'], answer: 'Nucleus' },
    { prompt: 'Match: powerhouse of the cell', options: ['Mitochondria','Golgi','Vacuole'], answer: 'Mitochondria' },
    { prompt: 'Which builds proteins?', options: ['Lysosome','Ribosome','Cell wall'], answer: 'Ribosome' },
    { prompt: 'Outer boundary of animal cell?', options: ['Cell membrane','Cell wall','Capsule'], answer: 'Cell membrane' },
  ];
  let i = 0, score = 0, maxScore = 100, started=false, answers={};
  let activityId=null, attemptId=null, origin='*';
  function post(type, extra){ parent.postMessage(Object.assign({type,activityId,attemptId}, extra||{}), origin); }
  window.addEventListener('message', e => { const d=e.data; if(d&&d.type==='INIT'){ activityId=d.activityId||activityId; attemptId=d.attemptId||attemptId; if(d.origin) origin=d.origin; }});
  const title=document.getElementById('title'), body=document.getElementById('body'), status=document.getElementById('status'), bar=document.getElementById('bar');
  function render(){
    bar.style.width=((i/challenges.length)*100)+'%';
    if(i>=challenges.length){
      title.textContent='Cell tour complete';
      body.innerHTML=`<div class="panel">Score: <strong>${score}</strong>/${maxScore}</div>`;
      post('ACTIVITY_COMPLETED',{result:{completed:true,score,max_score:maxScore,percentage:Math.round(score/maxScore*100),time_spent_seconds:Math.round((Date.now()-window.__t0)/1000),answers}});
      return;
    }
    const c=challenges[i];
    title.textContent='Challenge '+(i+1)+': '+c.prompt;
    post('QUESTION_STARTED',{step:i+1,title:c.prompt});
    body.innerHTML=`<div class="panel choices">${c.options.map(o=>`<label><input type="radio" name="q" value="${o}"> ${o}</label>`).join('')}</div><button id="go">Submit</button>`;
    document.getElementById('go').onclick=()=>{
      const sel=document.querySelector('input[name=q]:checked'); if(!sel){status.textContent='Pick one';return;}
      const ok=sel.value===c.answer; const pts=ok?25:0; score+=pts; answers['q'+(i+1)]=sel.value;
      post('ANSWER_SUBMITTED',{step:i+1,answer:sel.value,points:pts});
      post('QUESTION_COMPLETED',{step:i+1,points:pts});
      status.textContent=ok?'Nice!':'Try to remember for next time.';
      i++; render();
    };
  }
  document.getElementById('start').onclick=()=>{ if(started)return; started=true; window.__t0=Date.now(); post('STARTED'); render(); };
  document.getElementById('retry').onclick=()=>{ i=0;score=0;answers={};started=false; body.innerHTML=''; status.textContent='Reset.'; post('RETRY'); };
  post('READY');
})();
