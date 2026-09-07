import type { Decorator, Meta, StoryObj } from '@storybook/react-vite'
import { Dropdown, DropdownItem } from './Dropdown'

const meta = {
  component: Dropdown,
  args: {
    label: 'New',
    children: (
      <>
        <DropdownItem onClick={() => console.log('page')}>Page</DropdownItem>
        <DropdownItem onClick={() => console.log('collection')}>Collection</DropdownItem>
        <DropdownItem onClick={() => console.log('media')}>Media upload</DropdownItem>
      </>
    ),
  },
  argTypes: {
    align: { control: 'inline-radio', options: ['start', 'end'] },
  },
  decorators: [(Story) => <div className="h-72"><Story /></div>] as Decorator[],
} satisfies Meta<typeof Dropdown>

export default meta

type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const AlignedToEnd: Story = {
  args: { align: 'end' },
  decorators: [(Story) => <div className="flex h-72 justify-end"><Story /></div>] as Decorator[],
}
