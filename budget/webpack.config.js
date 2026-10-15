const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
    entry: {
        'budget-app': path.join(__dirname, 'src', 'main.js'),
    },
    output: {
        path: path.resolve(__dirname, 'js'),
        filename: '[name].js',
        // Views loaded on first visit (see LAZY_MODULES in src/main.js). The
        // file names stay fixed so the set of committed files only changes
        // when a view is added; the hash in the query string is what makes a
        // browser fetch the new copy after an update. The public path is set
        // at runtime (src/publicPath.js).
        chunkFilename: 'budget-[name].js?v=[contenthash:8]',
    },
    optimization: {
        // No automatically split shared/vendor chunks: they get generated
        // names that change with the code, and every file here is committed
        // and shipped. A module one lazy view shares with the main bundle is
        // taken from the main bundle anyway.
        splitChunks: false,
    },
    devtool: 'source-map',
    module: {
        rules: [
            {
                test: /\.js$/,
                exclude: /node_modules/,
                use: {
                    loader: 'babel-loader',
                    options: {
                        presets: ['@babel/preset-env']
                    }
                }
            },
            {
                test: /\.css$/,
                use: [MiniCssExtractPlugin.loader, 'css-loader']
            }
        ]
    },
    plugins: [
        new MiniCssExtractPlugin({
            filename: '../css/[name].css'
        })
    ],
    resolve: {
        extensions: ['.js'],
        modules: [
            path.join(__dirname, 'src'),
            'node_modules'
        ]
    }
};