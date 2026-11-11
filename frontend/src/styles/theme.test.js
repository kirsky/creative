import { BRAND_GRADIENT, SURFACES, cssVariablesResolver, theme } from './theme';

/** WCAG relative luminance of a #rrggbb colour.
 * @param {string} hex */
function luminance(hex) {
  const [r, g, b] = [1, 3, 5]
    .map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG contrast ratio of two #rrggbb colours.
 * @param {string} a @param {string} b */
function contrast(a, b) {
  const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (light + 0.05) / (dark + 0.05);
}

/** A colour with `alpha` of `over` laid on `base`, both #rrggbb.
 * @param {string} over @param {string} base @param {number} alpha */
function tint(over, base, alpha) {
  const channel = (/** @type {number} */ i) =>
    Math.round(
      parseInt(over.slice(i, i + 2), 16) * alpha + parseInt(base.slice(i, i + 2), 16) * (1 - alpha),
    )
      .toString(16)
      .padStart(2, '0');
  return `#${channel(1)}${channel(3)}${channel(5)}`;
}

const RED = '#ef4444';
const APP_VARIABLES = [
  '--app-page-bg',
  '--app-card-bg',
  '--app-card-shadow',
  '--app-brand-gradient',
  '--app-text',
  '--app-text-muted',
  '--app-border',
  '--app-danger-text',
];

/** The theme's loosely typed parts, as the tests read them. */
const t = /** @type {any} */ (theme);

describe('theme', () => {
  test('uses the Aurora palette as the primary colour', () => {
    expect(theme.primaryColor).toBe('aurora');
    expect(t.colors.aurora).toHaveLength(10);
  });

  test('uses Onest as the font, with a system fallback', () => {
    expect(theme.fontFamily).toMatch(/^'?Onest Variable'?,/);
    expect(theme.fontFamily).toContain('system-ui');
  });

  test('has a default gradient from the brand stops', () => {
    expect(theme.defaultGradient).toMatchObject({ ...BRAND_GRADIENT, deg: 135 });
  });

  test('keeps the filled primary shade readable under white text', () => {
    const shade = t.colors.aurora[t.primaryShade.light];
    expect(contrast('#ffffff', shade)).toBeGreaterThanOrEqual(4.5);
    const dark = t.colors.aurora[t.primaryShade.dark];
    expect(contrast('#ffffff', dark)).toBeGreaterThanOrEqual(4.5);
  });
});

describe('contrast of the tokens (WCAG AA, 4.5:1)', () => {
  test.each([BRAND_GRADIENT.from, BRAND_GRADIENT.to])(
    'white text on the brand gradient stop %s',
    (stop) => {
      expect(contrast('#ffffff', stop)).toBeGreaterThanOrEqual(4.5);
    },
  );

  test.each(/** @type {const} */ (['light', 'dark']))(
    '%s scheme: text, muted text and overdue red',
    (scheme) => {
      const s = SURFACES[scheme];
      const redTile = tint(RED, s.card, 0.1);
      for (const background of [s.page, s.card]) {
        expect(contrast(s.text, background)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(s.muted, background)).toBeGreaterThanOrEqual(4.5);
        expect(contrast(s.dangerText, background)).toBeGreaterThanOrEqual(4.5);
      }
      expect(contrast(s.dangerText, redTile)).toBeGreaterThanOrEqual(4.5);
    },
  );
});

describe('cssVariablesResolver', () => {
  const resolved = /** @type {any} */ (cssVariablesResolver(/** @type {any} */ (theme)));

  test.each(/** @type {const} */ (['light', 'dark']))(
    'defines every app variable for the %s scheme',
    (scheme) => {
      for (const name of APP_VARIABLES) {
        expect(resolved[scheme][name]).toBeTruthy();
      }
    },
  );

  test('sets a different body colour per scheme, not Mantine gray', () => {
    expect(resolved.light['--mantine-color-body']).toBe(SURFACES.light.page);
    expect(resolved.dark['--mantine-color-body']).toBe(SURFACES.dark.page);
    expect(resolved.dark['--mantine-color-body']).not.toBe(resolved.light['--mantine-color-body']);
  });

  test('the page background layers glows over the base colour', () => {
    expect(resolved.light['--app-page-bg']).toContain('radial-gradient');
    expect(resolved.light['--app-page-bg']).toContain(SURFACES.light.page);
    expect(resolved.dark['--app-page-bg']).toContain(SURFACES.dark.page);
  });
});
