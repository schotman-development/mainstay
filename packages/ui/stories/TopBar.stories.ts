import type { Meta, StoryObj } from 'storybook-php'
import TopBar from './blade/top-bar.blade.php'

const meta: Meta<typeof TopBar> = {
  component: TopBar,
  title: 'Top bar',
  parameters: { layout: 'fullscreen', controls: { disable: true } },
}

export default meta

export const Assembled: StoryObj<typeof TopBar> = {}
