(function () {
  const form = document.querySelector('.longevity-contact-form');
  if (!form) return;

  form.addEventListener('submit', function () {
    const btn = form.querySelector('button[type="submit"]');
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Sending\u2026';
    }
  });
})();
