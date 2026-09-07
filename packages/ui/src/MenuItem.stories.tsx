import type { Meta, StoryObj } from '@storybook/react-vite'
import { MenuItem } from './MenuItem'

const meta = {
  component: MenuItem,
  args: { children: 'Pages', href: '#' },
} satisfies Meta<typeof MenuItem>

export default meta

type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const Current: Story = {
  args: { current: true },
}

export const AsNav: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <nav aria-label="Sections" className="flex items-center gap-1">
      <MenuItem href="#" current>
        Pages
      </MenuItem>
      <MenuItem href="#">Collections</MenuItem>
      <MenuItem href="#">Media</MenuItem>
      <MenuItem href="#">Settings</MenuItem>
    </nav>
  ),
}
