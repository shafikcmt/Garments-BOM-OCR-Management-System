import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    // Bootstrap owns the base layer on every screen. Tailwind's preflight
    // would reset it and break the whole UI, so it stays off — this mirrors
    // the `corePlugins: { preflight: false }` the CDN build used before the
    // stylesheet moved into Vite.
    corePlugins: {
        preflight: false,
    },

    theme: {
        extend: {
            fontFamily: {
                // Inter is what the layouts actually load (fonts.bunny.net)
                // and what body falls back to in app.css.
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
