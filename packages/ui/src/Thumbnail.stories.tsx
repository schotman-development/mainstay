import type { Meta, StoryObj } from '@storybook/react-vite'
import { Thumbnail } from './Thumbnail'

const preview =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 28'%3E%3Crect width='40' height='28' fill='%23e7e5e0'/%3E%3Crect x='5' y='6' width='30' height='3' rx='1.5' fill='%238b8880'/%3E%3Crect x='5' y='13' width='21' height='2.5' rx='1.25' fill='%23bdbab3'/%3E%3Crect x='5' y='19' width='26' height='2.5' rx='1.25' fill='%23bdbab3'/%3E%3C/svg%3E"

const meta = {
  component: Thumbnail,
  args: { src: preview, fallback: 'None' },
} satisfies Meta<typeof Thumbnail>

export default meta

type Story = StoryObj<typeof meta>

/* The three sizes side by side, which is the only way to see that they are a
   set rather than three numbers that happen to be near each other. */
export const Sizes: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex items-end gap-4 font-sans">
      <Thumbnail src={preview} size="sm" fallback="A" />
      <Thumbnail src={preview} size="md" fallback="None" />
      <Thumbnail src={preview} size="lg" fallback="Empty" />
    </div>
  ),
}

/*
 | The empty states at the same sizes. The pairing is the point: each box is
 | exactly the size the image would have been, so choosing one shifts nothing
 | below it.
 */
export const Empty: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex items-end gap-4 font-sans">
      {/* Solid, as in a list beside real previews. */}
      <Thumbnail size="sm" fallback="A" />
      <Thumbnail size="md" dashed fallback="None" />
      <Thumbnail size="lg" dashed fallback="Empty" />
    </div>
  ),
}

/* Dashed reads as a slot waiting to be filled; solid as a stand-in for the
   thing itself. */
export const Solid: Story = {
  args: { src: undefined, fallback: 'A', size: 'md' },
}
