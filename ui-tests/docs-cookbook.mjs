// Exercise the published curl commands on loopback; never use a real key or staging.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
const root = new URL('../', import.meta.url);
const examples = JSON.parse(await readFile(new URL('tools/docs/api-examples.json', root), 'utf8'));
const script = await readFile(new URL('tools/docs/api-cookbook.sh', root), 'utf8');
const doc = await readFile(new URL('docs/developer-guide/api-cookbook.md', root), 'utf8');
assert.ok(doc.includes('```bash\n' + script + '```'), 'published commands match the exercised script');
const received = [];
const server = createServer(async (req, res) => {
  let body = ''; for await (const chunk of req) body += chunk;
  received.push({ method: req.method, path: req.url, ...(body ? { body: JSON.parse(body) } : {}), ...(req.headers['x-owner-token'] ? { owner: true } : {}) });
  assert.equal(req.headers['x-owner-token'] || req.headers['x-api-key'], 'fixture');
  res.writeHead(200, { 'Content-Type': 'application/json' }); res.end('{"data":[]}');
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
  await new Promise((resolve, reject) => {
    const child = spawn('bash', ['tools/docs/api-cookbook.sh'], { cwd: root, env: { ...process.env, BASE: 'http://127.0.0.1:' + server.address().port, KEY: 'fixture', OWNER_TOKEN: 'fixture' }, stdio: ['ignore', 'ignore', 'pipe'] });
    let stderr = ''; child.stderr.on('data', chunk => { stderr += chunk; });
    child.on('error', reject); child.on('close', code => code === 0 ? resolve() : reject(new Error(stderr)));
  });
  assert.deepEqual(received, examples);
  console.log(`Cookbook: ${received.length} curl requests verified; application behavior is covered by ApiCookbookTest.`);
} finally { await new Promise(resolve => server.close(resolve)); }
