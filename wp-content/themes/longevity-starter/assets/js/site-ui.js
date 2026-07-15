(() => {
  'use strict';

  const dialog = document.getElementById('lel-search-dialog');
  const trigger = document.querySelector('[data-search-open]');
  const fallback = document.querySelector('.longevity-search-fallback');
  if (!dialog || !trigger) return;

  trigger.hidden = false;
  if (fallback) fallback.hidden = true;
  let previousFocus = null;

  const close = () => {
    if (typeof dialog.close === 'function' && dialog.open) dialog.close();
    else dialog.removeAttribute('open');
    document.documentElement.classList.remove('has-open-dialog');
    if (previousFocus instanceof HTMLElement) previousFocus.focus();
  };

  trigger.addEventListener('click', () => {
    previousFocus = trigger;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
    document.documentElement.classList.add('has-open-dialog');
    window.setTimeout(() => dialog.querySelector('input')?.focus(), 0);
  });

  dialog.addEventListener('click', (event) => {
    if (event.target === dialog || event.target.closest('[data-search-close]')) close();
  });
  dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    close();
  });
  dialog.addEventListener('close', () => {
    document.documentElement.classList.remove('has-open-dialog');
  });
})();
