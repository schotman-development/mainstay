import { beforeEach, expect, test } from 'vitest'
import { sidebar } from './sidebar'

/*
 | The route decides whether a section is open; this is the reader's override on
 | top of it, and which side of the route it was made on is what stops the answer
 | leaking across the boundary.
 */
function nav({ inside }: { inside: boolean }) {
  document.body.innerHTML = `
    <nav>
      <button
        data-sidebar-toggle
        aria-expanded="${inside}"
        aria-controls="sidebar-0.0"
        data-inside="${inside}"
      >Collections</button>
      <ul id="sidebar-0.0" data-open="${inside}"></ul>
    </nav>`

  sidebar()

  return {
    toggle: document.querySelector<HTMLElement>('[data-sidebar-toggle]')!,
    panel: document.getElementById('sidebar-0.0')!,
  }
}

beforeEach(() => {
  document.body.innerHTML = ''
  sessionStorage.clear()
})

test('clicking the row folds it, and clicking again puts it back', () => {
  const { toggle, panel } = nav({ inside: true })

  toggle.click()
  expect([panel.dataset.open, toggle.getAttribute('aria-expanded')]).toEqual(['false', 'false'])

  toggle.click()
  expect([panel.dataset.open, toggle.getAttribute('aria-expanded')]).toEqual(['true', 'true'])
})

/*
 | Without this, closing the section you are standing in leaves the only
 | aria-current in the tree behind display:none: nothing marked on screen, and
 | nothing for a screen reader to find either. "true" rather than "page" because
 | the row is not the page -- it is the current item in the set.
 */
test('a folded row stands in for the page it is hiding', () => {
  const { toggle } = nav({ inside: true })

  expect(toggle.hasAttribute('aria-current')).toBe(false)

  toggle.click()
  expect(toggle.getAttribute('aria-current')).toBe('true')

  toggle.click()
  expect(toggle.hasAttribute('aria-current')).toBe(false)
})

/* A row that is itself the page keeps saying so either way. */
test('a row that is the page says page, folded or not', () => {
  const { toggle } = nav({ inside: true })

  toggle.dataset.current = 'page'
  toggle.click()

  expect(toggle.getAttribute('aria-current')).toBe('page')
})

/* Fold it away and walk deeper and it stays folded. */
test('the override sticks on the side of the route it was made on', () => {
  nav({ inside: true }).toggle.click()

  const { panel } = nav({ inside: true })

  expect(panel.dataset.open).toBe('false')
})

/*
 | Opening a section from outside it is the case where the two answers differ:
 | the route says shut, the reader said open. It has to stick out here...
 */
test('a section opened from outside stays open out there', () => {
  nav({ inside: false }).toggle.click()

  expect(nav({ inside: false }).panel.dataset.open).toBe('true')
})

/*
 | ...and say nothing about the inside, where the route's own answer applies
 | again. There is one answer per row, not one per side: recording which side it
 | was made on is what makes it apply there and nowhere else, so a later answer
 | from the other side replaces it rather than sitting beside it. That is the
 | React state this was ported from, and the same single slot.
 */
test('the override applies on its own side of the route and is replaced from the other', () => {
  nav({ inside: false }).toggle.click()

  /* Walking in: the route opens it, the outside answer does not apply. */
  expect(nav({ inside: true }).panel.dataset.open).toBe('true')

  /* Folding it from in here answers for the inside, and takes the slot. */
  document.body.innerHTML = ''
  nav({ inside: true }).toggle.click()

  expect(nav({ inside: true }).panel.dataset.open).toBe('false')

  /* Back outside, there is no longer an answer for out here, so the route
     decides again. */
  expect(nav({ inside: false }).panel.dataset.open).toBe('false')
})

test('a row nobody has touched follows the route', () => {
  expect(nav({ inside: true }).panel.dataset.open).toBe('true')

  document.body.innerHTML = ''
  expect(nav({ inside: false }).panel.dataset.open).toBe('false')
})
