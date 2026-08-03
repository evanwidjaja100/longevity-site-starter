(() => {
  'use strict';

  const resultsEditor = document.querySelector('.lel-results-editor');
  if (resultsEditor) {
    const resultRows = resultsEditor.querySelector('[data-lel-result-rows]');
    const resultFields = ['label', 'observed_value', 'unit', 'reference_label', 'reference_value', 'status', 'note', 'display_order'];
    const reindexResults = () => {
      resultRows.querySelectorAll('[data-lel-result-row]').forEach((row, index) => {
        row.querySelectorAll('input, select, textarea').forEach((input, fieldIndex) => {
          const field = resultFields[fieldIndex];
          input.name = `public_test_results_rows[${index}][${field}]`;
          input.id = `lel-result-${field}-${index}`;
        });
      });
    };

    const createResultRow = (index) => {
      const tr = document.createElement('tr');
      tr.dataset.lelResultRow = '';

      const cells = [
        { tag: 'input', attrs: { type: 'text', 'aria-label': 'Metric' } },
        { tag: 'input', attrs: { type: 'text', 'aria-label': 'Observed value' } },
        { tag: 'input', attrs: { type: 'text', 'aria-label': 'Unit' } },
        { tag: 'input', attrs: { type: 'text', 'aria-label': 'Reference label' } },
        { tag: 'input', attrs: { type: 'text', 'aria-label': 'Reference value' } },
        {
          tag: 'select',
          attrs: { 'aria-label': 'Status' },
          options: [
            { value: 'meets', label: 'Meets reference' },
            { value: 'partially_meets', label: 'Partially meets' },
            { value: 'does_not_meet', label: 'Does not meet' },
            { value: 'informational', label: 'Informational', selected: true },
            { value: 'not_applicable', label: 'Not applicable' },
          ],
        },
        { tag: 'textarea', attrs: { rows: '2', 'aria-label': 'Interpretation note' } },
        { tag: 'input', attrs: { type: 'number', min: '0', max: '999', value: String((index + 1) * 10), 'aria-label': 'Display order' } },
      ];

      cells.forEach((cell) => {
        const td = document.createElement('td');
        const el = document.createElement(cell.tag);
        Object.entries(cell.attrs).forEach(([k, v]) => el.setAttribute(k, v));
        if (cell.options) {
          cell.options.forEach((opt) => {
            const o = document.createElement('option');
            o.value = opt.value;
            o.textContent = opt.label;
            if (opt.selected) o.selected = true;
            el.appendChild(o);
          });
        }
        td.appendChild(el);
        tr.appendChild(td);
      });

      const actions = document.createElement('td');
      const upBtn = document.createElement('button');
      upBtn.type = 'button';
      upBtn.className = 'button-link';
      upBtn.dataset.lelResultUp = '';
      upBtn.setAttribute('aria-label', 'Move row up');
      upBtn.textContent = '\u2191';

      const downBtn = document.createElement('button');
      downBtn.type = 'button';
      downBtn.className = 'button-link';
      downBtn.dataset.lelResultDown = '';
      downBtn.setAttribute('aria-label', 'Move row down');
      downBtn.textContent = '\u2193';

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'button-link-delete';
      removeBtn.dataset.lelRemoveResult = '';
      removeBtn.textContent = 'Remove';

      actions.appendChild(upBtn);
      actions.appendChild(document.createTextNode(' '));
      actions.appendChild(downBtn);
      actions.appendChild(document.createTextNode(' '));
      actions.appendChild(removeBtn);
      tr.appendChild(actions);

      return tr;
    };

    resultsEditor.addEventListener('click', (event) => {
      const row = event.target.closest('[data-lel-result-row]');
      if (event.target.closest('[data-lel-add-result]')) {
        const index = resultRows.children.length;
        resultRows.appendChild(createResultRow(index));
        reindexResults();
        resultRows.lastElementChild.querySelector('input').focus();
      } else if (row && event.target.closest('[data-lel-remove-result]')) {
        row.remove();
        reindexResults();
      } else if (row && event.target.closest('[data-lel-result-up]') && row.previousElementSibling) {
        resultRows.insertBefore(row, row.previousElementSibling);
        reindexResults();
      } else if (row && event.target.closest('[data-lel-result-down]') && row.nextElementSibling) {
        resultRows.insertBefore(row.nextElementSibling, row);
        reindexResults();
      }
    });
    reindexResults();
  }

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

  const createDimensionRow = (index) => {
    const tr = document.createElement('tr');
    tr.dataset.lelScoreRow = '';

    const cells = [
      { id: `lel-dimension-name-${index}`, label: 'Dimension name', tag: 'input', attrs: { type: 'text' } },
      { id: `lel-dimension-score-${index}`, label: 'Dimension score', tag: 'input', attrs: { type: 'number', min: '0', max: '5', step: '0.1' } },
      { id: `lel-dimension-weight-${index}`, label: 'Dimension weight', tag: 'input', attrs: { type: 'number', min: '0', max: '100', step: '0.1' } },
    ];

    cells.forEach((cell) => {
      const td = document.createElement('td');
      const label = document.createElement('label');
      label.className = 'screen-reader-text';
      label.htmlFor = cell.id;
      label.textContent = cell.label;
      const input = document.createElement(cell.tag);
      input.id = cell.id;
      Object.entries(cell.attrs).forEach(([k, v]) => input.setAttribute(k, v));
      td.appendChild(label);
      td.appendChild(input);
      tr.appendChild(td);
    });

    const actions = document.createElement('td');
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'button-link-delete';
    removeBtn.dataset.lelRemoveDimension = '';
    removeBtn.textContent = 'Remove';
    actions.appendChild(removeBtn);
    tr.appendChild(actions);

    return tr;
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
      rows.appendChild(createDimensionRow(index));
      reindex();
      calculate();
      rows.lastElementChild.querySelector('input').focus();
    }
  });

  reindex();
  calculate();
})();
