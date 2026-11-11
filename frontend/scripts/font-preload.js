/** The Onest files worth preloading: the variable font's Latin and Cyrillic subsets. */
const PRELOADED_FONT = /(^|\/)onest-(latin|cyrillic)-wght-normal\.[^/]*woff2$/;

/**
 * `<link rel="preload">` tags for the main font files, so the page does not paint in a fallback
 * font first. `crossorigin` is required even for same-origin fonts.
 * @param {string[]} assetNames emitted asset paths, relative to the output directory
 * @param {string} publicPath
 */
function fontPreloadTags(assetNames, publicPath) {
  return assetNames
    .filter((name) => PRELOADED_FONT.test(name))
    .sort((a, b) => Number(b.includes('latin')) - Number(a.includes('latin')))
    .map((name) => ({
      tagName: 'link',
      voidTag: true,
      attributes: {
        rel: 'preload',
        as: 'font',
        type: 'font/woff2',
        crossorigin: 'anonymous',
        href: `${publicPath}${name}`,
      },
    }));
}

/** Adds the font preload tags to the HTML that HtmlWebpackPlugin writes. */
class FontPreloadPlugin {
  /**
   * @param {import('html-webpack-plugin')} HtmlWebpackPlugin the plugin class, for its hooks
   */
  constructor(HtmlWebpackPlugin) {
    this.HtmlWebpackPlugin = HtmlWebpackPlugin;
  }

  /** @param {import('webpack').Compiler} compiler */
  apply(compiler) {
    compiler.hooks.compilation.tap('FontPreloadPlugin', (compilation) => {
      this.HtmlWebpackPlugin.getHooks(compilation).alterAssetTagGroups.tap(
        'FontPreloadPlugin',
        (data) => {
          const names = Object.keys(compilation.assets);
          data.headTags.push(...fontPreloadTags(names, data.publicPath));
          return data;
        },
      );
    });
  }
}

module.exports = { fontPreloadTags, FontPreloadPlugin };
