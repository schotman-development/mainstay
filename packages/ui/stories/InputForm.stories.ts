import type { Decorator, Meta, StoryObj } from 'storybook-php'
import InAForm from './blade/input-in-a-form.blade.php'

const meta: Meta<typeof InAForm> = {
  component: InAForm,
  id: 'input',
  title: 'Input/In a form',
  parameters: { layout: 'centered', controls: { disable: true } },
  decorators: [(story) => `<div class="w-96 font-sans text-ink">${story()}</div>`] as Decorator[],
}

export default meta

export const InAForm_: StoryObj<typeof InAForm> = { name: 'In a form' }
