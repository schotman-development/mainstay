import { beforeEach, expect, test } from 'vitest'
import { tabs } from './tabs'

const draw = (open = '') => {
  document.body.innerHTML = `
    <form id="form">
      <div data-tabs="entry" role="tablist" hidden ${open ? `data-tabs-open="${open}"` : ''}>
        <button type="button" role="tab" id="details-tab" aria-controls="details" aria-selected="true">Details</button>
        <button type="button" role="tab" id="content-tab" aria-controls="content" aria-selected="false" tabindex="-1">Content</button>
      </div>
      <div id="details" role="tabpanel">Fields</div>
      <div id="content" role="tabpanel">Page</div>
    </form>`
  tabs()
}

const tab = (name: string) => document.getElementById(`${name}-tab`) as HTMLButtonElement
const panel = (name: string) => document.getElementById(name)!
const key = (name: string) => tab('details').parentElement!.dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true }))

beforeEach(() => {
  sessionStorage.clear()
  draw()
})

test('shows the bar and the first panel alone, which without it read one after the other', () => {
  expect(tab('details').parentElement!.hidden).toBe(false)
  expect([panel('details').hidden, panel('content').hidden]).toEqual([false, true])
})

test('a click picks a tab, its panel shown and it alone in the tab order, and says so', () => {
  let heard = ''
  document.addEventListener('tabchange', (event) => (heard = (event as CustomEvent).detail), { once: true })

  tab('content').click()

  expect([panel('details').hidden, panel('content').hidden]).toEqual([true, false])
  expect([tab('details').getAttribute('aria-selected'), tab('content').getAttribute('aria-selected')]).toEqual(['false', 'true'])
  expect([tab('details').tabIndex, tab('content').tabIndex]).toEqual([-1, 0])
  expect(heard).toBe('content')
})

test('the arrow keys move between the tabs and round, Home and End to either end', () => {
  tab('details').focus()
  key('ArrowRight')
  expect(document.activeElement).toBe(tab('content'))
  expect(panel('content').hidden).toBe(false)

  key('ArrowRight')
  expect(document.activeElement).toBe(tab('details'))

  key('End')
  expect(document.activeElement).toBe(tab('content'))

  key('Home')
  expect(document.activeElement).toBe(tab('details'))
})

test('the tab picked comes back after a reload of the page, and after its form is saved, but not on another opening', () => {
  tab('content').click()
  window.dispatchEvent(new Event('pagehide'))
  draw()
  expect(panel('content').hidden).toBe(false)

  // Read once: the page after this one starts on Details.
  draw()
  expect(panel('details').hidden).toBe(false)

  // A save lands wherever it lands.
  tab('content').click()
  document.getElementById('form')!.dispatchEvent(new Event('submit'))
  window.dispatchEvent(new Event('pagehide'))
  history.replaceState(null, '', '/admin/page/drafts/3')
  draw()
  expect(panel('content').hidden).toBe(false)

  // Another page, then this one again.
  tab('content').click()
  window.dispatchEvent(new Event('pagehide'))
  history.replaceState(null, '', '/admin/page/5')
  draw()
  expect(panel('details').hidden).toBe(false)

  // A list, which has no tabs, and back to the entry.
  tab('content').click()
  window.dispatchEvent(new Event('pagehide'))
  document.body.innerHTML = '<p>The list</p>'
  tabs()
  draw()
  expect(panel('details').hidden).toBe(false)
})

test('opens the panel the page names over the one picked before: the one a refused save is in', () => {
  tab('content').click()
  window.dispatchEvent(new Event('pagehide'))
  draw('details')

  expect(panel('details').hidden).toBe(false)
})
