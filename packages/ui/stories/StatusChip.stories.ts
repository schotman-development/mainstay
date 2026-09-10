import type { Decorator, Meta, StoryObj } from 'storybook-php'
import StatusChip from '../resources/views/components/status-chip.blade.php'

const meta: Meta<typeof StatusChip> = {
  component: StatusChip,
  title: 'Status chip',
  args: { status: 'Draft' },
  parameters: { layout: 'centered' },
}

export default meta

type Story = StoryObj<typeof StatusChip>

/* Draft is the state worth noticing, so it is the one that reads as a label. */
export const Draft: Story = {}

export const Published: Story = { args: { status: 'Published' } }

/* On the page rather than on a panel, where the chip takes the panel colour so
   its border still reads. */
export const OnCanvas: Story = {
  args: { ground: 'surface' },
  decorators: [(story) => `<div class="bg-canvas p-4">${story()}</div>`] as Decorator[],
}
