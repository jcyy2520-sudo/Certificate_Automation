import { defineConfig } from 'vitest/config';

// A standalone config (it does not load the Laravel/Vite build plugins) so the
// certificate editor's pure helpers can be unit tested in plain Node.
export default defineConfig({
    test: {
        environment: 'node',
        include: ['tests/js/**/*.test.js'],
    },
});
