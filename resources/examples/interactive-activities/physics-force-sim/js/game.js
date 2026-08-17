(function(){
  let mass=2, force=10, started=false, score=0, answers={}, activityId=null, attemptId=null, origin='*';
  const maxScore=100;
  function post(type,extra){parent.postMessage(Object.assign({type,activityId,attemptId},extra||{}),origin);}
  window.addEventListener('message',e=>{const d=e.data;if(d&&d.type==='INIT'){activityId=d.activityId||activityId;attemptId=d.attemptId||attemptId;if(d.origin)origin=d.origin;}});
  const ball=document.getElementById('ball'), accelEl=document.getElementById('accel'), status=document.getElementById('status');
  function accel(){return force/mass;}
  function update(){ accelEl.textContent = accel().toFixed(2)+' m/s²'; ball.style.left = Math.min(90, 5+accel()*4)+'%'; post('PROGRESS',{mass,force,acceleration:accel()}); }
  document.getElementById('mass').oninput=e=>{mass=Number(e.target.value);update();};
  document.getElementById('force').oninput=e=>{force=Number(e.target.value);update();};
  document.getElementById('start').onclick=()=>{if(started)return;started=true;window.__t0=Date.now();post('STARTED');status.textContent='Adjust mass & force, then answer.';update();};
  document.getElementById('ask').onclick=()=>{
    if(!started){status.textContent='Start first';return;}
    post('QUESTION_STARTED',{step:1,title:'Newton 2nd law'});
    const a=accel();
    const guess=Number(document.getElementById('guess').value);
    const ok=Math.abs(guess-a)<0.15;
    const pts=ok?60:20; score+=pts; answers.acceleration=guess; answers.expected=a;
    post('ANSWER_SUBMITTED',{answer:{acceleration:guess},points:pts});
    post('QUESTION_COMPLETED',{points:pts});
    // second challenge: if force doubles
    const doubled=force*2/mass; answers.doubled_guess=Number(document.getElementById('guess2').value);
    const ok2=Math.abs(answers.doubled_guess-doubled)<0.2; const pts2=ok2?40:10; score+=pts2;
    answers.doubled_expected=doubled;
    post('ANSWER_SUBMITTED',{step:2,answer:{doubled:answers.doubled_guess},points:pts2});
    post('QUESTION_COMPLETED',{step:2,points:pts2});
    post('ACTIVITY_COMPLETED',{result:{completed:true,score,max_score:maxScore,percentage:Math.round(score/maxScore*100),time_spent_seconds:Math.round((Date.now()-window.__t0)/1000),answers}});
    status.textContent=`Done. Score ${score}/${maxScore}`;
  };
  document.getElementById('retry').onclick=()=>{started=false;score=0;answers={};status.textContent='Reset.';post('RETRY');};
  post('READY'); update();
})();
