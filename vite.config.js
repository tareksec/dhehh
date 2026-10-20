import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament/admin/theme.css',
                'resources/css/filament/reseller/theme.css',
                'resources/css/filament/investor/theme.css',
                'resources/css/filament/ims/theme.css',
                'resources/js/app.js',
                'resources/js/app-updater.js',
                'resources/js/push-notifications.js',
                'resources/js/web-push.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
