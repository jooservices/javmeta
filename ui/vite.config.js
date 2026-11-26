import { defineConfig } from 'vite'; import react from '@vitejs/plugin-react'; import path from 'node:path';
export default defineConfig({
  base: '/', plugins: [react()],
  resolve: { alias: { '@shared/store.js': path.resolve('src/data/live.js') } },
  build: { outDir: 'dist', emptyOutDir: true, chunkSizeWarningLimit: 2500 },
  server: { proxy: { '/api': 'http://127.0.0.1:8000' } },
});
