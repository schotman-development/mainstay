import type { Decorator, Meta, StoryObj } from '@storybook/react-vite'
import { Logo, LogoMark } from './Logo'

const meta = {
  component: Logo,
  args: { size: 24 },
  argTypes: { size: { control: { type: 'range', min: 12, max: 96, step: 2 } } },
} satisfies Meta<typeof Logo>

export default meta

type Story = StoryObj<typeof meta>

export const Lockup: Story = {}

/*
 | The point of the size ladder: at 64 the mark is the full construction, at 32
 | the mast is shortened, at 24 the spreader goes, at 16 only the triangle is
 | left. Rendered at 4x underneath so the shed parts are actually visible.
 */
export const Sizes: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="flex items-end gap-8 text-ink">
      {[64, 32, 24, 16].map((size) => (
        <div key={size} className="flex flex-col items-center gap-4">
          <LogoMark size={size} label={`Mainstay, ${size} pixels`} />
          <span className="text-xs text-muted">{size}px</span>
          <div className="opacity-40">
            <LogoMark size={size * 4} />
          </div>
        </div>
      ))}
    </div>
  ),
}

export const Reverse: Story = {
  parameters: { controls: { disable: true } },
  // currentColor is the whole knockout: put the lockup on ink, colour it canvas.
  decorators: [
    (Story) => (
      <div className="-m-6 bg-ink p-10 text-canvas">
        <Story />
      </div>
    ),
  ] as Decorator[],
  render: () => <Logo size={38} className="text-canvas" />,
}
