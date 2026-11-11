import { createTheme, Paper } from '@mantine/core';

/**
 * Design tokens and the Mantine theme: the one place where the app's colours are defined. Light and
 * dark values live side by side here, reach the CSS as `--app-*` variables (see
 * `cssVariablesResolver`) and are checked for contrast in theme.test.js. Components and SCSS only
 * use the variables or Mantine's own tokens; never hard-code a colour elsewhere.
 */

/**
 * The Aurora brand gradient where white text sits on it (buttons, tiles, avatars): the violet and
 * blue of the design, darkened just enough for 4.5:1 against white (WCAG AA).
 */
export const BRAND_GRADIENT = { from: '#7758f5', to: '#3472d8' };

/** The undarkened Aurora colours, for decoration that carries no text: the page glow. */
export const BRAND_GLOW = { from: '#7c5cff', to: '#3b82f6' };

/**
 * @typedef {object} Surface
 * @property {string} page the page base colour
 * @property {string} card solid card background
 * @property {string} column board column background: a faint brand tint, so cards stand out on it
 * @property {string} text
 * @property {string} muted secondary text
 * @property {string} dangerText overdue and error text: readable on page, card and a red tile
 * @property {string} border
 * @property {string} shadow card shadow
 * @property {number} glow strength of the page glow, 0 to 1
 */

/** @type {{light: Surface, dark: Surface}} */
export const SURFACES = {
  light: {
    page: '#f4f5fb',
    card: '#ffffff',
    column: 'rgba(119, 88, 245, 0.07)',
    text: '#161a2b',
    muted: '#5b6280',
    dangerText: '#b91c1c',
    border: 'rgba(22, 26, 43, 0.09)',
    shadow: '0 1px 2px rgba(10, 14, 40, 0.05), 0 8px 24px -12px rgba(10, 14, 40, 0.18)',
    glow: 0.16,
  },
  dark: {
    page: '#0d1018',
    card: '#161a26',
    column: 'rgba(124, 92, 255, 0.1)',
    text: '#e8eaf4',
    muted: '#9aa0bd',
    dangerText: '#fca5a5',
    border: 'rgba(255, 255, 255, 0.08)',
    shadow: '0 1px 2px rgba(0, 0, 0, 0.4), 0 10px 28px -14px rgba(0, 0, 0, 0.7)',
    glow: 0.28,
  },
};

/** @param {string} hex #rrggbb @param {number} alpha */
function rgba(hex, alpha) {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/** The page backdrop: a glow of each brand colour in opposite corners over the base colour. */
function pageBackground(/** @type {Surface} */ surface) {
  return [
    `radial-gradient(40rem 22rem at 95% -8%, ${rgba(BRAND_GLOW.from, surface.glow)}, transparent 70%)`,
    `radial-gradient(34rem 20rem at -6% 106%, ${rgba(BRAND_GLOW.to, surface.glow * 0.7)}, transparent 70%)`,
    surface.page,
  ].join(', ');
}

/** @param {Surface} surface */
function schemeVariables(surface) {
  return {
    '--mantine-color-body': surface.page,
    '--mantine-color-default-border': surface.border,
    '--app-page-bg': pageBackground(surface),
    '--app-card-bg': surface.card,
    '--app-column-bg': surface.column,
    '--app-surface': surface.card,
    '--app-card-shadow': surface.shadow,
    '--app-brand-gradient': `linear-gradient(135deg, ${BRAND_GRADIENT.from}, ${BRAND_GRADIENT.to})`,
    // Text on the brand gradient: white, which the gradient stops are chosen to hold 4.5:1 against.
    '--app-on-brand': '#ffffff',
    '--app-text': surface.text,
    '--app-text-muted': surface.muted,
    '--app-border': surface.border,
    '--app-danger-text': surface.dangerText,
  };
}

/**
 * Emits the tokens as CSS variables, one set per colour scheme. Mantine calls it with the theme,
 * which the tokens do not need.
 * @type {import('@mantine/core').CSSVariablesResolver}
 */
export const cssVariablesResolver = () => ({
  variables: {},
  light: schemeVariables(SURFACES.light),
  dark: schemeVariables(SURFACES.dark),
});

export const theme = createTheme({
  primaryColor: 'aurora',
  primaryShade: { light: 6, dark: 6 },
  colors: {
    // Violet to blue; shade 6 (the filled-button shade) holds 4.5:1 under white text.
    aurora: [
      '#f1efff',
      '#e3deff',
      '#c8bdff',
      '#ab9bff',
      '#917bff',
      '#7f66ff',
      '#6c4df0',
      '#5b3fd6',
      '#4b33b3',
      '#3a2889',
    ],
    // A blue-black dark scheme instead of Mantine's neutral gray.
    dark: [
      '#c9cde0',
      '#b3b8d0',
      '#8a90ad',
      '#6a7093',
      '#424866',
      '#363b55',
      '#262b3f',
      '#1b2030',
      '#161a26',
      '#0d1018',
    ],
  },
  defaultGradient: { ...BRAND_GRADIENT, deg: 135 },
  fontFamily: "'Onest Variable', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
  headings: {
    fontFamily: "'Onest Variable', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
    fontWeight: '700',
  },
  defaultRadius: 'md',
  radius: { xs: '0.25rem', sm: '0.5rem', md: '0.75rem', lg: '1rem', xl: '1.5rem' },
  shadows: {
    xs: '0 1px 2px rgba(10, 14, 40, 0.06)',
    sm: '0 1px 2px rgba(10, 14, 40, 0.05), 0 4px 12px -6px rgba(10, 14, 40, 0.14)',
    md: '0 1px 2px rgba(10, 14, 40, 0.05), 0 8px 24px -12px rgba(10, 14, 40, 0.2)',
    lg: '0 2px 4px rgba(10, 14, 40, 0.06), 0 16px 40px -16px rgba(10, 14, 40, 0.28)',
    xl: '0 4px 8px rgba(10, 14, 40, 0.08), 0 28px 60px -20px rgba(10, 14, 40, 0.36)',
  },
  components: {
    // Cards sit on the page as solid, softly shadowed surfaces (see base.scss for .app-paper).
    Paper: Paper.extend({ defaultProps: { radius: 'lg' }, classNames: { root: 'app-paper' } }),
  },
});
