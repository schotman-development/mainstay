import type { Meta, StoryObj } from 'storybook-php'
import EveryButton from './blade/every-button.blade.php'

const meta: Meta<typeof EveryButton> = {
  component: EveryButton,
  id: 'button',
  title: 'Button/Every variant',
  parameters: { controls: { disable: true } },
}

export default meta

export const EveryVariant: StoryObj<typeof EveryButton> = {}
