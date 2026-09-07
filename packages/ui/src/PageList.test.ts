import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { expect, test } from 'vitest'
import { PageList, pages, selectionState, shapeEntries, type View } from './PageList.stories'

const view = (patch: Partial<View> = {}): View => ({
  query: '',
  status: 'All',
  field: 'modified',
  direction: 'desc',
  page: 1,
  perPage: 8,
  ...patch,
})

const titles = (patch?: Partial<View>) => shapeEntries(pages, view(patch)).rows.map((row) => row.title)

test('opens on the most recently modified', () => {
  expect(titles()).toEqual(['Home', 'About', 'Pricing', 'Careers', 'Terms of service'])
})

test('sorts by any column, both ways', () => {
  expect(titles({ field: 'title', direction: 'asc' })).toEqual(['About', 'Careers', 'Home', 'Pricing', 'Terms of service'])
  expect(titles({ field: 'title', direction: 'desc' })).toEqual(['Terms of service', 'Pricing', 'Home', 'Careers', 'About'])
})

/*
 | Careers and Terms of service share a modified date. The order they keep is
 | the order they were given, which is how a caller controls tie-breaks.
 */
test('ties keep author order', () => {
  expect(titles({ field: 'modified', direction: 'desc' }).slice(3)).toEqual(['Careers', 'Terms of service'])
})

/* Drafts have no release date, and must not lead the list when sorting by it. */
test('missing release dates sort last whichever way the column points', () => {
  expect(titles({ field: 'released', direction: 'desc' })).toEqual(['Careers', 'Home', 'About', 'Terms of service', 'Pricing'])
  expect(titles({ field: 'released', direction: 'asc' })).toEqual(['Home', 'About', 'Careers', 'Terms of service', 'Pricing'])
})

test('search covers title, author and category, case-insensitively', () => {
  expect(titles({ query: 'PRIC' })).toEqual(['Pricing'])
  expect(titles({ query: 'grace' })).toEqual(['About', 'Terms of service'])
  expect(titles({ query: 'marketing' })).toEqual(['Home', 'About', 'Pricing'])
  expect(titles({ query: '  home  ' })).toEqual(['Home'])
  expect(titles({ query: 'nothing at all' })).toEqual([])
})

test('the status filter narrows to one state', () => {
  expect(titles({ status: 'Draft' })).toEqual(['Pricing', 'Terms of service'])
  expect(titles({ status: 'Published' })).toEqual(['Home', 'About', 'Careers'])
})

test('filters and sorting compose', () => {
  expect(titles({ status: 'Published', field: 'title', direction: 'asc' })).toEqual(['About', 'Careers', 'Home'])
})

test('pages the result and reports where it is', () => {
  const first = shapeEntries(pages, view({ perPage: 2 }))

  expect(first.rows.map((row) => row.title)).toEqual(['Home', 'About'])
  expect(first).toMatchObject({ total: 5, page: 1, pageCount: 3, start: 0 })

  const last = shapeEntries(pages, view({ perPage: 2, page: 3 }))

  expect(last.rows.map((row) => row.title)).toEqual(['Terms of service'])
  expect(last).toMatchObject({ page: 3, pageCount: 3, start: 4 })
})

/*
 | Narrowing the query shortens the result under a page the caller is already
 | holding, so an unclamped page renders an empty table with rows behind it.
 */
test('a page past the end is clamped, not left empty', () => {
  const shaped = shapeEntries(pages, view({ perPage: 2, page: 99, status: 'Draft' }))

  expect(shaped).toMatchObject({ total: 2, page: 1, pageCount: 1, start: 0 })
  expect(shaped.rows.map((row) => row.title)).toEqual(['Pricing', 'Terms of service'])
})

test('an empty result still reports one page', () => {
  expect(shapeEntries(pages, view({ query: 'nothing', page: 4 }))).toMatchObject({ total: 0, page: 1, pageCount: 1, start: 0 })
})

const paths = (...titles: string[]) =>
  new Set(pages.filter((page) => titles.includes(page.title)).map((page) => page.path))

test('the header checkbox reflects only the rows on screen', () => {
  const rows = shapeEntries(pages, view({ perPage: 2 })).rows

  expect(selectionState(rows, new Set())).toEqual({ count: 0, all: false, some: false })
  expect(selectionState(rows, paths('Home'))).toEqual({ count: 1, all: false, some: true })
  expect(selectionState(rows, paths('Home', 'About'))).toEqual({ count: 2, all: true, some: false })
})

/*
 | Selection outlives paging, so a full page can sit inside a much larger
 | selection and the box must still read as full rather than partial.
 */
test('rows selected on other pages do not make this one partial', () => {
  const rows = shapeEntries(pages, view({ perPage: 2 })).rows

  expect(selectionState(rows, paths('Home', 'About', 'Careers'))).toMatchObject({ all: true, some: false })
})

test('an empty page is never "all selected"', () => {
  expect(selectionState([], paths('Home'))).toEqual({ count: 0, all: false, some: false })
})

/*
 | perPage and page are exported API, and slice() with a negative length drops
 | rows off the end rather than erroring, so a bad one used to return fewer rows
 | than the footer claimed. Nothing here should ever disagree with rows.length.
 */
test('a nonsensical page size still yields a coherent page', () => {
  for (const perPage of [0, -1, -3, 2.5, Number.NaN]) {
    const shaped = shapeEntries(pages, view({ perPage }))

    expect(Number.isInteger(shaped.page)).toBe(true)
    expect(Number.isInteger(shaped.pageCount)).toBe(true)
    expect(Number.isInteger(shaped.start)).toBe(true)
    expect(shaped.rows.length).toBeGreaterThan(0)
    expect(shaped.start + shaped.rows.length).toBeLessThanOrEqual(shaped.total)
  }

  /* Clamped to the smallest sane size rather than to "everything". */
  expect(shapeEntries(pages, view({ perPage: -1 }))).toMatchObject({ pageCount: 5, page: 1 })
  expect(shapeEntries(pages, view({ perPage: -1 })).rows).toHaveLength(1)
  expect(shapeEntries(pages, view({ perPage: 2.5 })).rows).toHaveLength(2)
})

test('a fractional or unparseable page lands on a real one', () => {
  expect(shapeEntries(pages, view({ perPage: 2, page: 2.5 }))).toMatchObject({ page: 2, start: 2 })
  expect(shapeEntries(pages, view({ perPage: 2, page: Number.NaN }))).toMatchObject({ page: 1, start: 0 })
})

/* A decomposed title and a precomposed query are the same word. */
test('search folds unicode to one form', () => {
  const accented = [{ ...pages[0]!, title: 'Cafe\u0301 notes', path: '/cafe' }]

  expect(shapeEntries(accented, view({ query: 'caf\u00e9' })).rows).toHaveLength(1)
  expect(shapeEntries(accented, view({ query: 'cafe\u0301' })).rows).toHaveLength(1)
})

/*
 | The bulk bar acts on the selection, so the selection it reports has to be
 | what the search left behind -- otherwise "Move to trash" reaches rows that
 | are not on screen and cannot be checked before the click.
 */
test('the shaped result carries what the search matched, not just the page', () => {
  const shaped = shapeEntries(pages, view({ perPage: 2 }))

  expect(shaped.matched).toHaveLength(5)
  expect(shaped.rows).toHaveLength(2)

  const searched = shapeEntries(pages, view({ query: 'careers' }))

  expect(searched.matched.map((entry) => entry.title)).toEqual(['Careers'])
  expect(searched.matched.filter((entry) => paths('Home', 'About', 'Careers').has(entry.path))).toHaveLength(1)
})

/*
 | The screen's own toolbar is gone: its controls moved up into the fixed
 | shell's bar, beside the breadcrumb. Rendered rather than reasoned about,
 | because "there is only one heading" is exactly the kind of claim that quietly
 | stops being true the next time the layout moves.
 */
const screen = (entries = pages) =>
  renderToStaticMarkup(createElement(PageList, { entries, label: 'Pages', action: null }))

test('the list has no heading of its own, only the breadcrumb', () => {
  const html = screen()

  expect(html).not.toMatch(/<h1[\s>]/)
  expect([...html.matchAll(/aria-label="Breadcrumb"/g)]).toHaveLength(1)

  /* The trail is what names the list now, so the last crumb has to be it. */
  expect(html).toContain('aria-current="page"')
  expect(html).toMatch(/aria-current="page"[^>]*>Pages</)
})

/* The table lost the heading it was pointed at, so it carries the name itself
   rather than referring to one that is no longer there. Scoped to the table's
   own tag: the shell's chrome has aria-labelledby of its own, and a whole-page
   search would be answering about the wrong element. */
test('the table is still named', () => {
  const html = screen()
  const table = html.slice(html.indexOf('<table'), html.indexOf('>', html.indexOf('<table')) + 1)

  expect(table).toContain('aria-label="Pages"')
  expect(table).not.toContain('aria-labelledby')
})

test('the search and the filter live in the shell bar', () => {
  const html = screen()
  const bar = html.slice(0, html.indexOf('<table'))

  expect(bar).toContain('aria-label="Search pages"')
  expect(bar).toContain('Status')
})

/* Nothing to narrow, so nothing to narrow it with -- but the way to add the
   first one stays. */
test('an empty list keeps the bar and drops the filters', () => {
  const html = screen([])

  expect(html).toContain('Nothing here yet')
  expect(html).toContain('aria-label="Breadcrumb"')
  expect(html).not.toContain('aria-label="Search pages"')
})
