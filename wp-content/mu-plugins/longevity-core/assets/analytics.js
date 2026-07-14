(() => {
  'use strict';

  const config = window.longevityAnalyticsConfig || {};
  const allowed = new Set(config.allowedEvents || []);
  window.longevityAnalytics = window.longevityAnalytics || [];

  const push = (name, parameters = {}) => {
    if (!allowed.has(name)) return;
    const safe = {
      event: name,
      content_id: String(config.contentId || ''),
      content_group: String(config.contentGroup || ''),
      ...parameters
    };
    window.longevityAnalytics.push(safe);
    if (Array.isArray(window.dataLayer)) window.dataLayer.push(safe);
    window.dispatchEvent(new CustomEvent('longevity:analytics', { detail: safe }));
  };

  document.addEventListener('click', (event) => {
    const target = event.target.closest('[data-lel-event]');
    if (!target) return;
    const eventName = target.dataset.lelEvent;
    push(eventName, {
      placement: String(target.dataset.placement || ''),
      merchant: String(target.dataset.merchant || ''),
      destination_domain: target.hostname || ''
    });
  });

  document.addEventListener('toggle', (event) => {
    const target = event.target;
    if (target instanceof HTMLDetailsElement && target.open && target.dataset.lelEvent) {
      push(target.dataset.lelEvent);
    }
  }, true);

  window.longevityTrack = push;
})();
