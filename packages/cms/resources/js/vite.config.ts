import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

/*
 | Output filenames are fixed rather than hashed. The Blade shell cache-busts
 | with ?v={Mainstay::VERSION} instead, which saves shipping a Vite manifest
 | and parsing it in PHP on every admin request.
 */
export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: {
    outDir: '../../dist',
    emptyOutDir: true,
    rollupOptions: {
      input: 'src/main.tsx',
      output: {
        entryFileNames: 'mainstay.js',
        chunkFileNames: 'mainstay-[name].js',
        assetFileNames: 'mainstay.[ext]',
      },
    },
  },
})
