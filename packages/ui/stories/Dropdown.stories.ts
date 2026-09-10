import type { Decorator, Meta, StoryObj } from 'storybook-php'
import { expect, userEvent, within } from 'storybook/test'
import Dropdown from './blade/dropdown-menu.blade.php'

const meta: Meta<typeof Dropdown> = {
  component: Dropdown,
  title: 'Dropdown',
  argTypes: {
    align: { control: 'inline-radio', options: ['start', 'end'] },
  },
  decorators: [(story) => `<div class="h-72">${story()}</div>`] as Decorator[],
}

export default meta

type Story = StoryObj<typeof Dropdown>

/*
 | The three dismissals the bundle adds to <details> are only ever true of the
 | real thing: the unit tests prove the delegated listeners against a fixture,
 | and this proves the fixture was the markup Blade actually renders.
 */
export const Default: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    const details = canvasElement.querySelector('details')!
    const summary = canvasElement.querySelector('summary')!

    await userEvent.click(summary)
    await expect(details.open).toBe(true)
    await expect(canvas.getByText('Media upload')).toBeVisible()

    // Dismiss on activate: no consumer wraps its own handler to close the menu.
    await userEvent.click(canvas.getByText('Page'))
    await expect(details.open).toBe(false)

    /*
     | Escape with focus inside the panel. Closing puts the panel behind
     | display:none, so the assertion that matters is the second one -- without
     | the handover a keyboard user lands back on the document body.
     */
    await userEvent.click(summary)
    await userEvent.tab()
    await expect(document.activeElement).toBe(canvas.getByText('Page'))

    // Still open before the key, so Escape is what closes it below rather than
    // the focusout rule having got there first.
    await expect(details.open).toBe(true)

    await userEvent.keyboard('{Escape}')
    await expect(details.open).toBe(false)
    await expect(document.activeElement).toBe(summary)
  },
}

export const AlignedToEnd: Story = {
  args: { align: 'end' },
  decorators: [(story) => `<div class="flex h-72 justify-end">${story()}</div>`] as Decorator[],
}
