import { defineConfig } from '@playwright/test';

// By default the vite dev server (proxying /api to Laravel on :8000). With E2E_BASE_URL (e.g. http://127.0.0.1:8000) the tests run
// against the built UI served by Laravel itself, exactly as production serves it.
const external = process.env.E2E_BASE_URL;

// Browser end-to-end tests: the built UI (vite dev server) against the real Laravel API on :8000 (ui/e2e/serve.sh).
export default defineConfig({
  testDir: './e2e/tests',
  timeout: 60_000,
  expect: { timeout: 15_000 },
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: external || 'http://127.0.0.1:5173',
    trace: 'off',
    launchOptions: process.env.PLAYWRIGHT_CHROMIUM_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH, args: ['--no-sandbox'] } : { args: ['--no-sandbox'] },
  },
  webServer: external ? undefined : { command: 'npm run dev -- --host 127.0.0.1 --port 5173 --strictPort', url: 'http://127.0.0.1:5173', reuseExistingServer: true, timeout: 60_000 },
});
