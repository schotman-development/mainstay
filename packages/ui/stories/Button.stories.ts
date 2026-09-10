import type { Meta, StoryObj } from 'storybook-php'
import Button from '../resources/views/components/button.blade.php'

const meta: Meta<typeof Button> = {
  component: Button,
  title: 'Button',
  args: { slot: 'Save changes' },
  argTypes: {
    variant: {
      control: 'inline-radio',
      options: ['primary', 'secondary', 'danger'],
    },
  },
}

export default meta

type Story = StoryObj<typeof Button>

export const Primary: Story = {}

export const Secondary: Story = { args: { variant: 'secondary' } }

export const Danger: Story = { args: { variant: 'danger', slot: 'Delete collection' } }

export const Disabled: Story = { args: { disabled: true } }
