import { defineConfig } from 'vite';
import { resolve } from 'path';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [
    react({
      jsxRuntime: 'classic',
    }),
  ],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    lib: {
      entry: resolve(__dirname, 'src/wordpress.tsx'),
      formats: ['iife'],
      fileName: () => 'contact-form.iife.js',
      name: 'ExtraChillContactForm',
    },
    rollupOptions: {
      external: ['react', 'react-dom', 'react-dom/client'],
      output: {
        globals: {
          'react': 'React',
          'react-dom': 'ReactDOM',
          'react-dom/client': 'ReactDOM',
        },
        assetFileNames: 'contact-form.[ext]',
      },
    },
    cssCodeSplit: false,
    outDir: 'assets',
    emptyOutDir: false,
  },
});
