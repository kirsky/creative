const path = require('node:path');
const HtmlWebpackPlugin = require('html-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const { FontPreloadPlugin } = require('./scripts/font-preload');

/**
 * `npm run build` runs with --mode production, `npm run watch` with --mode development --watch.
 * Both write to frontend/dist, which nginx serves; there is no dev server or HMR.
 * @param {unknown} _env
 * @param {{mode?: string}} argv
 */
module.exports = (_env, argv) => {
  const isProduction = argv.mode === 'production';

  return {
    entry: './src/index.jsx',
    output: {
      path: path.resolve(__dirname, 'dist'),
      filename: isProduction ? '[name].[contenthash].js' : '[name].js',
      publicPath: '/',
      // Empties dist before each build but keeps the .gitkeep placeholder.
      clean: { keep: /\.gitkeep$/ },
    },
    devtool: isProduction ? false : 'source-map',
    resolve: { extensions: ['.jsx', '.js'] },
    module: {
      rules: [
        {
          test: /\.jsx?$/,
          exclude: /node_modules/,
          // Babel's env follows the build, not NODE_ENV (unset here): in its default env,
          // "development", JSX compiles to jsxDEV, which production React does not have.
          use: { loader: 'babel-loader', options: { envName: argv.mode ?? 'production' } },
        },
        { test: /\.css$/, use: [MiniCssExtractPlugin.loader, 'css-loader'] },
        { test: /\.scss$/, use: [MiniCssExtractPlugin.loader, 'css-loader', 'sass-loader'] },
        {
          test: /\.woff2$/,
          type: 'asset/resource',
          generator: { filename: 'fonts/[name].[contenthash][ext]' },
        },
      ],
    },
    plugins: [
      new HtmlWebpackPlugin({ template: './src/index.html' }),
      new FontPreloadPlugin(HtmlWebpackPlugin),
      new MiniCssExtractPlugin({
        filename: isProduction ? '[name].[contenthash].css' : '[name].css',
      }),
    ],
    // The Mantine bundle is well above webpack's default 244 KiB hint.
    performance: { hints: false },
  };
};
