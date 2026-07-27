(() => {
  'use strict';

  const configEl = document.getElementById('longevity-analytics-config');
  const config = configEl ? JSON.parse(configEl.textContent) : {};
  const schemas = config.eventSchemas || {};
  window.longevityAnalytics = window.longevityAnalytics || [];
  window.longevityConsent = window.longevityConsent || {
    analytics: false,
    advertising: false
  };

  const push = (name, parameters = {}) => {
    const allowedParameters = schemas[name];
    if (!Array.isArray(allowedParameters)) return;

    const candidates = {
      content_id: config.contentId,
      content_group: config.contentGroup,
      ...parameters
    };
    const safe = { event: name };
    allowedParameters.forEach((key) => {
      if (!Object.prototype.hasOwnProperty.call(candidates, key)) return;
      const value = String(candidates[key] ?? '').replace(/[\r\n\t]/g, ' ').slice(0, 120);
      if (value === '') return;
      safe[key] = value;
    });

    window.longevityAnalytics.push(safe);
    const consent = window.longevityConsent || {};
    const advertisingAllowed = name !== 'affiliate_click' || consent.advertising === true;
    if (consent.analytics === true && advertisingAllowed && Array.isArray(window.dataLayer)) {
      window.dataLayer.push(safe);
    }
    window.dispatchEvent(new CustomEvent('longevity:analytics', { detail: safe }));
  };

  document.addEventListener('click', (event) => {
    const target = event.target.closest('[data-lel-event]');
    if (!target) return;
    // Select elements emit on change, not click.
    if (target instanceof HTMLSelectElement) return;
    push(target.dataset.lelEvent, {
      placement: target.dataset.placement,
      merchant: target.dataset.merchant,
      destination_domain: target.hostname
    });
  });

  document.addEventListener('change', (event) => {
    const target = event.target;
    if (target instanceof HTMLSelectElement && target.dataset.lelEvent) {
      push(target.dataset.lelEvent, {
        placement: target.dataset.placement,
        category: target.dataset.category,
        product_category: target.dataset.category,
        sort: target.value,
        value: target.value
      });
    }
  });

  document.addEventListener('toggle', (event) => {
    const target = event.target;
    if (target instanceof HTMLDetailsElement && target.open && target.dataset.lelEvent) {
      push(target.dataset.lelEvent, {
        product_category: target.dataset.productCategory
      });
    }
  }, true);

  window.longevityTrack = push;
})();
