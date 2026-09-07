import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { expect, test } from 'vitest'
import { Sidebar, activeItem, type SidebarSection } from './Sidebar'

const sections: SidebarSection[] = [
  { label: 'Content', items: [{ href: '/admin', label: 'Overview' }, { href: '/admin/pages', label: 'Pages' }] },
  { label: 'System', items: [{ href: '/admin/settings', label: 'Settings' }] },
]

const marked = (current?: string) => activeItem(sections, current)?.href

test('marks the item the pathname belongs to', () => {
  expect(marked('/admin/pages')).toBe('/admin/pages')
})

test('a child route still marks its section', () => {
  expect(marked('/admin/pages/42/edit')).toBe('/admin/pages')
})

/* The reason the match is longest-first: /admin is a prefix of every route. */
test('the shallowest item does not claim its siblings', () => {
  expect(marked('/admin/settings')).toBe('/admin/settings')
  expect(marked('/admin')).toBe('/admin')
})

test('a partial segment is not a match', () => {
  expect(marked('/admin/pages-archive')).toBe('/admin')
  expect(marked('/elsewhere')).toBeUndefined()
  expect(marked(undefined)).toBeUndefined()
})

/*
 | A caller handing over location.href rather than location.pathname used to
 | fall through to the shallowest item, marking the wrong section outright.
 */
test('a query string or hash is not part of the route', () => {
  expect(marked('/admin/pages?status=draft')).toBe('/admin/pages')
  expect(marked('/admin/pages#top')).toBe('/admin/pages')
  expect(marked('/admin/pages/42?tab=seo#meta')).toBe('/admin/pages')
})

/* A doubled slash used to fall through and mark the shallowest item instead. */
test('repeated slashes are not part of the route', () => {
  expect(marked('/admin//pages')).toBe('/admin/pages')
  expect(marked('//admin//pages//42')).toBe('/admin/pages')
  expect(activeItem([{ items: [{ href: '/admin//pages', label: 'Pages' }] }], '/admin/pages')?.label).toBe('Pages')
})

test('trailing slashes match on either side', () => {
  expect(marked('/admin/pages/')).toBe('/admin/pages')
  expect(activeItem([{ items: [{ href: '/admin/pages/', label: 'Pages' }] }], '/admin/pages')?.label).toBe('Pages')
})

/* "/" prefixes every path on the site, so it can only ever match itself. */
test('a root item does not claim the whole site', () => {
  const root: SidebarSection[] = [{ items: [{ href: '/', label: 'Home' }] }]

  expect(activeItem(root, '/')?.label).toBe('Home')
  expect(activeItem(root, '/totally/unrelated')).toBeUndefined()
  expect(activeItem([{ items: [{ href: '', label: 'Home' }] }], '/anything')).toBeUndefined()
})

test('two items on the same route mark one link, not both', () => {
  const pinned: SidebarSection[] = [
    { label: 'Pinned', items: [{ href: '/admin/pages', label: 'Pages' }] },
    ...sections,
  ]
  const html = renderToStaticMarkup(createElement(Sidebar, { sections: pinned, current: '/admin/pages' }))

  expect(html.match(/aria-current="page"/g)).toHaveLength(1)
})

test('exactly one link carries aria-current', () => {
  const html = renderToStaticMarkup(createElement(Sidebar, { sections, current: '/admin/pages/42' }))

  expect(html.match(/aria-current="page"/g)).toHaveLength(1)
  expect(html).toMatch(/aria-current="page"[^>]*href="\/admin\/pages"/)
})

test('each group labels its own list', () => {
  const html = renderToStaticMarkup(createElement(Sidebar, { sections }))
  const headings = [...html.matchAll(/<h2 id="([^"]+)"/g)].map((match) => match[1])

  expect(headings).toHaveLength(2)
  for (const heading of headings) expect(html).toContain(`aria-labelledby="${heading}"`)
})

const tree: SidebarSection[] = [
  {
    items: [
      {
        href: '/admin/collections',
        label: 'Collections',
        items: [
          {
            href: '/admin/collections/posts',
            label: 'Posts',
            submenu: 'flyout' as const,
            items: [
              { href: '/admin/collections/posts', label: 'View posts' },
              { href: '/admin/collections/posts/new', label: 'New post' },
            ],
          },
        ],
      },
    ],
  },
]

/* Matching that stopped at the first level handed this to Collections. */
test('a sub item route beats the parent it hangs off', () => {
  expect(activeItem(tree, '/admin/collections/posts/new')?.label).toBe('New post')
  expect(activeItem(tree, '/admin/collections/posts/42/edit')?.label).toBe('Posts')
  expect(activeItem(tree, '/admin/collections')?.label).toBe('Collections')
})

test('being inside a collection opens it in place of its flyout', () => {
  const inside = renderToStaticMarkup(
    createElement(Sidebar, { sections: tree, current: '/admin/collections/posts/42' }),
  )

  /* Collections is a toggle, so it is the only disclosure in the tree, and it
     is pinned open the whole way down to the page being viewed. */
  expect(inside.match(/aria-expanded="true"/g)).toHaveLength(1)
  expect(inside).not.toContain('invisible')
  expect(inside).not.toContain('class="hidden')
})

test('a shut toggle hides its list rather than flying it out', () => {
  const outside = renderToStaticMarkup(
    createElement(Sidebar, { sections: tree, current: '/admin/pages' }),
  )

  /* Its own list goes behind display:none, which takes the collection flyout
     nested inside it out of reach along with it. */
  expect(outside).toMatch(/<ul id="[^"]+" class="hidden">/)
  expect(outside).not.toContain('aria-expanded="true"')
})

/* Collections shut takes its collections down with it, so the flyout only has
   to be checked with the parent open -- which is the state it is reached in. */
test('a collection carries a flyout and no disclosure of its own', () => {
  const open = renderToStaticMarkup(
    createElement(Sidebar, { sections: tree, current: '/admin/collections' }),
  )

  expect(open.match(/invisible/g)).toHaveLength(1)
  expect(open.match(/aria-expanded=/g)).toHaveLength(1)
  expect(open).toMatch(/invisible[^"]*absolute[^"]*left-full/)
})

/*
 | Landing anywhere in a section opens it, and it stays closeable from there --
 | a row that is itself the disclosure cannot be a row that does nothing when
 | clicked.
 */
test('the section you are in is open and never taken out of use', () => {
  for (const current of ['/admin/collections', '/admin/collections/posts/new']) {
    const html = renderToStaticMarkup(createElement(Sidebar, { sections: tree, current }))

    expect(html).toContain('aria-expanded="true"')
    expect(html).not.toMatch(/<button[^>]*\sdisabled=""/)
  }
})

/*
 | The disclosure is the row, not a control parked at the end of it: the label
 | is inside the button, and the button is the only thing on the row.
 */
test('a toggle row is one button carrying the whole item', () => {
  const html = renderToStaticMarkup(
    createElement(Sidebar, { sections: tree, current: '/admin/pages' }),
  )

  expect(html).toMatch(/<button[^>]*class="[^"]*w-full[^"]*"[^>]*>(?:(?!<\/button>).)*Collections/)
  expect(html.match(/<button/g)).toHaveLength(1)
  expect(html).not.toContain('href="/admin/collections"')
})

/*
 | Each level steps in less than the one before it, and the third gets a rule
 | as well as the smaller type -- three things saying the same thing, because
 | indentation alone stops being readable by the time it is this shallow.
 */
test('third level items step in behind a rule and drop a size', () => {
  const html = renderToStaticMarkup(
    createElement(Sidebar, { sections: tree, current: '/admin/collections/posts/new' }),
  )

  expect(html).toContain('class="mt-1 ml-3.5 flex flex-col gap-1 pl-2"')
  expect(html).toContain('class="mt-1 ml-2 flex flex-col gap-1 border-l border-border pl-2"')

  expect(html).toMatch(/<a[^>]*\btext-xs\b[^>]*>View posts</)
  expect(html).toMatch(/<a[^>]*\btext-xs\b[^>]*>New post</)
  expect(html).toMatch(/<a[^>]*\btext-sm\b[^>]*>Posts</)

  /* The rule is the third level's alone: the second still has none. */
  expect(html.match(/border-l\b/g)).toHaveLength(1)
})
