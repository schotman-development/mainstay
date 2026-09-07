import type { Decorator, Meta, StoryObj } from '@storybook/react-vite'
import { CommandCenter } from './CommandCenter'
import { Dropdown, DropdownItem } from './Dropdown'
import { ExternalIcon } from './Icon'
import { Logo } from './Logo'
import { MenuItem } from './MenuItem'
import { UserMenu } from './UserMenu'
import type { Command } from './rankCommands'

const commands: Command[] = [
  { id: 'pages', label: 'Pages', group: 'Navigate', run: () => console.log('pages') },
  { id: 'collections', label: 'Collections', group: 'Navigate', run: () => console.log('collections') },
  { id: 'media', label: 'Media library', group: 'Navigate', keywords: 'files images uploads', run: () => console.log('media') },
  { id: 'settings', label: 'Settings', group: 'Navigate', run: () => console.log('settings') },
  { id: 'new-page', label: 'New page', group: 'Create', keywords: 'add', run: () => console.log('new page') },
  { id: 'publish', label: 'Publish changes', group: 'Actions', keywords: 'deploy live', run: () => console.log('publish') },
]

/*
 | There is no TopBar component: the bar is a row, and a wrapper whose whole body
 | is <header className="...">{children}</header> would be an abstraction with one
 | consumer. This is the reference assembly instead -- excludeStories keeps it out
 | of the sidebar so Shell.stories.tsx can render the same bar rather than a copy
 | of it that drifts.
 */
export function TopBar() {
  return (
    <header className="flex h-8.5 shrink-0 items-center gap-3 border-b border-border bg-surface px-3">
      {/* flex-1 is basis-0, so the two outer groups always resolve to the same
          width and the command center lands on the bar's true centre -- not
          wherever the brand and nav happen to end. */}
      <div className="flex flex-1 items-center gap-2">
        <Logo size={16} />

        {/* The items sit nearly flush: each already carries px-2.5, so their own
            padding is the separation and a gap on top of it reads as a hole.
            Wrapped rather than tightening the group's gap, so the mark keeps its
            distance from the first item. */}
        <div className="flex items-center gap-0.5">
          {/* Only the outbound link is navigation; a creation menu has no
              business inside the landmark. */}
          <nav aria-label="Main" className="flex items-center">
            <MenuItem
              compact
              href="https://example.test"
              target="_blank"
              rel="noreferrer"
              className="gap-1.5"
            >
              Visit website
              {/* The icon is decorative, so on its own it tells a screen reader
                  nothing about the new tab it is warning sighted users about. */}
              <span className="sr-only">(opens in a new tab)</span>
              <ExternalIcon />
            </MenuItem>
          </nav>

          <Dropdown
            label="New"
            triggerClassName="h-6 rounded-control px-2.5 text-xs text-muted hover:bg-canvas hover:text-ink"
          >
            <DropdownItem onClick={() => console.log('new page')}>Page</DropdownItem>
            <DropdownItem onClick={() => console.log('new collection')}>Collection</DropdownItem>
            <DropdownItem onClick={() => console.log('upload media')}>Media upload</DropdownItem>
          </Dropdown>
        </div>
      </div>

      <CommandCenter commands={commands} className="w-full max-w-sm" />

      <div className="flex flex-1 justify-end">
        <UserMenu
          name="Ada Lovelace"
          email="ada@mainstay.test"
          onAccountSettings={() => console.log('account settings')}
          onSignOut={() => console.log('sign out')}
        />
      </div>
    </header>
  )
}

const meta = {
  title: 'Top bar',
  component: TopBar,
  parameters: { layout: 'fullscreen', controls: { disable: true } },
  excludeStories: ['TopBar'],
  // The bar owns its own background; the preview's canvas padding fights it.
  decorators: [(Story) => <div className="-m-6 h-96 font-sans text-ink"><Story /></div>] as Decorator[],
} satisfies Meta<typeof TopBar>

export default meta

export const Assembled: StoryObj<typeof meta> = {}
