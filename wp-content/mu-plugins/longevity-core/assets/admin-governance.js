(() => {
  'use strict';

  const editor = document.querySelector('.lel-editorial-grid');
  if (!editor) return;
  editor.classList.add('lel-js');

  const checked = (id) => Boolean(document.getElementById(id)?.checked);
  const value = (id) => String(document.getElementById(id)?.value || '');
  const updateConditionalSections = () => {
    const medical = checked('medical_review_required') || checked('material_health_claims') || !['', 'not_required'].includes(value('medical_review_status'));
    const testing = checked('testing_required') || !['', 'not_required'].includes(value('testing_status'));
    const commercial = !['', 'none'].includes(value('commercial_relationship'));
    editor.querySelectorAll('[data-lel-conditional="medical"]').forEach((section) => { section.hidden = !medical; });
    editor.querySelectorAll('[data-lel-conditional="testing"]').forEach((section) => { section.hidden = !testing; });
    editor.querySelectorAll('[data-lel-conditional="commercial"]').forEach((section) => { section.hidden = !commercial; });
  };

  editor.addEventListener('change', updateConditionalSections);
  updateConditionalSections();

  document.querySelectorAll('.lel-readiness-link').forEach((link) => {
    link.addEventListener('click', () => {
      const target = document.querySelector(link.getAttribute('href'));
      if (!target) return;
      const section = target.closest('details');
      if (section) {
        section.hidden = false;
        section.open = true;
      }
      window.setTimeout(() => target.focus(), 0);
    });
  });

  const scoreEditor = document.querySelector('.lel-score-editor');
  if (!scoreEditor) return;
  const rows = scoreEditor.querySelector('[data-lel-score-rows]');
  const weightOutput = scoreEditor.querySelector('[data-lel-weight-total]');
  const scoreOutput = scoreEditor.querySelector('[data-lel-calculated-score]');
  const totals = scoreEditor.querySelector('.lel-score-totals');
  const jsonOutput = scoreEditor.querySelector('.lel-score-json');

  const reindex = () => {
    rows.querySelectorAll('[data-lel-score-row]').forEach((row, index) => {
      const inputs = row.querySelectorAll('input');
      const keys = ['name', 'score', 'weight'];
      inputs.forEach((input, inputIndex) => {
        input.name = `review_score_dimensions_rows[${index}][${keys[inputIndex]}]`;
        input.id = `lel-dimension-${keys[inputIndex]}-${index}`;
      });
    });
  };

  const calculate = () => {
    let weight = 0;
    let calculated = 0;
    const json = [];
    rows.querySelectorAll('[data-lel-score-row]').forEach((row) => {
      const inputs = row.querySelectorAll('input');
      const name = inputs[0].value.trim();
      const score = Math.min(5, Math.max(0, Number(inputs[1].value || 0)));
      const rowWeight = Math.min(100, Math.max(0, Number(inputs[2].value || 0)));
      weight += rowWeight;
      calculated += score * (rowWeight / 100);
      if (name) json.push({ name, score, weight: rowWeight });
    });
    weightOutput.textContent = weight.toFixed(1).replace('.0', '');
    scoreOutput.textContent = calculated.toFixed(2);
    totals.classList.toggle('is-invalid', Math.abs(weight - 100) > 0.01);
    jsonOutput.textContent = JSON.stringify(json, null, 2);
  };

  scoreEditor.addEventListener('input', calculate);
  scoreEditor.addEventListener('click', (event) => {
    const remove = event.target.closest('[data-lel-remove-dimension]');
    if (remove) {
      const row = remove.closest('[data-lel-score-row]');
      if (row && rows.querySelectorAll('[data-lel-score-row]').length > 1) row.remove();
      reindex();
      calculate();
      return;
    }
    if (event.target.closest('[data-lel-add-dimension]')) {
      const index = rows.querySelectorAll('[data-lel-score-row]').length;
      const row = document.createElement('tr');
      row.dataset.lelScoreRow = '';
      row.innerHTML = `<td><label class="screen-reader-text" for="lel-dimension-name-${index}">Dimension name</label><input id="lel-dimension-name-${index}" type="text"></td><td><label class="screen-reader-text" for="lel-dimension-score-${index}">Dimension score</label><input id="lel-dimension-score-${index}" type="number" min="0" max="5" step="0.1"></td><td><label class="screen-reader-text" for="lel-dimension-weight-${index}">Dimension weight</label><input id="lel-dimension-weight-${index}" type="number" min="0" max="100" step="0.1"></td><td><button type="button" class="button-link-delete" data-lel-remove-dimension>Remove</button></td>`;
      rows.appendChild(row);
      reindex();
      calculate();
      row.querySelector('input').focus();
    }
  });

  reindex();
  calculate();
})();
