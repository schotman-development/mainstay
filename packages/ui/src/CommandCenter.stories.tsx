import type { Decorator, Meta, StoryObj } from '@storybook/react-vite'
import { CommandCenter } from './CommandCenter'
import type { Command } from './rankCommands'

const commands: Command[] = [
  { id: 'pages', label: 'Pages', group: 'Navigate', run: () => console.log('pages') },
  { id: 'collections', label: 'Collections', group: 'Navigate', run: () => console.log('collections') },
  { id: 'media', label: 'Media library', group: 'Navigate', keywords: 'files images uploads', run: () => console.log('media') },
  { id: 'settings', label: 'Settings', group: 'Navigate', run: () => console.log('settings') },
  { id: 'new-page', label: 'New page', group: 'Create', keywords: 'add', run: () => console.log('new page') },
  { id: 'new-collection', label: 'New collection', group: 'Create', keywords: 'add', run: () => console.log('new collection') },
  { id: 'publish', label: 'Publish changes', group: 'Actions', keywords: 'deploy live', run: () => console.log('publish') },
  { id: 'clear-cache', label: 'Clear cache', group: 'Actions', run: () => console.log('clear cache') },
]

const meta = {
  component: CommandCenter,
  args: { commands },
  parameters: { layout: 'padded' },
  // The list is absolutely positioned; without headroom it opens off the frame.
  decorators: [(Story) => <div className="h-96 max-w-lg"><Story /></div>] as Decorator[],
} satisfies Meta<typeof CommandCenter>

export default meta

type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const Empty: Story = {
  args: { commands: [], placeholder: 'Nothing registered yet' },
}
