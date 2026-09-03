import { defineConfig } from 'vite';
import symfony from '@symfony/reprise/vite';

export default defineConfig(({ mode }) => ({
    build: {
        emptyOutDir: true,
        sourcemap: mode !== 'production',
        rollupOptions: {
            input: {
                app: './app.js',
                dark: './styles/themes/dark.css',
            },
            output: mode === 'production' ? {} : {
                entryFileNames: '[name].js',
                chunkFileNames: '[name].js',
                assetFileNames: '[name].[ext]',
            },
        },
    },
    plugins: [
        symfony({
            outputPath: '../public/build',
            publicPath: '/build/',
            stimulus: 'controllers.json',
            copy: [
                { from: './images', to: 'images' },
            ],
        }),
    ],
}));
