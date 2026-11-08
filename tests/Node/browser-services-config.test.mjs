import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { EventEmitter } from 'node:events';
import { refreshConfig, run, SAFE_DEFAULTS } from '../../deploy/browser-services-config.mjs';

const config = { ...structuredClone(SAFE_DEFAULTS), version: 'a1', poll_interval_seconds: 30 };
const response = (status, data = null, etag = '"a1"') => ({
  status,
  ok: status >= 200 && status < 300,
  headers: { get: name => name === 'etag' ? etag : null },
  json: async () => data,
});

test('poll sends API scope credential and ETag, and atomically caches an updated response', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'javmeta-browser-config-'));
  const cacheFile = join(directory, 'cache', 'browser-services.json');
  let request;
  try {
    const result = await refreshConfig({
      url: 'https://xcrawler.test/api/v1/config/browser-services',
      apiKey: 'test-key',
      cacheFile,
      fetchImpl: async (url, options) => { request = { url, options }; return response(200, { data: config }); },
    });
    assert.equal(result.status, 'updated');
    assert.equal(request.options.headers['X-API-Key'], 'test-key');
    assert.equal((await readFile(cacheFile, 'utf8')).includes('a1'), true);
    assert.equal(request.options.redirect, 'error');
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test('poll keeps the last good cache on 304 and on errors, and seeds safe defaults before first success', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'javmeta-browser-config-'));
  const cacheFile = join(directory, 'browser-services.json');
  try {
    await refreshConfig({ url: 'https://xcrawler.test/api/v1/config/browser-services', apiKey: 'test-key', cacheFile, fetchImpl: async () => response(200, { data: config }) });
    const unchanged = await refreshConfig({ url: 'https://xcrawler.test/api/v1/config/browser-services', apiKey: 'test-key', cacheFile, fetchImpl: async (_url, options) => { assert.equal(options.headers['If-None-Match'], '"a1"'); return response(304); } });
    assert.equal(unchanged.status, 'unchanged');
    const failed = await refreshConfig({ url: 'https://xcrawler.test/api/v1/config/browser-services', apiKey: 'test-key', cacheFile, fetchImpl: async () => { throw new Error('offline'); } });
    assert.equal(failed.status, 'stale');
    assert.deepEqual(failed.data, config);
    await rm(cacheFile);
    const safe = await refreshConfig({ url: 'https://xcrawler.test/api/v1/config/browser-services', apiKey: 'test-key', cacheFile, fetchImpl: async () => { throw new Error('offline'); } });
    assert.equal(safe.data.version, 'safe-defaults');
    assert.deepEqual(JSON.parse(await readFile(cacheFile, 'utf8')), SAFE_DEFAULTS);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test('the polling loop stops at once on SIGTERM instead of finishing its wait, and then removes its handlers', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'javmeta-browser-run-'));
  const signals = new EventEmitter();
  const polls = [];
  try {
    const started = Date.now();
    const loop = run({
      signals,
      env: { JAVMETA_CONFIG_CACHE_FILE: join(directory, 'cache.json') },
      refresh: async options => { polls.push(options.timeoutMs); return { status: 'unchanged', data: { ...structuredClone(SAFE_DEFAULTS), poll_interval_seconds: 300 } }; },
    });
    await new Promise(resolve => setTimeout(resolve, 100));
    assert.equal(polls.length, 1, 'It polled once and is now waiting out a 300 second interval.');
    assert.equal(signals.listenerCount('SIGTERM'), 1);
    signals.emit('SIGTERM');
    await loop;
    assert.ok(Date.now() - started < 2000, 'The stop did not wait for the poll interval.');
    assert.equal(polls.length, 1, 'No further poll after the signal.');
    assert.equal(signals.listenerCount('SIGTERM') + signals.listenerCount('SIGINT'), 0);
    assert.equal(JSON.parse(await readFile(join(directory, 'cache.json'), 'utf8')).version, SAFE_DEFAULTS.version, 'Safe defaults are cached before the first success.');
  } finally { await rm(directory, { recursive: true, force: true }); }
});

test('SIGINT stops the loop the same way and a poll error is not swallowed silently', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'javmeta-browser-run-'));
  const signals = new EventEmitter();
  try {
    await assert.rejects(run({ signals, env: { JAVMETA_CONFIG_CACHE_FILE: join(directory, 'cache.json') }, refresh: async () => { throw new Error('config api down'); } }), /config api down/);
    assert.equal(signals.listenerCount('SIGINT'), 0, 'Handlers are removed even when the loop fails.');

    const loop = run({ signals, env: { JAVMETA_CONFIG_CACHE_FILE: join(directory, 'cache.json') }, refresh: async () => ({ data: { ...structuredClone(SAFE_DEFAULTS), poll_interval_seconds: 300 } }) });
    await new Promise(resolve => setTimeout(resolve, 50));
    signals.emit('SIGINT');
    await loop;
  } finally { await rm(directory, { recursive: true, force: true }); }
});
