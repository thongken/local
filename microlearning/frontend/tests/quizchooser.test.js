import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

function chooserHarness() {
  let plugin, model, hiddenCallback, shown = false, hidden = false;
  const modules = {
    'core/modal': {create: async () => ({show() { assert.ok(hidden); shown = true; }})},
    'core/modal_events': {hidden: 'modal-hidden'},
    'core/templates': {render: async (template, data) => { model = data; return '<cards>'; }},
    'core/str': {get_strings: async strings => strings.map(({key, component}) => `${component}:${key}`)},
    'core/config': {wwwroot: 'https://moodle.test'},
  };
  const window = {location: {href: 'https://moodle.test/course/view.php?id=2'}};
  vm.runInNewContext(readFileSync(new URL('../../amd/build/quizchooser.min.js', import.meta.url), 'utf8'), {
    URL, window,
    define(name, dependencies, factory) { plugin = factory(...dependencies.map(name => modules[name])); },
  });
  const modal = {getRoot: () => ({one(event, callback) { hiddenCallback = callback; }}),
    hide() { hidden = true; hiddenCallback(); }};
  return {plugin, modal, window, state: () => ({model, shown})};
}

test('shipped AMD chooser preserves the normal form and destination for audio, record and PDF', async () => {
  const harness = chooserHarness();
  const original = 'https://moodle.test/course/modedit.php?id=2&add=quiz&section=3&beforemod=17&sr=3';
  await harness.plugin.open(original, harness.modal);
  const {model, shown} = harness.state();
  assert.equal(model.choices.length, 4); assert.ok(shown);
  assert.equal(model.choices[0].url, original);
  assert.equal(model.choices[3].label, 'local_pdfquiz:quizpdf');
  assert.equal(model.choices[3].description, 'local_pdfquiz:quizpdfhelp');
  for (const [index, plugin, kind] of [[1, 'microlearning', 'audio'], [2, 'microlearning', 'record'], [3, 'pdfquiz', null]]) {
    const url = new URL(model.choices[index].url);
    assert.equal(url.pathname, `/local/${plugin}/quiz.php`);
    assert.equal(url.searchParams.get('courseid'), '2');
    assert.equal(url.searchParams.get('section'), '3');
    assert.equal(url.searchParams.get('beforemod'), '17');
    assert.equal(url.searchParams.get('kind'), kind);
  }
});

test('unknown course links fall back to the original Moodle form', async () => {
  const harness = chooserHarness();
  const original = 'https://moodle.test/course/modedit.php?add=quiz';
  await harness.plugin.open(original, harness.modal);
  assert.equal(harness.window.location.href, original);
  assert.equal(harness.state().shown, false);
});

test('Moodle 5.3 section IDs survive the chooser instead of defaulting to section zero', async () => {
  const harness = chooserHarness();
  const original = 'https://moodle.test/course/mod.php?id=2&add=quiz&sectionid=15&beforemod=17';
  await harness.plugin.open(original, harness.modal);
  const choices = harness.state().model.choices;
  assert.equal(choices[0].url, original);
  for (const choice of choices.slice(1)) {
    const url = new URL(choice.url);
    assert.equal(url.searchParams.get('sectionid'), '15');
    assert.equal(url.searchParams.has('section'), false);
    assert.equal(url.searchParams.get('beforemod'), '17');
  }
});
