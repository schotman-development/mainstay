import { Fragment } from 'react'
import type { ReactNode } from 'react'
import type { Decorator, Meta, StoryObj } from '@storybook/react-vite'
import { Sidebar } from './Sidebar'
import { sections } from './Sidebar.stories'
import { TopBar } from './TopBar.stories'

/*
 | One step of the breadcrumb. No href is what marks the page you are already
 | on, so "is this a link" and "is this the current page" cannot be set to
 | disagree with each other.
 */
export type Crumb = { label: string; href?: string }

/*
 | The admin, minus whatever screen you happen to be on. The bar and the
 | navigation are fixed: every route in the panel renders inside this, and no
 | screen brings chrome of its own.
 |
 | Exported for the same reason TopBar is -- excludeStories keeps it out of the
 | sidebar, and a screen's stories render the real shell rather than a copy of
 | it that drifts. A screen that assembles its own bar and navigation is a
 | second layout to keep in step, and it will not be kept in step.
 |
 | What it hands a screen is a box with a definite height and nothing else: no
 | width cap, no padding, no card. Filling that box rather than growing to fit
 | is the screen's half of the bargain -- PageList's `fill` prop is exactly this
 | contract, and it is what keeps the bar and the navigation still while the
 | content scrolls under them.
 */
export function Shell({
  current,
  breadcrumb,
  actions,
  children,
}: {
  current: string
  /* Where you are, deepest last. Part of the shell rather than of the screen:
     a trail that only some routes bother to draw is a trail you cannot learn
     to rely on. */
  breadcrumb: Crumb[]
  /* What this screen lets you do with what you are looking at -- a Save button,
     a status, whatever the route needs. The bar is the shell's; the contents of
     its right-hand side are the screen's. */
  actions?: ReactNode
  children: ReactNode
}) {
  return (
    <div className="flex h-full flex-col bg-canvas font-sans text-ink">
      <TopBar />

      {/* min-h-0 is what lets the main column scroll: without it a flex child
          takes its content height as a floor and the whole page scrolls
          instead, carrying the bar off the top of the screen. */}
      <div className="flex min-h-0 flex-1">
        <Sidebar sections={sections} current={current} />

        <main className="flex min-w-0 flex-1 flex-col overflow-hidden bg-canvas">
          {/* Above the content and inside the main column, so it lines up with
              the screen rather than with the navigation, and so it stays put
              while the content scrolls under it. */}
          <div className="flex shrink-0 flex-wrap items-center gap-3 border-b border-border px-5 py-3">
            <nav aria-label="Breadcrumb" className="flex min-w-0 items-center gap-1.5 text-sm">
              {breadcrumb.map((crumb, index) => (
                <Fragment key={crumb.href ?? crumb.label}>
                  {index > 0 && (
                    <span aria-hidden="true" className="text-muted/60">
                      /
                    </span>
                  )}

                  {crumb.href ? (
                    <a
                      href={crumb.href}
                      className="truncate rounded-control text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                    >
                      {crumb.label}
                    </a>
                  ) : (
                    <span aria-current="page" className="truncate font-medium">
                      {crumb.label}
                    </span>
                  )}
                </Fragment>
              ))}
            </nav>

            {actions && <div className="ml-auto flex items-center gap-2">{actions}</div>}
          </div>

          <div className="min-h-0 flex-1">{children}</div>
        </main>
      </div>
    </div>
  )
}

const meta = {
  title: 'Shell',
  component: Shell,
  args: {
    current: '/admin/collections/pages',
    breadcrumb: [{ label: 'Collections', href: '/admin/collections' }, { label: 'Pages' }],
    /*
     | A stand-in rather than a real screen. Every screen brings this shell with
     | it -- it has to, because the bar's actions come from state only the
     | screen holds -- so importing one here would point the dependency back the
     | way it came. What is left is a story of the contract itself: the box a
     | screen is handed, and how much of the window is actually left for it.
     */
    children: (
      <div className="grid h-full place-content-center bg-canvas px-4 text-center text-muted">
        <p className="text-sm">The screen renders here.</p>
        <p className="pt-1 text-xs">Full height, no width cap, no padding, no card.</p>
      </div>
    ),
  },
  parameters: { layout: 'fullscreen', controls: { disable: true } },
  excludeStories: ['Shell'],
  decorators: [(Story) => <div className="-m-6 h-dvh"><Story /></div>] as Decorator[],
} satisfies Meta<typeof Shell>

export default meta

/*
 | The fixed chrome on its own: the bar, the navigation, the breadcrumb, and the
 | hole every route drops into. Worth a story of its own because these are the
 | proportions every screen is designed against -- and because it is where the
 | divider rules have to agree, which only shows up in assembly.
 */
export const Chrome: StoryObj<typeof meta> = {}

/* A route two levels down. The trail lengthens, the navigation opens itself to
   match, and nothing else about the chrome moves. */
export const NestedRoute: StoryObj<typeof meta> = {
  args: {
    current: '/admin/collections/posts/42/edit',
    breadcrumb: [
      { label: 'Collections', href: '/admin/collections' },
      { label: 'Posts', href: '/admin/collections/posts' },
      { label: 'Shipping the new editor' },
    ],
  },
}
