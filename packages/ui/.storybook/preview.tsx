import { definePreview } from '@storybook/react-vite'
import './preview.css'

export default definePreview({
  parameters: {
    controls: { matchers: { color: /(background|color)$/i } },
  },

  // Components are designed against the theme's canvas, not the browser's white.
  decorators: [
    (Story) => (
      <div className="bg-canvas p-6 font-sans text-ink">
        <Story />
      </div>
    ),
  ],
})
