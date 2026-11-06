const mix = require('laravel-mix');
const ipv4 = require('internal-ip').v4.sync();
const argv = process.argv;
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');

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
                // 打包产物中的模块标识前缀与 chunk 加载数组名，默认都由包名推导
                // （DooTask: / webpackChunkDooTask）。显式改名后产物内不再出现
                // 旧品牌标识；必须全量重编，否则新旧分包加载协议不一致。
                uniqueName: 'policeTraining',
                chunkLoadingGlobal: 'webpackChunkPoliceTraining',
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
    })
    .then(() => {
        // 构建完成后刷新 public/js/hash
        //
        // main.blade.php 用「?v={版本}-{哈希}」引用 app.js / app.css，而该哈希原先是
        // 仓库自带的静态文件（2022 年起从未变过）。nginx 对静态资源设了 30 天强缓存，
        // 哈希不变意味着 URL 不变，浏览器会一直复用旧脚本——即使服务器已换成新文件。
        // 因此这里把 app.js 的内容哈希写入该文件，保证每次构建都得到新的 URL。
        const target = path.resolve(__dirname, publicPath, 'js/app.js');
        const hash = crypto.createHash('md5').update(fs.readFileSync(target)).digest('hex').slice(0, 16);
        fs.writeFileSync(path.resolve(__dirname, publicPath, 'js/hash'), hash);
        console.log('  资源哈希已更新: ' + hash);
    });
