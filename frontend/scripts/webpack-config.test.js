const { execFileSync } = require('node:child_process');
const path = require('node:path');

const FRONTEND = path.join(__dirname, '..');

// Runs in a fresh Node process (Babel 8 is ESM only), with the environment webpack has: no
// NODE_ENV and no BABEL_ENV.
const COMPILE = `
import { createRequire } from 'node:module';
import { transformSync } from '@babel/core';
const require = createRequire(process.cwd() + '/');
const config = require('./webpack.config.js')({}, { mode: process.argv[1] });
const rule = config.module.rules.find((candidate) => candidate.test.test('App.jsx'));
const use = Array.isArray(rule.use) ? rule.use[0] : rule.use;
const options = typeof use === 'string' ? {} : (use.options ?? {});
const result = transformSync('export const element = <div />;', {
  ...options,
  filename: process.cwd() + '/src/Example.jsx',
});
process.stdout.write(result.code);
`;

/**
 * The JSX of one element, compiled the way webpack's babel-loader compiles it in that mode.
 * @param {'production' | 'development'} mode
 */
function compileJsxFor(mode) {
  const env = { ...process.env };
  delete env.NODE_ENV;
  delete env.BABEL_ENV;
  return execFileSync(process.execPath, ['--input-type=module', '-e', COMPILE, mode], {
    cwd: FRONTEND,
    env,
    encoding: 'utf8',
  });
}

/*
 * Babel picks React's development JSX (`jsxDEV`) whenever its env is "development", its default
 * when NODE_ENV is unset, as it is for webpack. A production bundle of React has no `jsxDEV`, so
 * the built app crashed on its first render.
 */
describe('webpack config', () => {
  test('the production build compiles JSX for the production React runtime', () => {
    const code = compileJsxFor('production');
    expect(code).toContain('react/jsx-runtime');
    expect(code).not.toContain('jsx-dev-runtime');
  });

  test('the development build keeps the development JSX runtime', () => {
    expect(compileJsxFor('development')).toContain('react/jsx-dev-runtime');
  });
});
