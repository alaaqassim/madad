import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

/*
| Front-end test config.
|
| Deliberately separate from vite.config.js: the Laravel plugin wants a running
| dev server and a manifest, neither of which a component test has or needs.
*/
export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.js'],
        globals: true,
        restoreMocks: true,
    },
});
