import test from 'node:test';
import assert from 'node:assert/strict';
import {validateAudioFile, wavBlob, MAX_FILE_BYTES} from '../src/audio.js';
import {planQuizParts, questionAllocation} from '../src/quizplan.js';
import {buildUserPrompt} from '../src/prompt.js';
import {generateQuiz} from '../src/gemini.js';
import {Pipeline} from '../src/pipeline.js';

test('audio allowlist: reject PDF/video and mismatching MIME, even with an audio suffix', () => {
  for (const file of [{name: 'lesson.pdf', type: 'application/pdf', size: 10},
    {name: 'lesson.mp4', type: 'video/mp4', size: 10}, {name: 'fake.mp3', type: 'application/pdf', size: 10}]) {
    assert.throws(() => validateAudioFile(file));
  }
  for (const [name, type] of [['a.MP3', 'audio/mpeg'], ['a.wav', 'audio/x-wav'],
    ['a.m4a', 'audio/mp4'], ['a.ogg', 'audio/ogg; codecs=opus'], ['a.mp3', '']]) {
    assert.doesNotThrow(() => validateAudioFile({name, type, size: 100}));
  }
  assert.throws(() => validateAudioFile({name: 'a.mp3', size: 0}));
  assert.throws(() => validateAudioFile({name: 'a.mp3', size: MAX_FILE_BYTES + 1}));
  assert.throws(() => validateAudioFile({name: 'a.mp3', size: 100}, 50));
});

test('recording is a valid mono 16 kHz PCM WAV with clamped samples', async () => {
  const blob = wavBlob(Float32Array.from([-2, -1, 0, 1, 2]));
  const bytes = await blob.arrayBuffer(), view = new DataView(bytes);
  assert.equal(new TextDecoder().decode(bytes.slice(0, 4)), 'RIFF');
  assert.equal(view.getUint32(24, true), 16000);
  assert.equal(view.getUint16(22, true), 1);
  assert.equal(view.getUint16(34, true), 16);
  assert.equal(view.getUint32(40, true), 10);
  assert.deepEqual(Array.from({length: 5}, (_, index) => view.getInt16(44 + index * 2, true)), [-32768, -32768, 0, 32767, 32767]);
});

test('requesting fewer questions merges adjacent parts without dropping the transcript', () => {
  const parts = Array.from({length: 8}, (_, index) => ({index: index + 1, start: index * 30,
    end: (index + 1) * 30, text: `transcript-${index}`}));
  const planned = planQuizParts(parts, 3);
  assert.equal(planned.length, 3);
  assert.equal(planned[0].start, 0); assert.equal(planned[2].end, 240);
  for (const part of parts) assert.ok(planned.some(item => item.text.includes(part.text)));
  assert.deepEqual(questionAllocation(planned, 10), [4, 3, 3]);
  assert.match(buildUserPrompt(planned, {questionCount: 10}), /Tổng cộng ĐÚNG 10 câu/);
  assert.throws(() => planQuizParts(parts, 0));
  assert.throws(() => planQuizParts(parts, 31));
});

test('wrong AI question count is retried; only the requested total is accepted', async () => {
  const question = {question: 'Q?', options: ['a', 'b', 'c', 'd'], correct_answer: 'b', explanation: 'E'};
  const content = count => ({summary: {short_summary: 's', main_points: ['p']},
    segments: [{segment_summary: 's', quiz: Array.from({length: count}, () => question)}]});
  let calls = 0;
  const result = await generateQuiz({apiKey: 'not-real', parts: [{index: 1, start: 0, end: 60, text: 'lesson'}],
    questionCount: 2, sleep: async () => {}, fetchImpl: async () => ({ok: true, json: async () => ({
      candidates: [{finishReason: 'STOP', content: {parts: [{text: JSON.stringify(content(++calls))}]}}],
    })})});
  assert.equal(calls, 2); assert.equal(result.content.segments[0].quiz.length, 2);
});

test('cancelling during model initialisation rejects start instead of leaving it pending', async () => {
  const workers = [];
  const pipeline = new Pipeline({distUrl: '/', models: {}, createWorker: () => {
    const worker = {postMessage() {}, terminate() { this.dead = true; }}; workers.push(worker); return worker;
  }});
  const start = pipeline.start(); pipeline.cancel();
  await assert.rejects(start, error => error.name === 'AbortError');
  assert.ok(workers.every(worker => worker.dead));
});
