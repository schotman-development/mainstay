import { useState } from 'react'
import type { ReactNode } from 'react'
import type { Decorator, Meta, StoryObj } from '@storybook/react-vite'
import { Button } from './Button'
import { Checkbox } from './Checkbox'
import { DateText } from './DateText'
import { Dropdown, DropdownItem } from './Dropdown'
import { Icon, MoreIcon } from './Icon'
import { Input } from './Input'
import { StatusChip } from './StatusChip'
import { Shell } from './Shell.stories'
import { Thumbnail } from './Thumbnail'

export type Entry = {
  title: string
  /* Not shown any more, but still the route the actions act on. */
  path: string
  status: 'Published' | 'Draft'
  category: string
  author: string
  /* ISO dates. Nothing has a release date until it has been released. */
  released: string | null
  modified: string
  /* A rendered preview of the page. Falls back to a tile when there is none. */
  thumbnail?: string
}

export type SortField = 'title' | 'status' | 'category' | 'author' | 'released' | 'modified'

export type View = {
  query: string
  status: 'All' | 'Published' | 'Draft'
  field: SortField
  direction: 'asc' | 'desc'
  page: number
  perPage: number
}

/* Stands in for a real rendered page preview. */
const preview =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 28'%3E%3Crect width='40' height='28' fill='%23e7e5e0'/%3E%3Crect x='5' y='6' width='30' height='3' rx='1.5' fill='%238b8880'/%3E%3Crect x='5' y='13' width='21' height='2.5' rx='1.25' fill='%23bdbab3'/%3E%3Crect x='5' y='19' width='26' height='2.5' rx='1.25' fill='%23bdbab3'/%3E%3C/svg%3E"

/* Shared with Shell.stories.tsx; excludeStories keeps these out of the index. */
export const pages: Entry[] = [
  { title: 'Careers', path: '/careers', status: 'Published', category: 'Company', author: 'Katherine Johnson', released: '2026-02-18', modified: '2026-08-29', thumbnail: preview },
  { title: 'Home', path: '/', status: 'Published', category: 'Marketing', author: 'Ada Lovelace', released: '2025-11-04', modified: '2026-09-06', thumbnail: preview },
  { title: 'Terms of service', path: '/terms', status: 'Draft', category: 'Legal', author: 'Grace Hopper', released: null, modified: '2026-08-29' },
  { title: 'About', path: '/about', status: 'Published', category: 'Marketing', author: 'Grace Hopper', released: '2025-11-04', modified: '2026-09-05' },
  { title: 'Pricing', path: '/pricing', status: 'Draft', category: 'Marketing', author: 'Ada Lovelace', released: null, modified: '2026-09-03' },
]

export const posts: Entry[] = [
  { title: 'Shipping the new editor', path: '/blog/shipping-the-new-editor', status: 'Published', category: 'Product', author: 'Ada Lovelace', released: '2026-09-06', modified: '2026-09-06', thumbnail: preview },
  { title: 'What headless actually buys you', path: '/blog/what-headless-actually-buys-you', status: 'Published', category: 'Engineering', author: 'Katherine Johnson', released: '2026-09-01', modified: '2026-09-02' },
  { title: 'A field guide to content modelling for teams who have outgrown their spreadsheet', path: '/blog/content-modelling-field-guide', status: 'Draft', category: 'Engineering', author: 'Grace Hopper', released: null, modified: '2026-09-01' },
  { title: 'Release notes: 0.4', path: '/blog/release-notes-0-4', status: 'Draft', category: 'Changelog', author: 'Ada Lovelace', released: null, modified: '2026-08-11' },
]

/* Enough rows to page. */
export const many: Entry[] = [
  ...pages,
  ...posts,
  ...pages.map((page) => ({ ...page, title: `${page.title} (copy)`, path: `${page.path}-copy`, status: 'Draft' as const })),
]

/*
 | Search, filter, sort and page, in one pass and with no state of its own, so
 | the interesting part of the list is testable without rendering it.
 |
 | The page is clamped rather than trusted: narrowing the query shortens the
 | result, and the page the caller is holding can easily be past the end of what
 | is left. Returning the clamped value is what lets the footer stay honest.
 */
export function shapeEntries(entries: Entry[], view: View) {
  /* NFC on both sides: an accented title stored decomposed does not match the
     same word typed precomposed otherwise. */
  const fold = (value: string) => value.normalize('NFC').toLowerCase()

  const needle = fold(view.query.trim())

  const matched = entries.filter(
    (entry) =>
      (view.status === 'All' || entry.status === view.status) &&
      (needle === '' ||
        fold(entry.title).includes(needle) ||
        fold(entry.author).includes(needle) ||
        fold(entry.category).includes(needle)),
  )

  const sorted = [...matched].sort((a, b) => compare(a, b, view.field, view.direction))
  const total = sorted.length

  /* || 1 catches NaN as well as zero, and the floor keeps a fractional page
     from stranding rows between two pages that both claim to be whole. */
  const perPage = Math.max(1, Math.floor(view.perPage) || 1)
  const pageCount = Math.max(1, Math.ceil(total / perPage))
  const page = Math.min(Math.max(Math.floor(view.page) || 1, 1), pageCount)
  const start = (page - 1) * perPage

  return { rows: sorted.slice(start, start + perPage), matched: sorted, total, page, pageCount, start }
}

/*
 | What the header checkbox should show for the rows currently on screen.
 |
 | Selection outlives paging, so "all" means every row you can see, not every
 | row that exists -- otherwise the box never fills in on a paged list and there
 | is no way to tell whether ticking it will add or remove.
 */
export function selectionState(rows: Entry[], selected: ReadonlySet<string>) {
  const count = rows.filter((row) => selected.has(row.path)).length

  return { count, all: rows.length > 0 && count === rows.length, some: count > 0 && count < rows.length }
}

function compare(a: Entry, b: Entry, field: SortField, direction: 'asc' | 'desc'): number {
  const left = a[field]
  const right = b[field]

  /* A draft has no release date. It sorts last whichever way the column points,
     rather than leading the list every time you sort by it. */
  if (left === null || right === null) {
    if (left === right) return 0
    return left === null ? 1 : -1
  }

  const order = left.localeCompare(right, 'en')
  return direction === 'asc' ? order : -order
}

/* "/" is a path; "/" + "/edit" is a protocol-relative URL pointing at a host
   called "edit". Trim before joining. */
const under = (path: string, segment: string) => `${path.replace(/\/$/, '')}/${segment}`

const cell = 'px-5 py-3.5 align-middle'

/*
 | Pages and posts are the same list -- a title, whether readers can see it yet,
 | where it is filed, who owns it, and the two dates that matter.
 |
 | A screen rather than a component that gets placed on one: it brings the fixed
 | shell with it and puts its own controls in the shell's bar. It has to, for the
 | same reason the entry screen does -- the search box and the status filter read
 | and write view state that lives in here, and nothing outside can reach it to
 | render them.
 |
 | So there is no toolbar of its own any more. The breadcrumb says which list you
 | are looking at, the bar's right-hand side is what you can do to it, and the
 | column headers are the sort control. Everything below the bar is data.
 */
export function PageList({
  entries,
  label,
  action,
  href,
}: {
  entries: Entry[]
  /* The last crumb, and the table's accessible name. Both come from this one
     string so they cannot say different things. */
  label: string
  /* The primary action for this list, e.g. a New page button. */
  action?: ReactNode
  /* The collection's own route, for the navigation. Derived from the label when
     the two agree, which they do for every collection in the sidebar. */
  href?: string
}) {
  const [view, setView] = useState<View>({
    query: '',
    status: 'All',
    field: 'modified',
    direction: 'desc',
    page: 1,
    perPage: 8,
  })

  /* Anything that changes what is in the list sends you back to its first page;
     only an explicit page in the patch survives. */
  const update = (patch: Partial<View>) => setView((current) => ({ ...current, page: 1, ...patch }))

  const [selected, setSelected] = useState<ReadonlySet<string>>(new Set())

  /*
   | The screen *is* the content region: no card, no rule around it, the page's
   | own ground showing through. Which forces the tints to swap -- a chip or a
   | selected row that stood out against a panel is invisible once the panel is
   | gone, so on the page they take the panel colour instead.
   |
   | Spelled out as whole literals rather than composed, because the scanner
   | reads source text: a class assembled at runtime is a class Tailwind never
   | sees and never compiles.
   */
  const tint = 'bg-surface'
  const headCell = '[&>th]:bg-canvas'

  const { rows, matched, total, page, pageCount, start } = shapeEntries(entries, view)
  const onPage = selectionState(rows, selected)

  /*
   | Scoped to what the search and the filter have left, not to every entry.
   | Counted from the entries themselves rather than from the set, so a
   | selection left behind by a row that has since gone cannot inflate it -- and
   | scoped this way, "Move to trash" can never reach a row you cannot see.
   */
  const chosen = matched.filter((entry) => selected.has(entry.path))

  const toggle = (path: string) =>
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(path)) next.delete(path)
      else next.add(path)
      return next
    })

  const togglePage = () =>
    setSelected((current) => {
      const next = new Set(current)
      for (const row of rows) {
        if (onPage.all) next.delete(row.path)
        else next.add(row.path)
      }
      return next
    })

  const sort = (field: SortField) =>
    update({ field, direction: view.field === field && view.direction === 'asc' ? 'desc' : 'asc' })

  const listing = href ?? `/admin/collections/${label.toLowerCase()}`

  return (
    <Shell
      current={listing}
      breadcrumb={[{ label: 'Collections', href: '/admin/collections' }, { label }]}
      actions={
        <>
          {action}

          {/* No point offering to narrow a list that has nothing in it. The one
              action that still makes sense on an empty list is adding to it. */}
          {entries.length > 0 && (
            <>
              <Dropdown
                align="end"
                label={view.status === 'All' ? 'Status' : `Status: ${view.status}`}
                triggerClassName={`rounded-control border border-border ${tint} px-2.5 py-1 text-sm text-muted hover:text-ink`}
              >
                {(['All', 'Published', 'Draft'] as const).map((status) => (
                  <DropdownItem
                    key={status}
                    onClick={() => update({ status })}
                    aria-current={view.status === status ? 'true' : undefined}
                    className={view.status === status ? 'font-medium' : undefined}
                  >
                    {status}
                  </DropdownItem>
                ))}
              </Dropdown>

              <Input
                size="sm"
                ground="surface"
                fullWidth={false}
                type="search"
                value={view.query}
                onChange={(event) => update({ query: event.target.value })}
                placeholder="Search"
                aria-label={`Search ${label.toLowerCase()}`}
                className="w-48"
              />
            </>
          )}
        </>
      }
    >
      {entries.length === 0 ? (
        <div className="grid h-full place-content-center bg-canvas px-4 text-center">
          <p className="text-sm font-medium">Nothing here yet</p>
          <p className="pt-1 text-sm text-muted">The first one you publish shows up in this list.</p>
        </div>
      ) : (
        <div className="flex h-full min-h-0 flex-col bg-canvas">
          {/* The running total is announced from the footer, where it is always
              mounted; this one only ever speaks about the selection. */}
          <span aria-live="polite" className="sr-only">
            {chosen.length === 0 ? 'Nothing selected' : `${chosen.length} selected`}
          </span>

          {/*
            | Stays inside the content region rather than going up into the bar
            | with the other controls: it appears and disappears with the
            | selection, and the fixed chrome changing height as you tick a row
            | is the chrome no longer being fixed.
            */}
          {chosen.length > 0 && (
            <div className={`flex shrink-0 flex-wrap items-center gap-2 border-b border-border ${tint} px-5 py-3`}>
              <span className="text-sm">{chosen.length} selected</span>

              <Button variant="secondary" onClick={() => console.log('duplicate', chosen.map((entry) => entry.path))}>
                Duplicate
              </Button>
              <Button variant="danger" onClick={() => console.log('trash', chosen.map((entry) => entry.path))}>
                Move to trash
              </Button>

              <button
                type="button"
                onClick={() => setSelected(new Set())}
                className="ml-auto rounded-control text-sm text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              >
                Clear selection
              </button>
            </div>
          )}

          {/* Nine columns and a menu do not fit a narrow viewport; the table
              keeps its shape and scrolls inside its own box rather than widening
              the page. The bar above and the footer below stay put. */}
          <div className="min-h-0 flex-1 overflow-auto">
            {/* Named by the same string the breadcrumb's last crumb uses, rather
                than by a heading of its own -- there is no longer a heading to
                point at, and two names for one list is one too many. */}
            <table aria-label={label} className="w-full text-sm">
              <thead>
                {/* A collapsed border belongs to the table grid rather than to
                    the cell, so it does not travel with a stuck header and the
                    rule under it disappears on scroll. An inset shadow is
                    painted on the row itself and stays. */}
                <tr
                  className={`text-left text-xs text-muted [&>th]:sticky [&>th]:top-0 [&>th]:z-10 ${headCell} [&>th]:shadow-[inset_0_-1px_0_var(--color-border)]`}
                >
                  <th scope="col" className="w-12 px-5 py-3">
                    <Checkbox
                      checked={onPage.all}
                      indeterminate={onPage.some}
                      onChange={togglePage}
                      disabled={rows.length === 0}
                      label={onPage.all ? 'Deselect all on this page' : 'Select all on this page'}
                    />
                  </th>
                  <th scope="col" className="w-16 px-3 py-3">
                    <span className="sr-only">Preview</span>
                  </th>
                  <SortHeader field="title" view={view} onSort={sort}>Title</SortHeader>
                  <SortHeader field="status" view={view} onSort={sort}>Status</SortHeader>
                  <SortHeader field="category" view={view} onSort={sort}>Category</SortHeader>
                  <SortHeader field="author" view={view} onSort={sort}>Author</SortHeader>
                  <SortHeader field="released" view={view} onSort={sort}>Released</SortHeader>
                  <SortHeader field="modified" view={view} onSort={sort}>Modified</SortHeader>
                  <th scope="col" className="w-10">
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>

              <tbody>
                {rows.length === 0 && (
                  <tr>
                    <td colSpan={9} className="px-5 py-16 text-center text-sm text-muted">
                      No {label.toLowerCase()} match that search.
                    </td>
                  </tr>
                )}

                {rows.map((entry) => (
                  <tr
                    key={entry.path}
                    className={`group border-b border-border last:border-b-0 ${
                      selected.has(entry.path) ? tint : ''
                    }`}
                  >
                    <td className="w-12 px-5 py-3.5 align-middle">
                      <Checkbox
                        checked={selected.has(entry.path)}
                        onChange={() => toggle(entry.path)}
                        label={`Select ${entry.title}`}
                      />
                    </td>

                    <td className="w-16 px-3 py-3.5 align-middle">
                      {/* Solid rather than dashed: this column is mostly real
                          previews, and the initial is a stand-in for the page
                          rather than an empty slot. */}
                      <Thumbnail
                        src={entry.thumbnail}
                        fallback={[...entry.title.trim()][0]?.toUpperCase() ?? '?'}
                      />
                    </td>

                    <td className={cell}>
                      <a
                        href={under(entry.path, 'edit')}
                        className="rounded-control font-medium hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                      >
                        {entry.title}
                      </a>
                    </td>

                    <td className={cell}>
                      <StatusChip status={entry.status} ground="surface" />
                    </td>

                    <td className={`${cell} text-muted`}>{entry.category}</td>
                    <td className={`${cell} whitespace-nowrap text-muted`}>{entry.author}</td>
                    <td className={`${cell} whitespace-nowrap text-muted`}>
                      <DateText iso={entry.released} empty="Not released" />
                    </td>
                    <td className={`${cell} whitespace-nowrap text-muted`}>
                      <DateText iso={entry.modified} />
                    </td>

                    {/* Revealed on hover like the site editor's, but kept in the
                        DOM: opacity leaves it focusable, which is what carries a
                        keyboard to it. has-[details[open]] stops it fading out
                        from under its own open menu when the pointer leaves. */}
                    <td className="px-3 py-3.5 align-middle">
                      <div className="opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 has-[details[open]]:opacity-100 pointer-coarse:opacity-100">
                        <Dropdown
                          align="end"
                          chevron={false}
                          label={<MoreIcon title={entry.title} />}
                          triggerClassName="rounded-control p-1 text-muted hover:bg-canvas hover:text-ink"
                        >
                          <DropdownItem onClick={() => console.log('edit', entry.path)}>Edit</DropdownItem>
                          {entry.status === 'Published' && (
                            <DropdownItem onClick={() => console.log('view', entry.path)}>View</DropdownItem>
                          )}
                          <DropdownItem onClick={() => console.log('duplicate', entry.path)}>Duplicate</DropdownItem>
                          <DropdownItem onClick={() => console.log('rename', entry.path)}>Rename</DropdownItem>
                          <DropdownItem onClick={() => console.log('trash', entry.path)} danger>
                            Move to trash
                          </DropdownItem>
                        </Dropdown>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="flex shrink-0 items-center justify-between gap-2 border-t border-border px-5 py-3 text-xs text-muted">
            <span aria-live="polite">
              {total === 0 ? 'No results' : `${start + 1}–${start + rows.length} of ${total}`}
            </span>

            <div className="flex items-center gap-2">
              <Step onClick={() => update({ page: page - 1 })} disabled={page === 1} label="Previous page">
                &lsaquo;
              </Step>
              <span>
                {page} of {pageCount}
              </span>
              <Step onClick={() => update({ page: page + 1 })} disabled={page === pageCount} label="Next page">
                &rsaquo;
              </Step>
            </div>
          </div>
        </div>
      )}
    </Shell>
  )
}

/*
 | The header is the sort control, as in the site editor. aria-sort goes on the
 | cell rather than the button -- it describes the column, and a screen reader
 | reads it when it announces the header.
 */
function SortHeader({
  field,
  view,
  onSort,
  children,
}: {
  field: SortField
  view: View
  onSort: (field: SortField) => void
  children: string
}) {
  const active = view.field === field

  return (
    <th
      scope="col"
      aria-sort={active ? (view.direction === 'asc' ? 'ascending' : 'descending') : undefined}
      className="px-5 py-3 font-medium"
    >
      <button
        type="button"
        onClick={() => onSort(field)}
        className="flex items-center gap-1 rounded-control hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
      >
        {children}
        <span className="sr-only">, sort</span>
        <Icon
          className={`size-3 ${active ? '' : 'opacity-0'} ${active && view.direction === 'asc' ? 'rotate-180' : ''}`}
        >
          <path d="m4 6 4 4 4-4" />
        </Icon>
      </button>
    </th>
  )
}

function Step({
  onClick,
  disabled,
  label,
  children,
}: {
  onClick: () => void
  disabled: boolean
  label: string
  children: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={label}
      className="rounded-control border border-border px-2 py-0.5 text-ink hover:bg-surface disabled:pointer-events-none disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
    >
      {children}
    </button>
  )
}

const meta = {
  title: 'Page list',
  component: PageList,
  args: { entries: pages, label: 'Pages', action: <Button>New page</Button> },
  parameters: { layout: 'fullscreen', controls: { disable: true } },
  excludeStories: ['PageList', 'shapeEntries', 'selectionState', 'pages', 'posts', 'many'],
  decorators: [(Story) => <div className="-m-6 h-dvh"><Story /></div>] as Decorator[],
} satisfies Meta<typeof PageList>

export default meta

export const Pages: StoryObj<typeof meta> = {}

/* The same list, and the reason it takes entries rather than reading a store. */
export const Posts: StoryObj<typeof meta> = {
  args: { entries: posts, label: 'Posts', action: <Button>New post</Button> },
}

/* Enough rows that the footer has something to do, and that the rows are the
   only thing scrolling while the bar and the footer stay put. */
export const Paginated: StoryObj<typeof meta> = {
  args: { entries: many },
}

/* What a fresh install opens on. */
export const Empty: StoryObj<typeof meta> = {
  args: { entries: [] },
}
