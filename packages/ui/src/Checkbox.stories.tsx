import type { Meta, StoryObj } from '@storybook/react-vite'
import { Checkbox } from './Checkbox'

const meta = {
  component: Checkbox,
  args: { label: 'Select Home', checked: false, onChange: () => {} },
  parameters: { layout: 'centered' },
} satisfies Meta<typeof Checkbox>

export default meta

type Story = StoryObj<typeof meta>

export const Unchecked: Story = {}

export const Checked: Story = { args: { checked: true } }

/* The state JSX cannot express. Some of what this box covers is selected, and
   an empty box here would say the opposite of the truth. */
export const Indeterminate: Story = { args: { indeterminate: true } }

export const Disabled: Story = { args: { disabled: true } }
