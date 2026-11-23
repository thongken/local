/** Rebuild only the customised activity chooser and this plugin's AMD modules using local esbuild. */
import {build} from 'esbuild';
import {readFileSync, mkdirSync} from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const frontend = path.dirname(fileURLToPath(import.meta.url));
const plugin = path.resolve(frontend, '..');
const publicRoot = path.resolve(plugin, '../..');
const moduleName = 'core_courseformat/local/activitychooser/dialogue';
const source = path.join(publicRoot, 'course/format/amd/src/local/activitychooser/dialogue.js');
const out = path.join(publicRoot, 'course/format/amd/build/local/activitychooser/dialogue.min.js');
// Moodle normally transforms import(pluginName) into AMD require. Preserve that behaviour with esbuild.
const text = readFileSync(source, 'utf8').replace('import(pluginName)',
  'new Promise((resolve, reject) => require([pluginName], resolve, reject))');
const options = {stdin: {contents: text, resolveDir: path.dirname(source), sourcefile: source},
  format: 'cjs', platform: 'browser', bundle: true, packages: 'external', target: 'es2022',
  minify: true, legalComments: 'none', logLevel: 'warning', tsconfigRaw: {}};
const preview = await build({...options, write: false, metafile: true});
const deps = [...new Set(Object.values(preview.metafile.outputs).flatMap(output => output.imports)
  .filter(item => item.external).map(item => item.path))];
await build({...options, outfile: out, sourcemap: 'external',
  banner: {js: `define(${JSON.stringify(moduleName)},${JSON.stringify(['require', 'exports', 'module', ...deps])},function(require,exports,module){`},
  footer: {js: '});'}});

mkdirSync(path.join(plugin, 'amd/build'), {recursive: true});
for (const name of ['loader', 'quizchooser']) {
  const contents = readFileSync(path.join(plugin, `amd/src/${name}.js`), 'utf8')
    .replace('define([', `define('local_microlearning/${name}', [`);
  await build({stdin: {contents, sourcefile: `../amd/src/${name}.js`},
    outfile: path.join(plugin, `amd/build/${name}.min.js`), sourcemap: 'external',
    minify: true, target: 'es2022', legalComments: 'none'});
}
console.log('Built customised activity chooser, loader and quizchooser AMD modules.');
