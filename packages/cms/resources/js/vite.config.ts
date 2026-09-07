import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

/*
 | Output filenames are fixed rather than hashed. The Blade shell cache-busts
 | with ?v={Mainstay::VERSION} instead, which saves shipping a Vite manifest
 | and parsing it in PHP on every admin request.
 */
/*
 | Assets resolve relative to the stylesheet, not the domain root. The build is
 | published into public/vendor/mainstay, so a root-absolute url(/mainstay.woff2)
 | would 404 -- and would break again for any host whose Laravel app is not
 | served from the domain root.
 */
export default defineConfig({
  base: './',
  plugins: [react(), tailwindcss()],
  build: {
    outDir: '../../dist',
    emptyOutDir: true,
    rollupOptions: {
      input: 'src/main.tsx',
      output: {
        entryFileNames: 'mainstay.js',
        chunkFileNames: 'mainstay-[name].js',
        /*
         | The stylesheet keeps its fixed name because the Blade shell hardcodes
         | it. Everything else keeps its source name: a shared 'mainstay.[ext]'
         | pattern makes Vite disambiguate the six font files as mainstay2,
         | mainstay3 ... and it renumbers them on each build, so a committed
         | dist would churn all six binaries every time.
         */
        assetFileNames: (asset) => {
          const name = asset.names?.[0] ?? asset.name ?? ''

          return name.endsWith('.css') ? 'mainstay.[ext]' : '[name].[ext]'
        },
      },
    },
  },
})
