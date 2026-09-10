import type { Decorator, Meta, StoryObj } from 'storybook-php'
import { expect, userEvent, within } from 'storybook/test'
import UserMenu from '../resources/views/components/user-menu.blade.php'

const meta: Meta<typeof UserMenu> = {
  component: UserMenu,
  title: 'User menu',
  args: { name: 'Ada Lovelace', email: 'ada@mainstay.test' },
  decorators: [(story) => `<div class="flex h-72 justify-end">${story()}</div>`] as Decorator[],
}

export default meta

type Story = StoryObj<typeof UserMenu>

export const Default: Story = {
  /*
   | Two things at once: the initials, which are a PHP expression no JavaScript
   | test can reach, and dismiss-on-outside-click against the header's real
   | trigger rather than the fixture's.
   */
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    const details = canvasElement.querySelector('details')!

    await expect(canvas.getByText('AL')).toBeInTheDocument()

    await userEvent.click(canvasElement.querySelector('summary')!)
    await expect(details.open).toBe(true)
    await expect(canvas.getByText('ada@mainstay.test')).toBeVisible()
    await expect(canvas.getByRole('button', { name: 'Sign out' })).toBeVisible()

    // The wrapper the decorator put around the story: inside the canvas, outside
    // the menu, which is exactly what the pointerdown rule is looking for.
    await userEvent.click(canvasElement)
    await expect(details.open).toBe(false)
  },
}

export const NoEmail: Story = {
  args: { name: 'Grace', email: null },

  /* One word in, one letter out -- and the paragraph the email would have sat
     in is gone rather than empty. */
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)

    await expect(canvas.getByText('G')).toBeInTheDocument()

    await userEvent.click(canvasElement.querySelector('summary')!)
    await expect(canvas.queryAllByText(/@/)).toHaveLength(0)
  },
}
