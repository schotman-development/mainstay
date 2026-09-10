import type { Meta, StoryObj } from 'storybook-php'
import Logo from '../resources/views/components/logo.blade.php'

const meta: Meta<typeof Logo> = {
  component: Logo,
  title: 'Logo',
  args: { size: 24 },
  argTypes: { size: { control: { type: 'range', min: 12, max: 96, step: 2 } } },
}

export default meta

export const Lockup: StoryObj<typeof Logo> = {}
