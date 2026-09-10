import type { Decorator, Meta, StoryObj } from 'storybook-php'
import { expect, userEvent, within } from 'storybook/test'
import Composite from './blade/input-composite.blade.php'

const meta: Meta<typeof Composite> = {
  component: Composite,
  id: 'input',
  title: 'Input/Composite',
  parameters: { layout: 'centered', controls: { disable: true } },
  decorators: [(story) => `<div class="w-96 font-sans text-ink">${story()}</div>`] as Decorator[],
}

export default meta

export const Composite_: StoryObj<typeof Composite> = {
  name: 'Composite',

  /*
   | The tag input is the one component whose state is markup: a chip carries
   | the hidden input that posts it, and adding one clones the <template> Blade
   | rendered. Cloning the wrong thing is invisible on screen and breaks the
   | form, so the hidden values are what gets asserted, not the chips.
   */
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    const field = canvasElement.querySelector<HTMLInputElement>('[data-tag-field]')!
    const posted = () => {
      const hidden = canvasElement.querySelectorAll<HTMLInputElement>('[data-tag] input[name="tags[]"]')

      return [...hidden].map((input) => input.value)
    }

    await expect(posted()).toEqual(['editor', 'release'])

    await userEvent.type(field, 'docs{Enter}')
    await expect(posted()).toEqual(['editor', 'release', 'docs'])

    // Case-insensitively deduplicated, so a second "Editor" does not quietly
    // create a tag that filters to a different set of entries.
    await userEvent.type(field, 'Editor{Enter}')
    await expect(posted()).toEqual(['editor', 'release', 'docs'])
    await expect(field).toHaveValue('')

    // Comma commits too, because it is what people already type.
    await userEvent.type(field, 'draft,')
    await expect(posted()).toEqual(['editor', 'release', 'docs', 'draft'])

    // Backspace on an empty input takes the last one back.
    await userEvent.type(field, '{Backspace}')
    await expect(posted()).toEqual(['editor', 'release', 'docs'])

    /*
     | Every remove button is the same glyph, so the name it is found by is the
     | sr-only text the clone had to fill in -- a chip built in JavaScript
     | instead would leave a row of buttons called "x".
     */
    await userEvent.click(canvas.getByRole('button', { name: 'Remove release' }))
    await expect(posted()).toEqual(['editor', 'docs'])

    for (const tag of ['editor', 'docs']) {
      await userEvent.click(canvas.getByRole('button', { name: `Remove ${tag}` }))
    }

    // The placeholder is an instruction for an empty field, so it comes back
    // with the field -- from data-placeholder, since the attribute was cleared.
    await expect(posted()).toEqual([])
    await expect(field).toHaveAttribute('placeholder', 'Add a tag')
  },
}
