(() => {
  const root = document.querySelector('[data-playground]');
  if (!root) return;

  const expression = document.querySelector('#expression');
  const variables = document.querySelector('#variables');
  const result = document.querySelector('#result');
  const button = document.querySelector('#evaluate');
  const explainButton = document.querySelector('#explain');
  const analyzeButton = document.querySelector('#analyze');
  const plotButton = document.querySelector('#plot');
  const systemButton = document.querySelector('#analyze-system');
  const system = document.querySelector('#system');
  const derivativeButton = document.querySelector('#derivative');
  const integralButton = document.querySelector('#integral');
  const areaButton = document.querySelector('#area');
  const rootButton = document.querySelector('#root-find');
  const matrixButton = document.querySelector('#analyze-matrix');
  const matrix = document.querySelector('#matrix');
  const equation = document.querySelector('#equation');
  const locale = document.querySelector('#locale');
  const resultHeading = document.querySelector('#result-heading');

  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));

  const showResult = (html, className) => {
    result.className = className;
    result.innerHTML = html;
  };

  const readVariables = () => {
    try {
      return { ok: true, value: JSON.parse(variables.value || '{}') };
    } catch {
      showResult('<strong>Variables must be valid JSON.</strong><span>Use an object such as {"subtotal": 42.5}.</span>', 'result-error');
      return { ok: false };
    }
  };

  const run = async () => {
    button.disabled = true;
    button.style.opacity = '.65';
    const parsed = readVariables();
    if (!parsed.ok) { button.disabled = false; button.style.opacity = '1'; return; }

    try {
      const response = await fetch('?api=evaluate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ expression: expression.value, variables: parsed.value }) });
      const data = await response.json();
      if (data.ok) {
        resultHeading.textContent = 'Result';
        showResult(`<span class="success-value">${data.display}</span><span class="success-type">${data.type} · deterministic result</span>`, 'result-success');
      } else {
        showResult(`<strong>${data.code}</strong><p>${data.message}</p><code>source span: ${data.span[0]}–${data.span[1]}</code>`, 'result-error');
      }
    } catch {
      showResult('<strong>Could not reach the evaluator.</strong><span>Check that the local PHP server is running.</span>', 'result-error');
    } finally {
      button.disabled = false;
      button.style.opacity = '1';
    }
  };

  const explain = async () => {
    explainButton.disabled = true;
    explainButton.style.opacity = '.65';
    const parsed = readVariables();
    if (!parsed.ok) { explainButton.disabled = false; explainButton.style.opacity = '1'; return; }

    try {
      const response = await fetch('?api=explain', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ expression: expression.value, variables: parsed.value, locale: locale.value }) });
      const data = await response.json();
      if (!data.ok) {
        showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error');
        return;
      }
      resultHeading.textContent = 'Step-by-step';
      const steps = data.explanation.steps.map((step) => `<li><span class="step-index" aria-hidden="true">${step.id}</span><div class="step-copy"><code>${escapeHtml(step.expression)}</code><strong>${escapeHtml(step.message)}</strong><span class="step-detail">${escapeHtml(step.detail)}</span></div><strong class="step-result">${escapeHtml(step.result)}</strong></li>`).join('');
      const visual = data.explanation.visual;
      const visualMarkup = visual ? `<details class="visual-details"><summary>Visual representation</summary><p>${escapeHtml(visual.description)}</p><div class="visual-preview">${visual.svg}</div></details>` : '';
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">✦</span><div><span class="explanation-label">${escapeHtml(data.explanation.locale)} explanation</span><strong>${escapeHtml(data.explanation.result)}</strong><span class="explanation-hint">Each card shows the rule, the substitution, and the result.</span></div></div><ol class="step-list" aria-label="Calculation steps">${steps}</ol>${visualMarkup}</div>`, 'result-explanation');
    } catch {
      showResult('<strong>Could not reach the explanation service.</strong><span>Check that the private explaining package is installed.</span>', 'result-error');
    } finally {
      explainButton.disabled = false;
      explainButton.style.opacity = '1';
    }
  };

  const analyze = async () => {
    analyzeButton.disabled = true;
    try {
      const parsed = readVariables();
      if (!parsed.ok) return;
      const response = await fetch('?api=analyze', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ equation: equation.value, known: parsed.value }) });
      const data = await response.json();
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      const steps = analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('');
      const solution = Object.entries(analysis.solutions).map(([key, value]) => `<strong>${escapeHtml(key)} = ${escapeHtml(value)}</strong>`).join(' ');
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">≈</span><div><span class="explanation-label">${escapeHtml(analysis.status)}</span><strong>${solution || 'No unique value yet'}</strong><span class="explanation-hint">${escapeHtml(analysis.summary)}</span></div></div><ol class="step-list" aria-label="Equation analysis">${steps}</ol><details class="visual-details" open><summary>Visual representation</summary><p>${escapeHtml(analysis.visual.description)}</p><div class="visual-preview">${analysis.visual.svg}</div></details></div>`, 'result-explanation');
    } catch { showResult('<strong>Could not reach the equation analyzer.</strong>', 'result-error'); }
    finally { analyzeButton.disabled = false; }
  };

  const plot = async () => {
    plotButton.disabled = true;
    try {
      const parsed = readVariables();
      if (!parsed.ok) return;
      const response = await fetch('?api=plot', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ expression: expression.value, variable: 'x', minimum: -10, maximum: 10, samples: 101, variables: parsed.value }) });
      const data = await response.json();
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const visual = data.visual;
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">⌁</span><div><span class="explanation-label">function plot</span><strong>${escapeHtml(visual.title)}</strong><span class="explanation-hint">${escapeHtml(visual.description)}</span></div></div><div class="visual-preview">${visual.svg}</div></div>`, 'result-explanation');
    } catch { showResult('<strong>Could not reach the plotting service.</strong>', 'result-error'); }
    finally { plotButton.disabled = false; }
  };

  const analyzeSystem = async () => {
    systemButton.disabled = true;
    try {
      const response = await fetch('?api=system', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ system: system.value }) });
      const data = await response.json();
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      const solution = Object.entries(analysis.solutions).map(([key, value]) => `<strong>${escapeHtml(key)} = ${escapeHtml(value)}</strong>`).join(' ');
      const visual = analysis.visual;
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">▦</span><div><span class="explanation-label">${escapeHtml(analysis.status)}</span><strong>${solution || 'No unique solution yet'}</strong><span class="explanation-hint">${escapeHtml(analysis.summary)}</span></div></div><ol class="step-list" aria-label="System analysis">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol><details class="visual-details" open><summary>Matrix representation</summary><p>${escapeHtml(visual.description)}</p><div class="visual-preview">${visual.svg}</div></details></div>`, 'result-explanation');
    } catch { showResult('<strong>Could not reach the system analyzer.</strong>', 'result-error'); }
    finally { systemButton.disabled = false; }
  };

  const calculus = async (operation) => {
    const control = operation === 'integral' ? integralButton : derivativeButton;
    control.disabled = true;
    try {
      const response = await fetch('?api=calculus', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ operation, expression: expression.value, variable: 'x' }) });
      const data = await response.json();
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">${operation === 'integral' ? '∫' : '′'}</span><div><span class="explanation-label">${escapeHtml(analysis.operation)}</span><strong>${escapeHtml(analysis.result)}</strong><span class="explanation-hint">${escapeHtml(analysis.status)}</span></div></div><ol class="step-list" aria-label="Calculus steps">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol><details class="visual-details" open><summary>Calculus visual</summary><p>${escapeHtml(analysis.visual.description)}</p><div class="visual-preview">${analysis.visual.svg}</div></details></div>`, 'result-explanation');
    } catch { showResult('<strong>Could not reach the calculus analyzer.</strong>', 'result-error'); }
    finally { control.disabled = false; }
  };

  const area = async () => {
    areaButton.disabled = true;
    try {
      const response = await fetch('?api=area', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ expression: expression.value, variable: 'x', minimum: 0, maximum: 1, samples: 101 }) });
      const data = await response.json();
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">∫</span><div><span class="explanation-label">signed area · ${escapeHtml(analysis.status)}</span><strong>${escapeHtml(analysis.area)}</strong><span class="explanation-hint">${escapeHtml(analysis.expression)} from ${escapeHtml(analysis.domain[0])} to ${escapeHtml(analysis.domain[1])}</span></div></div><ol class="step-list" aria-label="Area steps">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol><details class="visual-details" open><summary>Area visual</summary><p>${escapeHtml(analysis.visual.description)}</p><div class="visual-preview">${analysis.visual.svg}</div></details></div>`, 'result-explanation');
    } catch { showResult('<strong>Could not reach the area analyzer.</strong>', 'result-error'); }
    finally { areaButton.disabled = false; }
  };

  const findRoot = async () => {
    rootButton.disabled = true;
    try {
      const response = await fetch('?api=root', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ expression: expression.value, variable: 'x', minimum: 0, maximum: 2, iterations: 40 }) });
      const data = await response.json();
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">≈</span><div><span class="explanation-label">root · ${escapeHtml(analysis.status)}</span><strong>${escapeHtml(analysis.root ?? 'No certified root')}</strong><span class="explanation-hint">Bisection on [${escapeHtml(analysis.domain[0])}, ${escapeHtml(analysis.domain[1])}]</span></div></div><ol class="step-list" aria-label="Root steps">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol><details class="visual-details" open><summary>Convergence visual</summary><p>${escapeHtml(analysis.visual.description)}</p><div class="visual-preview">${analysis.visual.svg}</div></details></div>`, 'result-explanation');
    } catch { showResult('<strong>Could not reach the root analyzer.</strong>', 'result-error'); }
    finally { rootButton.disabled = false; }
  };

  const analyzeMatrix = async () => {
    matrixButton.disabled = true;
    try {
      let value;
      try { value = JSON.parse(matrix.value); } catch { showResult('<strong>Matrix must be valid JSON.</strong>', 'result-error'); return; }
      const response = await fetch('?api=matrix', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ matrix: value }) });
      const data = await response.json();
      if (!data.ok) { showResult(`<strong>${escapeHtml(data.code)}</strong><p>${escapeHtml(data.message)}</p>`, 'result-error'); return; }
      const analysis = data.analysis;
      const values = Object.entries(analysis.result).map(([key, value]) => `<strong>${escapeHtml(key)} = ${escapeHtml(JSON.stringify(value))}</strong>`).join(' ');
      showResult(`<div class="explanation-result"><div class="explanation-summary"><span class="result-symbol">▦</span><div><span class="explanation-label">matrix · ${escapeHtml(analysis.status)}</span><strong>${values}</strong></div></div><ol class="step-list" aria-label="Matrix steps">${analysis.steps.map((step) => `<li>${escapeHtml(step)}</li>`).join('')}</ol><details class="visual-details" open><summary>Matrix visual</summary><p>${escapeHtml(analysis.visual.description)}</p><div class="visual-preview">${analysis.visual.svg}</div></details></div>`, 'result-explanation');
    } catch { showResult('<strong>Could not reach the matrix analyzer.</strong>', 'result-error'); }
    finally { matrixButton.disabled = false; }
  };

  button.addEventListener('click', run);
  explainButton.addEventListener('click', explain);
  analyzeButton.addEventListener('click', analyze);
  plotButton.addEventListener('click', plot);
  systemButton.addEventListener('click', analyzeSystem);
  derivativeButton.addEventListener('click', () => calculus('derivative'));
  integralButton.addEventListener('click', () => calculus('integral'));
  areaButton.addEventListener('click', area);
  rootButton.addEventListener('click', findRoot);
  matrixButton.addEventListener('click', analyzeMatrix);
  root.querySelectorAll('[data-example]').forEach((example) => example.addEventListener('click', () => { expression.value = example.dataset.example; run(); }));
  expression.addEventListener('keydown', (event) => { if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') run(); });
})();
