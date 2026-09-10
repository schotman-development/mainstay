import type { Meta, StoryObj } from 'storybook-php'
import { expect, within } from 'storybook/test'
import Checkbox from '../resources/views/components/checkbox.blade.php'

const meta: Meta<typeof Checkbox> = {
  component: Checkbox,
  title: 'Checkbox',
  args: { label: 'Select Home' },
  parameters: { layout: 'centered' },
}

export default meta

type Story = StoryObj<typeof Checkbox>

export const Unchecked: Story = {}

export const Checked: Story = { args: { checked: true } }

/* The state markup cannot express. Some of what this box covers is selected,
   and an empty box here would say the opposite of the truth -- the attribute is
   a marker, and the admin's bundle turns it into the DOM property. */
export const Indeterminate: Story = {
  args: { indeterminate: true },

  /*
   | The one assertion a screenshot cannot make. Part-checked is a property with
   | no attribute behind it, so a passing render and a broken mount() look
   | identical on screen -- and the broken one reads as "nothing is selected"
   | when the truth is the opposite.
   */
  play: async ({ canvasElement }) => {
    const box = within(canvasElement).getByRole('checkbox', { name: 'Select Home' })

    await expect(box).toHaveProperty('indeterminate', true)
  },
}

export const Disabled: Story = { args: { disabled: true } }
