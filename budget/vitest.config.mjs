import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        // The code under test is browser code: it reads and writes the DOM
        // directly rather than going through a framework.
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        // Stylesheet imports (flatpickr's, via src/utils/datepicker.js) carry
        // nothing this suite asserts on, and processing them only slows the
        // run down.
        css: false,
    },
});
