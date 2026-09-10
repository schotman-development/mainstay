import type { Decorator, Meta, StoryObj } from 'storybook-php'
import Input from '../resources/views/components/input.blade.php'

const meta: Meta<typeof Input> = {
  component: Input,
  title: 'Input',
  args: { placeholder: 'Untitled' },
  parameters: { layout: 'centered' },
  argTypes: {
    size: { control: 'inline-radio', options: ['none', 'sm', 'md'] },
    ground: { control: 'inline-radio', options: ['canvas', 'surface'] },
  },
  decorators: [(story) => `<div class="w-96 font-sans text-ink">${story()}</div>`] as Decorator[],
}

export default meta

type Story = StoryObj<typeof Input>

export const Default: Story = {}

/* The bar density, against the panel ground the filter bar sits on. */
export const Compact: Story = {
  args: { size: 'sm', ground: 'surface', type: 'search', placeholder: 'Search' },
}
