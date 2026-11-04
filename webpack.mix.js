const mix = require('laravel-mix');
const ipv4 = require('internal-ip').v4.sync();
const argv = process.argv;

let mixBuildName = function (str) {
    if (typeof str !== "string") {
        return str;
    }
    if (/resources_assets_js_pages_(.*?)_vue/.test(str)) {
        str = /resources_assets_js_pages_(.*?)_vue/.exec(str)[1];
    }
    return str.replace(/_/g, '/');
}

let isHot = argv.includes('--hot');
let isElectron = argv.includes('--electron');
let publicPath = (!isHot && isElectron) ? 'electron/public' : 'public';

mix
    .js('resources/assets/js/app.js', 'js')
    .sass('resources/assets/sass/app.scss', 'css')
    .setPublicPath(publicPath)
    .webpackConfig(() => {
        let config = {
            output: {
                filename: (pathData) => {
                    let name = pathData.chunk && pathData.chunk.name ? pathData.chunk.name : 'app';
                    return name.replace(/^\//, '') + '.js';
                },
                chunkFilename: (pathData) => {
                    const id = pathData && pathData.chunk ? pathData.chunk.id : (pathData ? pathData.id : undefined);
                    return `js/build/${mixBuildName(id)}.js`;
                }
            }
        };
        if (isElectron && !isHot) {
            config.output.publicPath = './'
        } else {
            // Web 模式：publicPath 必须为 "/"，让浏览器从站点根路径加载
            // 懒加载 chunk（/js/build/*.js）。若设为空字符串 ""，
            // webpack 5 会把异步 chunk 的 runtime publicPath 回退到默认
            // "/dist/"，导致详情页等懒加载组件 chunk 404、白屏。
            config.output.publicPath = '/'
        }
        return config
    })
    .options({
        processCssUrls: false,
        hmrOptions: {
            host: ipv4 || 'localhost',
            port: '22222'
        },
    })
    .vue({
        version: 2,
    });
