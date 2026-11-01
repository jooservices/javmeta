import { chmod, mkdir, readFile, rename, rm, writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { dirname } from 'node:path';
import { randomUUID } from 'node:crypto';

export const SAFE_DEFAULTS = Object.freeze({
  concurrency: { browser_max: 1 },
  queues: { browser_max: 10, crawl_depth_max: 10 },
  timeouts: {
    playwright_request_ms: 30000,
    flaresolverr_max_ms: 30000,
    gateway_wait_ms: 40000,
    gateway_client_ms: 45000,
    config_client_ms: 5000,
  },
  thresholds: { max_requests_per_browser: 10, client_inflight: 1, lane_browser_percent: 50, lane_min_attempts: 40 },
  retry: { queue_tries: 1, backoff_seconds: 30, crawl_max_attempts: 1 },
  cleanup: { temp_data_max_age_hours: 24, watchdog_stall_after_minutes: 45 },
  poll_interval_seconds: 30,
  version: 'safe-defaults',
});

export async function readConfigFile(cacheFile) {
  try {
    const data = JSON.parse(await readFile(cacheFile, 'utf8'));
    return isConfig(data) ? data : null;
  } catch {
    return null;
  }
}

export async function writeConfigFile(cacheFile, data) {
  await mkdir(dirname(cacheFile), { recursive: true, mode: 0o750 });
  const temporary = `${cacheFile}.${process.pid}.${randomUUID()}.tmp`;
  try {
    await writeFile(temporary, `${JSON.stringify(data)}\n`, { mode: 0o640, flag: 'wx' });
    await chmod(temporary, 0o640);
    await rename(temporary, cacheFile);
  } catch (error) {
    await rm(temporary, { force: true });
    throw error;
  }
}

export async function refreshConfig({ url, apiKey, cacheFile, fetchImpl = fetch, timeoutMs = 5000 }) {
  const previous = await readConfigFile(cacheFile);
  const fallback = previous ?? SAFE_DEFAULTS;
  if (!url || !apiKey) {
    if (previous === null) await writeConfigFile(cacheFile, SAFE_DEFAULTS);
    return { status: 'missing-bootstrap', data: fallback };
  }

  const headers = { Accept: 'application/json', 'X-API-Key': apiKey };
  if (previous?.version && previous.version !== 'safe-defaults') headers['If-None-Match'] = `"${previous.version}"`;
  try {
    const response = await fetchImpl(url, { headers, redirect: 'error', signal: AbortSignal.timeout(timeoutMs) });
    if (response.status === 304 && previous !== null) return { status: 'unchanged', data: previous };
    if (!response.ok) throw new Error('configuration request failed');
    const payload = await response.json();
    if (!isConfig(payload?.data)) throw new Error('configuration response is invalid');
    await writeConfigFile(cacheFile, payload.data);
    return { status: 'updated', data: payload.data };
  } catch {
    if (previous === null) await writeConfigFile(cacheFile, SAFE_DEFAULTS);
    return { status: 'stale', data: fallback };
  }
}

function isConfig(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    && typeof value.version === 'string'
    && Number.isInteger(value.poll_interval_seconds)
    && value.poll_interval_seconds >= 10 && value.poll_interval_seconds <= 300
    && value.concurrency && value.queues && value.timeouts && value.thresholds && value.retry && value.cleanup;
}

async function run() {
  const url = process.env.JAVMETA_CONFIG_API_URL;
  const apiKey = process.env.JAVMETA_CONFIG_API_KEY;
  const cacheFile = process.env.JAVMETA_CONFIG_CACHE_FILE || '/var/cache/xcrawler/browser-services.json';
  let current = await readConfigFile(cacheFile) ?? SAFE_DEFAULTS;
  if (!(await readConfigFile(cacheFile))) await writeConfigFile(cacheFile, current);
  let stopping = false;
  process.on('SIGTERM', () => { stopping = true; });
  process.on('SIGINT', () => { stopping = true; });
  while (!stopping) {
    const result = await refreshConfig({ url, apiKey, cacheFile, timeoutMs: current.timeouts?.config_client_ms || 5000 });
    current = result.data;
    await new Promise(resolve => setTimeout(resolve, current.poll_interval_seconds * 1000));
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  run().catch(() => process.exitCode = 1);
}
