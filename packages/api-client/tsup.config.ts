import { defineConfig } from 'tsup';

export default defineConfig([
  {
    entry: {
      index: 'src/index.ts',
      wordpress: 'src/wordpress.ts',
    },
    format: ['cjs', 'esm'],
    dts: true,
    clean: true,
    external: ['@wordpress/api-fetch'],
    splitting: false,
    sourcemap: true,
  },
]);
