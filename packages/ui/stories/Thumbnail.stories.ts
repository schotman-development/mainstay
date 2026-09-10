import type { Meta, StoryObj } from 'storybook-php'
import Thumbnail from '../resources/views/components/thumbnail.blade.php'

const preview =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 28'%3E%3Crect width='40' height='28' fill='%23e7e5e0'/%3E%3Crect x='5' y='6' width='30' height='3' rx='1.5' fill='%238b8880'/%3E%3Crect x='5' y='13' width='21' height='2.5' rx='1.25' fill='%23bdbab3'/%3E%3Crect x='5' y='19' width='26' height='2.5' rx='1.25' fill='%23bdbab3'/%3E%3C/svg%3E"

const meta: Meta<typeof Thumbnail> = {
  component: Thumbnail,
  title: 'Thumbnail',
  args: { src: preview, fallback: 'None' },
  argTypes: {
    size: { control: 'inline-radio', options: ['sm', 'md', 'lg'] },
  },
}

export default meta

type Story = StoryObj<typeof Thumbnail>

export const Default: Story = {}

/* Dashed reads as a slot waiting to be filled; solid as a stand-in for the
   thing itself. */
export const Solid: Story = { args: { src: undefined, fallback: 'A', size: 'md' } }
