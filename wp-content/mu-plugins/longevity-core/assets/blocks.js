(() => {
  'use strict';

  const { registerBlockType } = window.wp.blocks;
  const { createElement: el } = window.wp.element;
  const { Placeholder } = window.wp.components;
  const { __ } = window.wp.i18n;
  const blocks = {
    'article-meta': ['Article metadata', 'Author, publication, update, fact-check, review, and evidence dates.'],
    'trust-summary': ['Trust summary', 'Approved bottom line, scope, evidence grade, limitations, and disclosure.'],
    'reviewer-card': ['Reviewer card', 'Verified, authenticated medical reviewer and exact review scope.'],
    'source-list': ['Source list', 'Safe bibliographic details linked to verified claims.'],
    'table-of-contents': ['Table of contents', 'Generated for long articles with at least three H2 headings.'],
    'review-score': ['Review score', 'Reproducible dimensions, total score, confidence, and model version.'],
    'review-decision': ['Review decision', 'Verdict, fit, tested model, acquisition, dates, and failures.'],
    'test-method': ['Test method', 'Version-matched approved test record and limitations.'],
    corrections: ['Corrections', 'Published correction and material update history.'],
    'related-content': ['Related content', 'Deterministic public related guides and reviews.'],
    'content-card-meta': ['Content card metadata', 'Content type, update date, evidence, testing, and review state.'],
    breadcrumbs: ['Breadcrumbs', 'Visible hierarchy shared with breadcrumb schema.'],
    'search-filters': ['Search filters', 'Progressively enhanced, allowlisted GET filters.'],
    'author-profile': ['Author profile', 'Public biography and verified professional scope where present.']
  };

  Object.entries(blocks).forEach(([slug, labels]) => {
    registerBlockType(`longevity/${slug}`, {
      apiVersion: 3,
      title: __(labels[0], 'longevity-core'),
      description: __(labels[1], 'longevity-core'),
      category: 'theme',
      icon: 'shield-alt',
      supports: { html: false },
      edit: () => el(Placeholder, {
        icon: 'shield-alt',
        label: __(labels[0], 'longevity-core'),
        instructions: __(labels[1], 'longevity-core')
      }),
      save: () => null
    });
  });
})();
