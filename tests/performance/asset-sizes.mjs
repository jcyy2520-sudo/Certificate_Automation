// Run after npm run build: node tests/performance/asset-sizes.mjs
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import path from 'node:path';

const build = path.resolve('public/build');
const manifest = JSON.parse(readFileSync(path.join(build, 'manifest.json'), 'utf8'));
const files = new Set();
function visit(key) {
    const chunk = manifest[key];
    if (!chunk || files.has(chunk.file)) return;
    files.add(chunk.file);
    for (const dependency of chunk.imports || []) visit(dependency);
}
visit('resources/js/app.js');
const js = [...files].filter((file) => file.endsWith('.js'));
console.log(JSON.stringify({
    initialManagementJsBytes: js.reduce((sum, file) => sum + statSync(path.join(build, file)).size, 0),
    initialManagementJsGzipBytes: js.reduce((sum, file) => sum + gzipSync(readFileSync(path.join(build, file))).length, 0),
    cssBytes: statSync(path.join(build, manifest['resources/css/app.css'].file)).size,
    emittedAssetBytes: readdirSync(path.join(build, 'assets')).reduce((sum, file) => sum + statSync(path.join(build, 'assets', file)).size, 0),
}, null, 2));
