import js from '@eslint/js';

export default [
  js.configs.recommended,
  {
    files: ['wp-content/**/*.js'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      globals: {
        window: 'readonly',
        document: 'readonly',
        CustomEvent: 'readonly',
        HTMLDetailsElement: 'readonly'
      }
    },
    rules: { 'no-console': 'off' }
  },
  {
    files: ['tests/e2e/**/*.js'],
    languageOptions: { ecmaVersion: 2022, sourceType: 'module' }
  },
  { ignores: ['node_modules/**', 'vendor/**'] }
];
