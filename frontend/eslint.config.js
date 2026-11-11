const js = require('@eslint/js');
const globals = require('globals');
const react = require('eslint-plugin-react');
const reactHooks = require('eslint-plugin-react-hooks');
const prettier = require('eslint-config-prettier');

module.exports = [
  { ignores: ['dist/', 'node_modules/', 'coverage/', '.npm-cache/'] },
  js.configs.recommended,
  {
    files: ['src/**/*.{js,jsx}'],
    plugins: { react, 'react-hooks': reactHooks },
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      parserOptions: { ecmaFeatures: { jsx: true } },
      globals: { ...globals.browser, process: 'readonly' },
    },
    settings: { react: { version: 'detect' } },
    rules: {
      ...react.configs.recommended.rules,
      ...react.configs['jsx-runtime'].rules,
      ...reactHooks.configs.recommended.rules,
      // Types come from JSDoc and `tsc`, not from prop-types.
      'react/prop-types': 'off',
    },
  },
  {
    files: ['src/**/*.test.{js,jsx}', 'scripts/**/*.test.js', 'jest.setup.js', 'src/test/**'],
    languageOptions: {
      sourceType: 'module',
      globals: { ...globals.browser, ...globals.jest, ...globals.node },
    },
  },
  {
    // Text for people belongs in src/i18n/locales, not in components.
    files: ['src/**/*.jsx'],
    ignores: ['**/*.test.jsx'],
    rules: { 'react/jsx-no-literals': 'error' },
  },
  {
    // Tooling files run in Node as CommonJS.
    files: [
      '*.config.js',
      'scripts/**/*.js',
      'src/test/styleStub.js',
      'src/test/globalSetup.js',
      'src/test/jsdomEnvironment.js',
    ],
    languageOptions: { sourceType: 'commonjs', globals: { ...globals.node } },
  },
  prettier,
];
