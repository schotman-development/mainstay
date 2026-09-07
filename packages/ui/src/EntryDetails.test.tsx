import { act } from 'react'
import { createRoot } from 'react-dom/client'
import { expect, test } from 'vitest'
import { EntryDetails, blank, changed, draft, published } from './EntryDetails.stories'

/* React only treats act() as act() when it is told it is in a test. The flag is
   read off the global object and has no declaration to widen, so it is cast at
   the one place it is set rather than declared globally for the whole package. */
;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true

/*
 | By label, because the screen now brings the fixed shell with it and the first
 | input in the document is the command center's, not the form's. Going through
 | the <label> also means the test fails if a field ever loses its labelling.
 */
function fieldNamed(host: Element, label: string) {
  const target = [...host.querySelectorAll('label')].find((el) => el.textContent === label)?.htmlFor

  return host.querySelector<HTMLInputElement | HTMLTextAreaElement>(`#${CSS.escape(target!)}`)!
}

function mount(entry: typeof draft) {
  const host = document.createElement('div')
  document.body.append(host)

  act(() => createRoot(host).render(<EntryDetails entry={entry} />))

  return host
}

/* React does not see a value set on the node itself, so the setter has to be
   called through the prototype for the change to reach the component. */
function type(field: HTMLInputElement | HTMLTextAreaElement, value: string) {
  const prototype = field instanceof HTMLTextAreaElement ? HTMLTextAreaElement : HTMLInputElement

  act(() => {
    Object.getOwnPropertyDescriptor(prototype.prototype, 'value')!.set!.call(field, value)
    field.dispatchEvent(new Event('input', { bubbles: true }))
  })
}

const press = (field: Element, key: string) =>
  act(() => {
    field.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))
  })

const tagField = (host: Element) => fieldNamed(host, 'Tags') as HTMLInputElement

const tagsOf = (host: Element) =>
  [...host.querySelectorAll('.sr-only')].map((el) => el.textContent).filter((text) => text?.startsWith('Remove '))

/* ---- changed() ---------------------------------------------------------- */

test('an edit to any field reads as unsaved', () => {
  expect(changed(draft, draft)).toBe(false)
  expect(changed(draft, { ...draft, excerpt: 'x' })).toBe(true)
  expect(changed(draft, { ...draft, released: '2026-01-01' })).toBe(true)
  expect(changed(draft, { ...draft, seoDescription: 'x' })).toBe(true)
})

/* Tags are a fresh array on every edit, so reference equality would call every
   entry dirty forever and the header would never go quiet. */
test('an equal tag list is not an edit, a different one is', () => {
  expect(changed(draft, { ...draft, tags: [...draft.tags] })).toBe(false)
  expect(changed(draft, { ...draft, tags: draft.tags.slice(0, -1) })).toBe(true)
  expect(changed(draft, { ...draft, tags: [...draft.tags].reverse() })).toBe(true)
})

/* The field list is read off the entries rather than written down, so this
   passes for a field nobody remembered to add to it. thumbnail is optional and
   absent from the blank fixture, which is the case a one-sided key read misses. */
test('a field set for the first time counts as an edit', () => {
  expect(changed(blank, { ...blank, thumbnail: 'preview.png' })).toBe(true)
})

/* Saving is what sets modified, so counting it would leave every entry dirty
   the instant it was saved. */
test('the server touching modified does not', () => {
  expect(changed(draft, { ...draft, modified: '2030-01-01' })).toBe(false)
})

/* ---- tags --------------------------------------------------------------- */

test('Enter commits a tag and clears the box', () => {
  const host = mount(draft)
  const field = tagField(host)

  type(field, 'headless')
  press(field, 'Enter')

  expect(tagsOf(host)).toContain('Remove headless')
  expect(field.value).toBe('')
})

test('a comma commits too, without landing in the next tag', () => {
  const host = mount(draft)
  const field = tagField(host)

  type(field, 'design')
  press(field, ',')

  expect(tagsOf(host)).toContain('Remove design')
  expect(field.value).toBe('')
})

/* Otherwise "Editor" quietly becomes a second tag that filters to a different
   set of entries than "editor". */
test('a tag that differs only in case is not added twice', () => {
  const host = mount(draft)
  const before = tagsOf(host).length

  type(tagField(host), 'EDITOR')
  press(tagField(host), 'Enter')

  expect(tagsOf(host)).toHaveLength(before)
})

test('blank input is not committed', () => {
  const host = mount(draft)
  const before = tagsOf(host).length

  type(tagField(host), '   ')
  press(tagField(host), 'Enter')

  expect(tagsOf(host)).toHaveLength(before)
})

/* The one thing a chip list is always missing. */
test('Backspace on an empty box takes the last tag back', () => {
  const host = mount(draft)

  press(tagField(host), 'Backspace')

  expect(tagsOf(host)).toHaveLength(draft.tags.length - 1)
  expect(tagsOf(host)).not.toContain(`Remove ${draft.tags.at(-1)}`)
})

/* A tag typed and left sitting in the box looks added and is not -- and the
   save button is one click away, which is exactly the blur that loses it. */
test('leaving the box commits what is in it', () => {
  const host = mount(draft)
  const field = tagField(host)

  type(field, 'typography')
  act(() => {
    field.dispatchEvent(new FocusEvent('focusout', { bubbles: true }))
  })

  expect(tagsOf(host)).toContain('Remove typography')
})

/* ---- the screen --------------------------------------------------------- */

/*
 | The body is edited on the rendered site, so this screen must not offer a
 | text surface for it -- only the way out to the one that does.
 */
test('there is nowhere to write the body here', () => {
  const host = mount(draft)

  expect(host.querySelector('[contenteditable]')).toBeNull()
  expect(host.querySelector('[role="textbox"]')).toBeNull()

  const handoff = host.querySelector<HTMLAnchorElement>('a[href*="edit=1"]')!
  expect(handoff.href).toContain(draft.path)
  expect(handoff.textContent).toContain('Edit content')
})

/* With no content there is no preview to recognise the page by, so the card has
   to read as an invitation rather than as a hole. */
test('an empty entry is invited to start rather than told it is broken', () => {
  const host = mount(blank)

  expect(host.querySelector('a[href*="edit=1"]')?.textContent).toContain('Start writing')
  expect(host.querySelector('section[aria-label="Content"]')?.textContent).toContain('Nothing written yet')
})

test('the rail dropdown is named by its field, not just its value', () => {
  const host = mount(draft)
  const names = [...host.querySelectorAll('summary')].map((el) => el.textContent?.trim())

  expect(names).toContain('Status: Draft')
})

test('hints are reachable, not just visible', () => {
  const host = mount(draft)
  const describedBy = fieldNamed(host, 'Summary').getAttribute('aria-describedby')!

  expect(host.querySelector(`#${CSS.escape(describedBy)}`)?.textContent).toContain('listed, quoted or shared')
})

/* Over the limit is information, not an error: the snippet gets truncated,
   nothing breaks. So it colours and never blocks.
   Found by its own text rather than by .text-danger: the shell's "Move to
   trash" wears that class too, so a class lookup would pass with no counter
   on the page at all. */
test('the meta description counter warns past the limit without stopping you', () => {
  const host = mount(published)
  const field = fieldNamed(host, 'Meta description')

  type(field, 'x'.repeat(200))

  const counter = [...host.querySelectorAll('span')].find((el) => el.textContent === '200/160')

  expect(counter).toBeDefined()
  expect(counter!.className).toContain('text-danger')
  expect(field.value).toHaveLength(200)
})

/* ---- the shell ---------------------------------------------------------- */

/*
 | The breadcrumb belongs to the shell, so the screen must not draw one of its
 | own -- two trails disagreeing about where you are is worse than none.
 */
test('there is exactly one breadcrumb and the shell owns it', () => {
  const host = mount(draft)
  const trails = host.querySelectorAll('nav[aria-label="Breadcrumb"]')

  expect(trails).toHaveLength(1)
  expect([...trails[0]!.querySelectorAll('a, span[aria-current]')].map((el) => el.textContent)).toEqual([
    'Collections',
    'Posts',
    draft.title,
  ])
})

/* No href is what marks the page you are on, so the two cannot be set to
   disagree. */
test('the last crumb is the current page and is not a link', () => {
  const host = mount(draft)
  const trail = host.querySelector('nav[aria-label="Breadcrumb"]')!
  const last = trail.lastElementChild!

  expect(last.getAttribute('aria-current')).toBe('page')
  expect(last.tagName).toBe('SPAN')
})

/* Renaming should show in the trail before you commit to it, so the crumb
   tracks the field rather than the saved entry. */
test('the trail follows the title as it is typed', () => {
  const host = mount(draft)
  type(fieldNamed(host, 'Title'), 'Renamed in the field')

  expect(host.querySelector('nav[aria-label="Breadcrumb"]')!.lastElementChild!.textContent).toBe(
    'Renamed in the field',
  )
})

/* The screen mounts inside the fixed shell rather than beside it. */
test('the fixed shell comes with the screen', () => {
  const host = mount(draft)

  expect(host.querySelector('nav[aria-label="Main"]')).not.toBeNull()
  expect(host.querySelector('[aria-label="Publishing"]')).not.toBeNull()
})
