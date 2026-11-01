import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { refreshConfig, SAFE_DEFAULTS } from '../../deploy/browser-services-config.mjs';

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
