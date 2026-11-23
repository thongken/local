/** Rebuild plugin AMD modules and the customised Moodle 5.3 activitychooser dialogue module. */
import {build} from 'esbuild';
import {readFileSync, mkdirSync} from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const pluginRoot = path.dirname(fileURLToPath(import.meta.url));

// 1. Build plugin AMD modules (amd/src/*.js -> amd/build/*.min.js).
mkdirSync(path.join(pluginRoot, 'amd/build'), {recursive: true});
for (const name of ['quizchooser', 'coursequiz', 'personalpdf']) {
    const srcPath = path.join(pluginRoot, `amd/src/${name}.js`);
    const contents = readFileSync(srcPath, 'utf8')
        .replace('define([', `define('local_pdfquiz/${name}', [`);
    await build({
        stdin: {contents, sourcefile: `../amd/src/${name}.js`},
        outfile: path.join(pluginRoot, `amd/build/${name}.min.js`),
        sourcemap: 'external',
        minify: true,
        target: 'es2022',
        legalComments: 'none',
    });
}

// 2. Build patched Moodle core activitychooser dialogue module inside moodle-core/.
const moduleName = 'core_courseformat/local/activitychooser/dialogue';
const coreSrc = path.join(pluginRoot, 'moodle-core/public/course/format/amd/src/local/activitychooser/dialogue.js');
const coreOut = path.join(pluginRoot, 'moodle-core/public/course/format/amd/build/local/activitychooser/dialogue.min.js');
mkdirSync(path.dirname(coreOut), {recursive: true});

const coreText = readFileSync(coreSrc, 'utf8').replace(
    'import(pluginName)',
    'new Promise((resolve, reject) => require([pluginName], resolve, reject))'
);
const options = {
    stdin: {contents: coreText, resolveDir: path.dirname(coreSrc), sourcefile: coreSrc},
    format: 'cjs',
    platform: 'browser',
    bundle: true,
    packages: 'external',
    target: 'es2022',
    minify: true,
    legalComments: 'none',
    logLevel: 'warning',
    tsconfigRaw: {},
};
const preview = await build({...options, write: false, metafile: true});
const deps = [...new Set(
    Object.values(preview.metafile.outputs)
        .flatMap(output => output.imports)
        .filter(item => item.external)
        .map(item => item.path)
)];
await build({
    ...options,
    outfile: coreOut,
    sourcemap: 'external',
    banner: {
        js: `define(${JSON.stringify(moduleName)},${JSON.stringify(['require', 'exports', 'module', ...deps])},function(require,exports,module){`,
    },
    footer: {js: '});'},
});

console.log('Built local_pdfquiz AMD modules and moodle-core dialogue.min.js.');

