import { test } from '@playwright/test';

test('find footer a rule', async ({ page }) => {
  await page.goto('/');
  const result = await page.evaluate(() => {
    const sheets = document.styleSheets;
    for (let i = 0; i < sheets.length; i++) {
      const href = sheets[i].href || 'inline';
      if (href.includes('style.css')) {
        try {
          const rules = sheets[i].cssRules || [];
          for (let j = 0; j < rules.length; j++) {
            if (rules[j].cssText && rules[j].cssText.includes('longevity-site-footer a')) {
              return 'FOUND at rule ' + j + ': ' + rules[j].cssText.substring(0, 100);
            }
          }
          return 'style.css has ' + rules.length + ' rules, none match';
        } catch(e) {
          return 'error reading style.css: ' + e.message;
        }
      }
    }
    return 'style.css not found in ' + sheets.length + ' sheets';
  });
  console.log(result);
});
