import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const wordpressElementAdapter = fileURLToPath(
    new URL('./src/AdminApp/wordpress-element.ts', import.meta.url),
  );

  return {
    plugins: [react({ jsxImportSource: 'vfg-wordpress-element' })],
    resolve: {
      alias: [
        {
          find: '@wordpress/i18n',
          replacement: fileURLToPath(new URL('./src/AdminApp/wordpress-i18n.ts', import.meta.url)),
        },
        { find: /^vfg-wordpress-element\/jsx-runtime$/, replacement: wordpressElementAdapter },
        { find: /^vfg-wordpress-element\/jsx-dev-runtime$/, replacement: wordpressElementAdapter },
      ],
    },
    base: './',
    server: {
      host: '127.0.0.1',
      port: 5174,
      strictPort: true,
      origin: env.VFG_DEV_ORIGIN || 'http://localhost:5174',
      cors: { origin: env.VFG_WP_ORIGIN || 'http://veridis-invoicing-dev.local' },
    },
    build: {
      outDir: 'build',
      emptyOutDir: true,
      manifest: 'manifest.json',
      minify: 'terser',
      terserOptions: {
        // Keep only the WordPress gettext API names stable for WP-CLI while
        // allowing all other production identifiers to be minified normally.
        mangle: {
          reserved: ['__', '_n', '_nx', '_x', 'sprintf'],
        },
        // WP-CLI associates these comments with the adjacent gettext call.
        format: {
          comments: /translators:|@license|^!/i,
        },
      },
      rollupOptions: {
        input: {
          app: 'src/AdminApp/main.tsx',
          orderPanel: 'src/OrderPanel/main.ts',
        },
      },
    },
  };
});
