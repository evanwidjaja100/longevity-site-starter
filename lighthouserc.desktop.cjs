'use strict';

const { urls, assertions } = require('./lighthouserc.shared.cjs');

module.exports = {
  ci: {
    collect: {
      numberOfRuns: 3,
      settings: { chromeFlags: '--no-sandbox --disable-dev-shm-usage', preset: 'desktop' },
      url: urls,
    },
    assert: { assertions },
    upload: { target: 'filesystem', outputDir: './reports/lighthouse/desktop' },
  },
};
