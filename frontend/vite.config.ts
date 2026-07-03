import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

// Build multi-entry: admin (wp-admin SPA), portal (/mi-cuenta) y checkout.
// El manifest lo consume PHP para encolar los assets con hash.
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@shared': resolve(__dirname, 'src/shared'),
    },
  },
  build: {
    manifest: true,
    outDir: 'dist',
    rollupOptions: {
      input: {
        admin: resolve(__dirname, 'src/admin/main.tsx'),
        portal: resolve(__dirname, 'src/portal/main.tsx'),
        checkout: resolve(__dirname, 'src/checkout/main.tsx'),
      },
    },
  },
});
