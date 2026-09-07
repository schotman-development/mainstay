import type { Decorator, Meta, StoryObj } from '@storybook/react-vite'
import { UserMenu } from './UserMenu'

const meta = {
  component: UserMenu,
  args: {
    name: 'Ada Lovelace',
    email: 'ada@mainstay.test',
    onAccountSettings: () => console.log('account settings'),
    onSignOut: () => console.log('sign out'),
  },
  decorators: [(Story) => <div className="flex h-72 justify-end"><Story /></div>] as Decorator[],
} satisfies Meta<typeof UserMenu>

export default meta

type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const NoEmail: Story = {
  args: { name: 'Grace', email: undefined },
}
