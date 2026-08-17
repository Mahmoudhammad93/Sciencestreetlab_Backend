(function () {
  const steps = [
    { type: 'drag', title: 'Drag organs to labels', items: [
      { id: 'heart', label: '❤️ Heart', slot: 'circulatory' },
      { id: 'lungs', label: '🫁 Lungs', slot: 'respiratory' },
      { id: 'brain', label: '🧠 Brain', slot: 'nervous' },
    ], slots: [
      { id: 'circulatory', label: 'Circulatory system' },
      { id: 'respiratory', label: 'Respiratory system' },
      { id: 'nervous', label: 'Nervous system' },
    ]},
    { type: 'mcq', title: 'Which organ pumps blood?', options: ['Lungs','Heart','Stomach'], answer: 'Heart' },
    { type: 'mcq', title: 'Where does gas exchange happen?', options: ['Brain','Lungs','Skin'], answer: 'Lungs' },
    { type: 'mcq', title: 'Which organ controls the body?', options: ['Heart','Brain','Liver'], answer: 'Brain' },
  ];
  let step = 0, score = 0, maxScore = 100, started = false, answers = {};
  const params = new URLSearchParams(location.search);
  let activityId = params.get('activityId');
  let attemptId = params.get('attemptId');
  let origin = '*';

  function post(type, extra) {
    const payload = Object.assign({ type, activityId, attemptId }, extra || {});
    parent.postMessage(payload, origin);
  }
  window.addEventListener('message', (e) => {
    const d = e.data; if (!d || typeof d !== 'object') return;
    if (d.type === 'INIT') {
      activityId = d.activityId || activityId;
      attemptId = d.attemptId || attemptId;
      if (d.origin) origin = d.origin;
    }
  });

  const ui = {
    title: document.getElementById('title'),
    body: document.getElementById('body'),
    status: document.getElementById('status'),
    bar: document.getElementById('bar'),
  };

  function setProgress() {
    ui.bar.style.width = ((step / steps.length) * 100) + '%';
  }

  function render() {
    setProgress();
    const s = steps[step];
    if (!s) return finish();
    ui.title.textContent = 'Step ' + (step + 1) + ': ' + s.title;
    post('QUESTION_STARTED', { step: step + 1, title: s.title });
    if (s.type === 'drag') renderDrag(s);
    else renderMcq(s);
  }

  function renderDrag(s) {
    const slotsHtml = s.slots.map(sl => `<div class="drop" data-slot="${sl.id}" ondragover="event.preventDefault()" ondrop="window.__drop(event,'${sl.id}')">${sl.label}</div>`).join('');
    const itemsHtml = s.items.map(it => `<div class="organ" draggable="true" data-id="${it.id}" ondragstart="window.__drag(event,'${it.id}')">${it.label}</div>`).join('');
    ui.body.innerHTML = `<div class="panel"><div class="organs">${itemsHtml}</div>${slotsHtml}</div>
      <div class="actions"><button id="check">Check placement</button></div>`;
    const map = {};
    window.__drag = (e, id) => { e.dataTransfer.setData('text/plain', id); };
    window.__drop = (e, slot) => {
      e.preventDefault();
      const id = e.dataTransfer.getData('text/plain');
      map[slot] = id;
      e.currentTarget.classList.add('filled');
      e.currentTarget.textContent = s.items.find(i => i.id === id)?.label || id;
    };
    document.getElementById('check').onclick = () => {
      let ok = 0;
      s.items.forEach(it => { if (map[it.slot] === it.id) ok++; });
      const points = Math.round((ok / s.items.length) * 30);
      score += points;
      answers['step'+(step+1)] = map;
      post('ANSWER_SUBMITTED', { step: step+1, answer: map, points });
      post('QUESTION_COMPLETED', { step: step+1, points });
      ui.status.textContent = `Placement score: ${points}/30`;
      step++; render();
    };
  }

  function renderMcq(s) {
    ui.body.innerHTML = `<div class="panel choices">${s.options.map(o => `<label><input type="radio" name="q" value="${o}"> ${o}</label>`).join('')}</div>
      <div class="actions"><button id="submit">Submit</button></div>`;
    document.getElementById('submit').onclick = () => {
      const selected = document.querySelector('input[name=q]:checked');
      if (!selected) { ui.status.textContent = 'Select an answer'; return; }
      const correct = selected.value === s.answer;
      const points = correct ? Math.round(40 / (steps.length - 1)) : 0;
      score += points;
      answers['step'+(step+1)] = selected.value;
      post('ANSWER_SUBMITTED', { step: step+1, answer: selected.value, points });
      post('QUESTION_COMPLETED', { step: step+1, points });
      ui.status.textContent = correct ? 'Correct!' : 'Not quite.';
      step++; render();
    };
  }

  function finish() {
    ui.title.textContent = 'Activity complete';
    ui.body.innerHTML = `<div class="panel"><p>Final score: <strong>${score}</strong> / ${maxScore}</p></div>`;
    ui.status.textContent = 'Result sent to parent app.';
    post('ACTIVITY_COMPLETED', {
      result: { completed: true, score, max_score: maxScore, percentage: Math.round(score/maxScore*100), time_spent_seconds: Math.round((Date.now()-window.__t0)/1000), answers }
    });
  }

  document.getElementById('start').onclick = () => {
    if (started) return;
    started = true; window.__t0 = Date.now();
    post('STARTED'); render();
  };
  document.getElementById('retry').onclick = () => {
    step = 0; score = 0; answers = {}; started = false;
    ui.body.innerHTML = ''; ui.status.textContent = 'Reset. Click Start.';
    post('RETRY');
  };

  post('READY');
})();
