import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        // Pagination views are rendered by the app ({{ $x->links() }}), so their
        // classes must be generated.
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './resources/views/**/*.blade.php',
        // './storage/framework/views/*.php' is deliberately NOT scanned.
        //
        // It holds compiled Blade, which is derived from resources/views and so
        // adds nothing — except that `view:cache` also compiles Laravel's own
        // exception-renderer views into it. Scanning those emitted ~12.9 kB of
        // utilities for the debug error page (which ships its own CSS and is off
        // in production), and made the build non-deterministic: output differed
        // by 204 selectors and produced a different asset hash depending on
        // whether the view cache happened to be warm when `npm run build` ran.
        // Verified none of those 204 classes are used by any application view.
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
