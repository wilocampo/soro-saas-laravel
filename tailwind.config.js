import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import primeui from 'tailwindcss-primeui';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    // Sakai toggles dark mode by adding .app-dark to <html> — dark: variants
    // must key off that class, not the OS preference.
    darkMode: ['selector', '.app-dark'],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    // primeui provides the theme-token utilities the whole UI is written in:
    // text-surface-*, bg-surface-*, text-muted-color, text-primary, …
    plugins: [forms, primeui],
};
