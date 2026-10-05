import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
  plugins: [react()],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  server: {
    // Décommentez pour viser votre vrai backend en dev (et mettez VITE_USE_MOCK=false dans .env.local)
    // proxy: { '/api': 'http://localhost:8000' },
  },
});
