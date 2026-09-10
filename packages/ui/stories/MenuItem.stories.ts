import type { Meta, StoryObj } from 'storybook-php'
import MenuItem from '../resources/views/components/menu-item.blade.php'

const meta: Meta<typeof MenuItem> = {
  component: MenuItem,
  title: 'Menu item',
  args: { slot: 'Pages', href: '#' },
}

export default meta

type Story = StoryObj<typeof MenuItem>

export const Default: Story = {}

export const Current: Story = { args: { current: true } }
