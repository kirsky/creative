const path = require('node:path');
const HtmlWebpackPlugin = require('html-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

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
        { test: /\.jsx?$/, exclude: /node_modules/, use: 'babel-loader' },
        { test: /\.css$/, use: [MiniCssExtractPlugin.loader, 'css-loader'] },
        { test: /\.scss$/, use: [MiniCssExtractPlugin.loader, 'css-loader', 'sass-loader'] },
      ],
    },
    plugins: [
      new HtmlWebpackPlugin({ template: './src/index.html' }),
      new MiniCssExtractPlugin({
        filename: isProduction ? '[name].[contenthash].css' : '[name].css',
      }),
    ],
    // The Mantine bundle is well above webpack's default 244 KiB hint.
    performance: { hints: false },
  };
};
