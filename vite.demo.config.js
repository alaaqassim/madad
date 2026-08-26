import { defineConfig } from 'vite';
import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

/*
| The static demo build — GitHub Pages, not production.
|
| Produces a self-contained site from demo/index.html so the contestant journey
| can be reviewed from a link, with no PHP, no database and no backend. The
| mock is switched on by VITE_MADAD_DEMO, which ONLY this config sets; the
| Laravel bundle (`npm run build`, vite.config.js) never does, and still ships
| without a byte of it.
|
| `base` is the repository name because Pages serves a project site from
| /<repo>/ rather than the domain root.
*/
export default defineConfig({
    root: fileURLToPath(new URL('./demo', import.meta.url)),
    base: '/madad/',
    plugins: [tailwindcss(), vue()],
    define: {
        'import.meta.env.VITE_MADAD_DEMO': JSON.stringify('true'),
    },
    build: {
        outDir: fileURLToPath(new URL('./dist-demo', import.meta.url)),
        emptyOutDir: true,
    },
});
