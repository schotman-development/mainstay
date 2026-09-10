import { beforeEach, expect, test } from 'vitest'
import { entryLists } from './entry-list'

/*
 | Selection outlives paging, so the two counts on this screen answer different
 | questions: the header checkbox is about the rows you can see, and the bulk bar
 | is about everything the search left. Getting those the same way is the bug
 | these pin.
 */
function list(rows: string[], matched = rows, name = 'Pages') {
  document.body.innerHTML = `
    <div data-entry-list="${name}">
      <script type="application/json" data-entry-matched>${JSON.stringify(matched)}</script>
      <span data-selection-live></span>
      <div data-selection-bar hidden><span data-selection-count>0</span><button data-selection-clear></button></div>
      <input type="checkbox" data-select-page aria-label="Select all on this page">
      ${rows.map((path) => `<input type="checkbox" data-select-row value="${path}">`).join('')}
    </div>`

  entryLists()

  return document.querySelector<HTMLElement>('[data-entry-list]')!
}

const tick = (path: string) => {
  const box = document.querySelector<HTMLInputElement>(`[data-select-row][value="${path}"]`)!

  box.checked = true
  box.dispatchEvent(new Event('change', { bubbles: true }))
}

const bar = () => document.querySelector<HTMLElement>('[data-selection-bar]')!
const count = () => document.querySelector('[data-selection-count]')!.textContent
const head = () => document.querySelector<HTMLInputElement>('[data-select-page]')!

beforeEach(() => {
  document.body.innerHTML = ''
  sessionStorage.clear()
})

test('the header checkbox reflects only the rows on screen', () => {
  list(['/a', '/b'])

  expect([head().checked, head().indeterminate]).toEqual([false, false])

  tick('/a')
  expect([head().checked, head().indeterminate]).toEqual([false, true])

  tick('/b')
  expect([head().checked, head().indeterminate]).toEqual([true, false])
})

test('the bar appears with the selection and says how many', () => {
  list(['/a', '/b'])

  expect(bar().hidden).toBe(true)

  tick('/a')

  expect(bar().hidden).toBe(false)
  expect(count()).toBe('1')
  expect(document.querySelector('[data-selection-live]')!.textContent).toBe('1 selected')
})

/*
 | The bug this exists for: page forward with rows ticked and the bar used to
 | say "Nothing selected" while the buttons would still have reached them.
 */
test('a selection made on another page is still counted here', () => {
  list(['/a', '/b'], ['/a', '/b', '/c', '/d'])
  tick('/a')
  tick('/b')

  /* The next page of the same list: different rows, same search behind it. */
  list(['/c', '/d'], ['/a', '/b', '/c', '/d'])

  expect(count()).toBe('2')
  expect(bar().hidden).toBe(false)
  /* ...but nothing on this page is ticked, so the header box is empty. */
  expect([head().checked, head().indeterminate]).toEqual([false, false])
})

/* Scoped to what the search left, so "Move to trash" can never reach a row you
   cannot see. */
test('a selection the search has excluded is not counted', () => {
  list(['/a', '/b'], ['/a', '/b'])
  tick('/a')
  tick('/b')

  list(['/b'], ['/b'])

  expect(count()).toBe('1')
})

test('the page checkbox ticks and unticks every row on screen', () => {
  list(['/a', '/b'])

  head().checked = true
  head().dispatchEvent(new Event('change', { bubbles: true }))

  expect(count()).toBe('2')

  head().checked = false
  head().dispatchEvent(new Event('change', { bubbles: true }))

  expect(count()).toBe('0')
})

/* The bar is counting rows you cannot see, so a Clear that left them behind
   would be a lie. */
test('clearing drops the rows on other pages too', () => {
  list(['/a', '/b'], ['/a', '/b', '/c'])
  tick('/a')

  list(['/c'], ['/a', '/b', '/c'])
  expect(count()).toBe('1')

  document.querySelector<HTMLButtonElement>('[data-selection-clear]')!.click()

  list(['/a', '/b'], ['/a', '/b', '/c'])
  expect(count()).toBe('0')
})

/* Two lists in one session must not share a selection. */
test('each list keeps its own', () => {
  list(['/a'], ['/a'], 'Pages')
  tick('/a')

  list(['/x'], ['/x'], 'Posts')

  expect(count()).toBe('0')
})
