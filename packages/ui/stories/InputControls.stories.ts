import type { Decorator, Meta, StoryObj } from 'storybook-php'
import EveryControl from './blade/every-control.blade.php'

const meta: Meta<typeof EveryControl> = {
  component: EveryControl,
  id: 'input',
  title: 'Input/Every control',
  parameters: { layout: 'centered', controls: { disable: true } },
  decorators: [(story) => `<div class="w-96 font-sans text-ink">${story()}</div>`] as Decorator[],
}

export default meta

export const EveryControl_: StoryObj<typeof EveryControl> = { name: 'Every control' }
