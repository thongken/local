import { build } from 'esbuild';
import { cpSync, mkdirSync, readdirSync, existsSync, writeFileSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const dist = path.resolve(here, process.env.DIST_DIR || '../dist');
const pluginRoot = path.resolve(here, '..');
if (dist === pluginRoot || !dist.startsWith(pluginRoot + path.sep)) {
  throw new Error('DIST_DIR phải nằm trong thư mục plugin microlearning và không được là chính thư mục plugin.');
}
rmSync(dist, { recursive: true, force: true });
mkdirSync(path.join(dist, 'ort'), { recursive: true });

const entries = ['app.js', 'coursequiz.js', 'narrationaudio.js', 'stt.worker.js', 'vad.worker.js', 'mic-worklet.js']
  .filter((f) => existsSync(path.join(here, 'src', f)))
  .map((f) => path.join(here, 'src', f));

await build({
  entryPoints: entries, outdir: dist, bundle: true, format: 'esm', platform: 'browser',
  target: 'es2022', minify: true, logLevel: 'info', legalComments: 'none', tsconfigRaw: {},
});

// WASM + loader của ONNX Runtime: phục vụ từ chính máy chủ Moodle, không dùng CDN.
const ortDir = path.join(here, 'node_modules/onnxruntime-web/dist');
for (const f of readdirSync(ortDir)) {
  if (/^ort-wasm-simd-threaded(\.jsep)?\.(mjs|wasm)$/.test(f)) cpSync(path.join(ortDir, f), path.join(dist, 'ort', f));
}
const sileroSrc = [
  path.join(here, 'assets/silero_vad_v5.onnx'),
  path.join(here, 'node_modules/@ricky0123/vad-web/dist/silero_vad_v5.onnx'),
].find(existsSync);
if (!sileroSrc) throw new Error('Thiếu silero_vad_v5.onnx. Chạy: npm install --save-dev @ricky0123/vad-web@0.0.24');
cpSync(sileroSrc, path.join(dist, 'silero_vad_v5.onnx'));
cpSync(path.join(here, 'src/style.css'), path.join(dist, 'style.css'));
writeFileSync(path.join(dist, '.htaccess'), 'AddType application/wasm .wasm\nAddType text/javascript .mjs .js\n');
console.log('Build xong ->', dist);
