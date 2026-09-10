import type { Decorator, Meta, StoryObj } from 'storybook-php'
import { expect, userEvent, within } from 'storybook/test'
import CommandCenter from '../resources/views/components/command-center.blade.php'

/* `run` was a callback in React. A server-rendered admin navigates, so a
   command is a label and somewhere to go. */
const commands = [
  { id: 'pages', label: 'Pages', group: 'Navigate', href: '/admin/pages' },
  { id: 'collections', label: 'Collections', group: 'Navigate', href: '/admin/collections' },
  { id: 'media', label: 'Media library', group: 'Navigate', keywords: 'files images uploads', href: '/admin/media' },
  { id: 'settings', label: 'Settings', group: 'Navigate', href: '/admin/settings' },
  { id: 'new-page', label: 'New page', group: 'Create', keywords: 'add', href: '/admin/pages/new' },
  { id: 'new-collection', label: 'New collection', group: 'Create', keywords: 'add', href: '/admin/collections/new' },
  { id: 'publish', label: 'Publish changes', group: 'Actions', keywords: 'deploy live', href: '/admin/publish' },
  { id: 'clear-cache', label: 'Clear cache', group: 'Actions', href: '/admin/cache/clear' },
]

const meta: Meta<typeof CommandCenter> = {
  component: CommandCenter,
  title: 'Command center',
  args: { commands },
  parameters: { layout: 'padded' },
  // The list is absolutely positioned; without headroom it opens off the frame.
  decorators: [(story) => `<div class="h-96 max-w-lg">${story()}</div>`] as Decorator[],
}

export default meta

type Story = StoryObj<typeof CommandCenter>

export const Default: Story = {
  /*
   | The whole component is one JSON payload and a keyboard. Blade writes the
   | commands into a <script type="application/json">, the bundle reads them
   | back, and nothing between the two is visible in a screenshot -- so the
   | query below is really an assertion about @json having survived the trip.
   |
   | Enter is deliberately not pressed: running a command is location.assign(),
   | which would navigate the canvas out from under the test.
   */
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    const field = canvas.getByRole('combobox', { name: 'Search or jump to' })

    // Opens on focus with everything showing, so it reads as a menu before it
    // is used as a search.
    await userEvent.click(field)
    await expect(field).toHaveAttribute('aria-expanded', 'true')
    await expect(canvas.getAllByRole('option')).toHaveLength(8)

    /*
     | "add" is in neither label -- it is a keyword on the two Create commands.
     | Matching it proves keywords made it through the payload, and the order
     | proves rankCommands' stable sort is holding author order on the tie.
     */
    await userEvent.type(field, 'add')

    const matches = canvas.getAllByRole('option')

    await expect(matches).toHaveLength(2)
    await expect(matches[0]!).toHaveTextContent('New page')
    await expect(matches[1]!).toHaveTextContent('New collection')

    // The highlight has to survive the keyboard, and it is published to a
    // screen reader by id rather than by anything the eye can see.
    await expect(matches[0]!).toHaveAttribute('aria-selected', 'true')

    // The rows are replaced on every render, so the moved highlight has to be
    // asked of the new list rather than of the nodes captured above.
    await userEvent.keyboard('{ArrowDown}')
    await expect(canvas.getAllByRole('option')[1]!).toHaveAttribute('aria-selected', 'true')
    await expect(field).toHaveAttribute('aria-activedescendant', 'command-center-list-new-collection')

    // First Escape closes without moving focus, so the key never jumps straight
    // from browsing to having lost what was typed.
    await userEvent.keyboard('{Escape}')
    await expect(field).toHaveAttribute('aria-expanded', 'false')
    await expect(field).toHaveValue('add')

    await userEvent.keyboard('{Escape}')
    await expect(field).toHaveValue('')
  },
}

export const Empty: Story = {
  args: { commands: [], placeholder: 'Nothing registered yet' },

  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    const field = canvas.getByRole('combobox', { name: 'Search or jump to' })

    await userEvent.click(field)
    await expect(canvas.getByText('No matches')).toBeVisible()

    // Nothing to highlight, so nothing is published as highlighted -- the clamp
    // in draw() reads back -1 here, and the attribute has to come off rather
    // than point at a row that does not exist.
    await expect(field).not.toHaveAttribute('aria-activedescendant')
  },
}
