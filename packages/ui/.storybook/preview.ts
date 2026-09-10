import type { Decorator } from 'storybook-php'
import { mount } from '../src/js'
import './preview.css'

/*
 | storybook-php fetches the markup from PHP and writes it into the canvas after
 | the decorators have run, so there is no render hook to hang this off -- the
 | story is on screen before anything here could know. Watching the canvas is
 | what catches it, and mount() is safe to call again, so every replacement gets
 | wired and nothing gets wired twice.
 |
 | Without this the workshop shows components that cannot be opened, ticked or
 | typed into, which is the half of "visually and functionally identical" that a
 | screenshot will never tell you about.
 */
new MutationObserver(() => mount()).observe(document.body, { childList: true, subtree: true })

export default {
  parameters: {
    controls: { matchers: { color: /(background|color)$/i } },
  },

  // Components are designed against the theme's canvas, not the browser's white.
  decorators: [(story) => `<div class="bg-canvas p-6 font-sans text-ink">${story()}</div>`] as Decorator[],
}
