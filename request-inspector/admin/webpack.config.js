const path = require('path');
const defaults = require('@wordpress/scripts/config/webpack.config');
module.exports = {
  ...defaults,
  entry: { index: path.resolve(__dirname, 'src/index.tsx'), live: path.resolve(__dirname, 'src/live.ts') },
  output: { ...defaults.output, chunkFilename: '[name].[contenthash].js', clean: true },
};
