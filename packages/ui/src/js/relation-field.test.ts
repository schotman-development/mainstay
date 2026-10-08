import { beforeEach, expect, test, vi } from 'vitest'
import { relationFields } from './relation-field'

/* A trimmed copy of what the relation field's component draws. */
const row = (value: string, title: string) => `
  <li data-relation-row draggable="true">
    <input type="hidden" name="related[]" value="${value}">
    <span data-relation-title>${title}</span>
    <button type="button" data-relation-up>Up</button>
    <button type="button" data-relation-down>Down</button>
    <button type="button" data-relation-remove>Remove</button>
  </li>`

beforeEach(() => {
  document.body.innerHTML = `
    <form>
      <div data-relation data-many="true" data-search="/admin/article/relations/related?locale=en">
        <input type="hidden" name="related[]" value="">
        <ol data-relation-rows>${row('1', 'One')}${row('2', 'Two')}${row('3', 'Three')}</ol>
        <template data-relation-template>${row('', '')}</template>
        <input type="search" data-relation-query>
        <ul data-relation-results></ul>
      </div>
    </form>`

  relationFields()
})

const posted = () => new FormData(document.querySelector('form')!).getAll('related[]')
const button = (title: string, which: string) =>
  [...document.querySelectorAll('[data-relation-row]')].find((row) => row.textContent?.includes(title))!.querySelector<HTMLElement>(`[data-relation-${which}]`)!

test('Up, Down and Remove reorder the rows, and the form posts them in that order', () => {
  let heard = 0

  document.querySelector('form')!.addEventListener('input', () => heard++)

  button('Three', 'up').click()
  expect(posted()).toEqual(['', '1', '3', '2'])

  button('One', 'down').click()
  expect(posted()).toEqual(['', '3', '1', '2'])

  button('Three', 'remove').click()
  button('One', 'remove').click()
  button('Two', 'remove').click()
  expect(posted()).toEqual([''])
  expect(heard).toBe(5)
})

test('a row dragged over another lands before or after it, by which half it is over', () => {
  const rows = [...document.querySelectorAll<HTMLElement>('[data-relation-row]')]

  for (const [index, row] of rows.entries()) {
    row.getBoundingClientRect = () => ({ top: index * 20, height: 20 }) as DOMRect
  }

  rows[0]!.dispatchEvent(new Event('dragstart', { bubbles: true }))

  const over = new MouseEvent('dragover', { bubbles: true, cancelable: true, clientY: 55 })

  rows[2]!.dispatchEvent(over)
  rows[0]!.dispatchEvent(new Event('dragend', { bubbles: true }))

  expect(over.defaultPrevented).toBe(true)
  expect(posted()).toEqual(['', '2', '3', '1'])
})

test('a search fetches rows, and a pick appends one, once', async () => {
  const fetched = vi.fn(async () => new Response('<li><button type="button" data-relation-pick data-value="9" data-title="Nine">Nine</button></li>'))

  vi.stubGlobal('fetch', fetched)
  vi.useFakeTimers()

  const query = document.querySelector<HTMLInputElement>('[data-relation-query]')!

  query.value = 'ni'
  query.dispatchEvent(new Event('input', { bubbles: true }))
  await vi.advanceTimersByTimeAsync(250)
  vi.useRealTimers()

  expect(String(fetched.mock.calls[0]?.[0])).toContain('/admin/article/relations/related?locale=en&q=ni')

  document.querySelector<HTMLElement>('[data-relation-pick]')!.click()

  expect(posted()).toEqual(['', '1', '2', '3', '9'])
  expect(query.value).toBe('')

  vi.unstubAllGlobals()
})

test('a drag that is not a row is left alone, its text with it', () => {
  document.body.insertAdjacentHTML('beforeend', '<textarea>hello brave world</textarea>')

  const setData = vi.fn()
  const drag = new Event('dragstart', { bubbles: true })

  Object.defineProperty(drag, 'dataTransfer', { value: { setData } })
  document.querySelector('textarea')!.dispatchEvent(drag)

  expect(setData).not.toHaveBeenCalled()
})

test('a pick already in the list is not added twice, and Enter in the search picks the first found', async () => {
  const results = document.querySelector('[data-relation-results]')!

  results.innerHTML = '<li><button type="button" data-relation-pick data-value="2" data-title="Two">Two</button></li>'
  document.querySelector<HTMLElement>('[data-relation-pick]')!.click()

  expect(posted()).toEqual(['', '1', '2', '3'])

  results.innerHTML = '<li><button type="button" data-relation-pick data-value="8" data-title="Eight">Eight</button></li>'
  ;(results as HTMLElement).dataset.for = ''
  const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })

  document.querySelector('[data-relation-query]')!.dispatchEvent(enter)

  expect(posted()).toEqual(['', '1', '2', '3', '8'])
  expect(enter.defaultPrevented).toBe(true)
})

test("a single relation's pick replaces its one row", () => {
  document.body.innerHTML = `
    <form>
      <div data-relation data-many="false">
        <input type="hidden" name="author" value="">
        <ol data-relation-rows><li data-relation-row><input type="hidden" name="author" value="4"><span data-relation-title>Four</span></li></ol>
        <template data-relation-template><li data-relation-row><input type="hidden" name="author" value=""><span data-relation-title></span></li></template>
        <ul data-relation-results><li><button type="button" data-relation-pick data-value="5" data-title="Five">Five</button></li></ul>
      </div>
    </form>`

  document.querySelector<HTMLElement>('[data-relation-pick]')!.click()

  expect(new FormData(document.querySelector('form')!).getAll('author')).toEqual(['', '5'])
  expect(document.querySelector('[data-relation-title]')!.textContent).toBe('Five')
})

test('Enter picks from the answer to what is typed now, and an older answer arriving late is not shown', async () => {
  const answers: Record<string, (html: string) => void> = {}
  const fetched = vi.fn(
    (url: URL) =>
      new Promise<Response>((resolve) => {
        answers[url.searchParams.get('q') ?? ''] = (html) => resolve(new Response(html))
      }),
  )
  const row = (value: string, title: string) => `<li><button type="button" data-relation-pick data-value="${value}" data-title="${title}">${title}</button></li>`
  const query = document.querySelector<HTMLInputElement>('[data-relation-query]')!
  const results = document.querySelector('[data-relation-results]')!

  vi.stubGlobal('fetch', fetched)
  vi.useFakeTimers()

  /* "b" is asked, and "ben" typed before its answer comes. */
  query.value = 'b'
  query.dispatchEvent(new Event('input', { bubbles: true }))
  await vi.advanceTimersByTimeAsync(250)
  query.value = 'ben'
  query.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
  vi.useRealTimers()

  answers.ben!(row('5', 'Ben'))
  await vi.waitFor(() => expect(posted()).toEqual(['', '1', '2', '3', '5']))

  answers.b!(row('4', 'Bo') + row('5', 'Ben'))
  await new Promise((resolve) => setTimeout(resolve, 0))

  expect(results.textContent).toBe('')
  expect(posted()).toEqual(['', '1', '2', '3', '5'])

  vi.unstubAllGlobals()
})

test('a pick made while a search is on its way leaves the emptied search empty', async () => {
  let answer: (html: string) => void = () => {}

  vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((resolve) => (answer = (html) => resolve(new Response(html))))))
  vi.useFakeTimers()

  const query = document.querySelector<HTMLInputElement>('[data-relation-query]')!
  const results = document.querySelector('[data-relation-results]')!

  query.value = 'ab'
  query.dispatchEvent(new Event('input', { bubbles: true }))
  await vi.advanceTimersByTimeAsync(250)
  vi.useRealTimers()

  results.innerHTML = '<li><button type="button" data-relation-pick data-value="7" data-title="Seven">Seven</button></li>'
  document.querySelector<HTMLElement>('[data-relation-pick]')!.click()
  answer('<li><button type="button" data-relation-pick data-value="8" data-title="Abc">Abc</button></li>')
  await new Promise((resolve) => setTimeout(resolve, 0))

  expect(results.textContent).toBe('')
  expect(query.value).toBe('')

  vi.unstubAllGlobals()
})

test('Enter stops a search still waiting to be asked, so its answer cannot follow the pick', async () => {
  const fetched = vi.fn(async (url: URL) => new Response(`<li><button type="button" data-relation-pick data-value="9" data-title="${url.searchParams.get('q')}">x</button></li>`))

  vi.stubGlobal('fetch', fetched)
  vi.useFakeTimers()

  const query = document.querySelector<HTMLInputElement>('[data-relation-query]')!

  query.value = 'ni'
  query.dispatchEvent(new Event('input', { bubbles: true }))
  query.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
  await vi.advanceTimersByTimeAsync(500)
  vi.useRealTimers()
  await vi.waitFor(() => expect(posted()).toEqual(['', '1', '2', '3', '9']))

  expect(fetched).toHaveBeenCalledTimes(1)

  vi.unstubAllGlobals()
})
