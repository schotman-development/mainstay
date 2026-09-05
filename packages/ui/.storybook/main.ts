import { defineMain } from '@storybook/react-vite/node'
import tailwindcss from '@tailwindcss/vite'

export default defineMain({
  framework: '@storybook/react-vite',
  stories: ['../src/**/*.stories.@(ts|tsx)'],
  addons: ['@storybook/addon-docs'],

  // Storybook phones home with anonymous usage data by default. Off, so a
  // clone of this repo does not start reporting from every dev machine and CI run.
  core: { disableTelemetry: true },

  // Tailwind belongs to the workshop, not to the library build. Adding it to
  // this package's vite.config.ts would attach a plugin the published bundle
  // has no CSS to process.
  viteFinal: (config) => {
    config.plugins = [...(config.plugins ?? []), tailwindcss()]

    return config
  },
})
