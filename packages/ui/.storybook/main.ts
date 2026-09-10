import type { StorybookConfig } from 'storybook'
import tailwindcss from '@tailwindcss/vite'

const local = (file: string) => new URL(file, import.meta.url).pathname

export default {
  framework: {
    name: 'storybook-php',
    options: {
      bootstrap: local('./bootstrap.php'),

      // Every story imports a .blade.php, so the adapter is the whole render
      // path -- there is no PHP callable behind a template for the default
      // runner to fall through to.
      typeMap: {
        files: {
          '*.blade.php': { adapter: local('./blade-adapter.php') },
        },
      },
    },
  },

  stories: ['../stories/**/*.stories.ts'],

  // addon-a11y runs axe over the rendered story. It earns its keep here because
  // the components are Blade: the markup a browser gets is assembled by PHP, so
  // the accessible name of a chip's remove button or a combobox's
  // aria-activedescendant is only ever true of the real output.
  addons: ['@storybook/addon-docs', '@storybook/addon-a11y'],

  // Storybook phones home with anonymous usage data by default. Off, so a
  // clone of this repo does not start reporting from every dev machine and CI run.
  core: { disableTelemetry: true },

  // Tailwind belongs to the workshop, not to the package: the Blade components
  // ship as source and are compiled by whatever stylesheet imports theme.css.
  viteFinal: (config) => {
    config.plugins = [...(config.plugins ?? []), tailwindcss()]

    return config
  },
} satisfies StorybookConfig
