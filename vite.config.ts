import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  define: {
    'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'production'),
  },
  build: {
    outDir: 'assets/js',
    emptyOutDir: false,
    lib: {
      entry: 'resources/js/admin-license-entry.tsx',
      name: 'LiteSpaceAdmin',
      formats: ['iife'],
      fileName: () => 'admin-license-bundle.js',
    },
    minify: 'esbuild',
    rollupOptions: {
      output: {
        extend: true,
      },
    },
  },
});
