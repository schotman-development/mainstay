import type { Decorator, Meta, StoryObj } from '@storybook/react-vite'
import { StatusChip } from './StatusChip'

const meta = {
  component: StatusChip,
  args: { status: 'Draft' },
  parameters: { layout: 'centered' },
} satisfies Meta<typeof StatusChip>

export default meta

type Story = StoryObj<typeof meta>

/* Draft is the state worth noticing, so it is the one that reads as a label. */
export const Draft: Story = {}

export const Published: Story = { args: { status: 'Published' } }

/* On the page rather than on a panel, where the chip takes the panel colour so
   its border still reads. */
export const OnCanvas: Story = {
  args: { ground: 'surface' },
  decorators: [(Story) => <div className="bg-canvas p-4"><Story /></div>] as Decorator[],
}
