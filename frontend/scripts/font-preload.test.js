const { fontPreloadTags } = require('./font-preload');

const ASSETS = [
  'fonts/onest-latin-wght-normal.abc.woff2',
  'fonts/onest-cyrillic-wght-normal.def.woff2',
  'fonts/onest-latin-ext-wght-normal.x.woff2',
  'fonts/onest-cyrillic-ext-wght-normal.y.woff2',
  'main.js',
];

describe('fontPreloadTags', () => {
  test('preloads only the latin and cyrillic variable font files', () => {
    const tags = fontPreloadTags(ASSETS, '/');
    expect(tags.map((tag) => tag.attributes.href)).toEqual([
      '/fonts/onest-latin-wght-normal.abc.woff2',
      '/fonts/onest-cyrillic-wght-normal.def.woff2',
    ]);
  });

  test('each tag is a font preload with crossorigin', () => {
    for (const tag of fontPreloadTags(ASSETS, '/')) {
      expect(tag.tagName).toBe('link');
      expect(tag.voidTag).toBe(true);
      expect(tag.attributes).toMatchObject({ rel: 'preload', as: 'font', type: 'font/woff2' });
      expect(tag.attributes.crossorigin).toBeTruthy();
    }
  });

  test('returns nothing when the build has no Onest font', () => {
    expect(fontPreloadTags(['main.js', 'main.css'], '/')).toEqual([]);
  });

  test('prefixes the public path', () => {
    const [tag] = fontPreloadTags(ASSETS, '/static/');
    expect(tag.attributes.href).toBe('/static/fonts/onest-latin-wght-normal.abc.woff2');
  });
});
