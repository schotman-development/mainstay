import type { Meta, StoryObj } from 'storybook-php'
import EntryDetails from './blade/entry-details.blade.php'

const meta: Meta<typeof EntryDetails> = {
  component: EntryDetails,
  title: 'Entry details',
  parameters: { layout: 'fullscreen', controls: { disable: true } },
}

export default meta

type Story = StoryObj<typeof EntryDetails>

/* Nobody has read it yet, so nothing on this screen is dangerous. */
export const DraftEntry: Story = {}

/*
 | The case the save state has to be loudest about: readers can already see
 | this, and the form is now holding something they cannot. Also the only story
 | with the search fields filled in, which is what having been through review
 | looks like.
 */
export const PublishedEntry: Story = { args: { entry: 'published' } }

/*
 | What "New post" opens on. Worth its own story because it is where the handoff
 | has to work hardest: there is no content, no preview to recognise the page by,
 | and the card has to read as an invitation rather than as a hole.
 */
export const NewEntry: Story = { args: { entry: 'blank' } }
