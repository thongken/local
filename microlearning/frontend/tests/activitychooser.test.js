import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

/** Exercise the shipped core AMD module while replacing only its DOM/transport collaborators. */
async function chooser({pluginAvailable = true} = {}) {
  let captured, exports;
  const calls = [];
  const window = {location: {href: 'https://moodle.test/course/view.php?id=2'}};
  const node = {on() {}, addEventListener() {}};
  const modal = {getRoot: () => node, getFooter: () => node,
    getBodyPromise: async () => {}, getFooterPromise: async () => {}};
  class Dom {
    constructor(instance) {
      captured = instance;
      // Event wiring/focus is outside this routing test; keep the production submit method intact.
      instance.registerModalListenerEvents = () => {};
      instance.setupKeyboardAccessibility = () => {};
    }
    unmarkAllChooserOptionAsSelected() {}
    getClosestChooserOption(target) { return target; }
    markChooserOptionAsSelected() {}
    getChooserOptionUrl(target) { return target.url; }
  }
  class Exporter {
    getFooterData() { return {}; }
    async getModChooserTemplateData() { return {}; }
  }
  const dependencies = {
    'core_courseformat/local/activitychooser/dialoguedom': Dom,
    'core_courseformat/local/activitychooser/exporter': Exporter,
    'core/modal': {create: () => modal}, 'core/str': {getString: () => 'Title'},
    'core/templates': {render: () => ''}, 'core/normalise': {getFirst: value => value},
    'core/deprecated': () => {}, 'core/notification': {exception: error => { throw error; }},
    'local_microlearning/quizchooser': {open: async (...args) => { calls.push(args); }},
  };
  function require(name, resolve, reject) {
    if (Array.isArray(name)) {
      if (pluginAvailable) resolve(dependencies[name[0]]); else reject(new Error('Optional plugin unavailable'));
      return;
    }
    return dependencies[name] || {};
  }
  const code = readFileSync(new URL('../../../../course/format/amd/build/local/activitychooser/dialogue.min.js', import.meta.url), 'utf8');
  vm.runInNewContext(code, {window, URL, console,
    define(name, deps, factory) {
      const module = {exports: {}};
      factory(require, module.exports, module);
      exports = module.exports;
    },
  });
  await exports.displayActivityChooserModal(Promise.resolve({}), Promise.resolve([]));
  await new Promise(resolve => setImmediate(resolve));
  return {calls, window, submit: url => captured.submitAddSelectedModule({url, getAttribute: () => null})};
}

test('core chooser intercepts Quiz once and opens the plugin', async () => {
  const harness = await chooser();
  const target = 'https://moodle.test/course/modedit.php?id=2&add=quiz&section=1';
  harness.submit(target); harness.submit(target);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(harness.calls.length, 1); assert.equal(harness.calls[0][0], target);
  assert.equal(harness.window.location.href, 'https://moodle.test/course/view.php?id=2');
});

test('other activity types retain their original Moodle URL', async () => {
  const harness = await chooser();
  const target = 'https://moodle.test/course/modedit.php?id=2&add=resource&section=1';
  harness.submit(target);
  assert.equal(harness.window.location.href, target); assert.equal(harness.calls.length, 0);
});

test('missing optional plugin falls back to the standard Quiz form', async () => {
  const harness = await chooser({pluginAvailable: false});
  const target = 'https://moodle.test/course/modedit.php?id=2&add=quiz&section=1';
  harness.submit(target);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(harness.window.location.href, target);
});
