import { defineConfig } from 'vite'; import react from '@vitejs/plugin-react'; import path from 'node:path';
// The build is served by Laravel from public/web (assets under /web/); the dev server keeps the root.
export default defineConfig(({ command }) => ({
  base: command === 'build' ? '/web/' : '/', plugins: [react()],
  resolve: { alias: { '@shared/store.js': path.resolve('src/data/live.js') } },
  build: { outDir: '../public/web', emptyOutDir: true, chunkSizeWarningLimit: 2500 },
  server: { proxy: { '/api': 'http://127.0.0.1:8000' } },
  test: { environment: 'node', include: ['tests/**/*.test.js'] },
}));
