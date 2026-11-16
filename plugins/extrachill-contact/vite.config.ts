/**
 * External dependencies
 */
import { defineConfig } from 'vite';
import { resolve } from 'path';
import react from '@vitejs/plugin-react';
import type { Plugin } from 'vite';

/**
 * Prefix the minified bundle with an ESLint directive. The bundle is generated
 * output, not source; source lives in src/ and is linted there. Runs after
 * minification (generateBundle) so the comment is not stripped.
 */
function eslintDisableBanner(): Plugin {
  return {
    name: 'extrachill-contact-eslint-disable-banner',
    generateBundle(_options, bundle) {
      for (const file of Object.values(bundle)) {
        if (file.type === 'chunk') {
          file.code = `/* eslint-disable */\n${file.code}`;
        }
      }
    },
  };
}

export default defineConfig({
  plugins: [
    react({
      jsxRuntime: 'classic',
    }),
    eslintDisableBanner(),
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
