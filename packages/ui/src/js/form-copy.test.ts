import { beforeEach, expect, test, vi } from 'vitest'
import { dirtyForms } from './dirty-form'
import { formCopies } from './form-copy'

const key = 'mainstay:copy:[1,"/admin/page/5","en"]'

/* A fresh page each time: the delegated listeners stay, as on a real one. */
function open(account = 1, { saved = false, restored = false, title = 'Hello' } = {}) {
  document.body.toggleAttribute('data-saved', saved)
  document.body.innerHTML = `
    <form method="post" action="/admin/page/5" data-dirty-form data-copy='[${account},"/admin/page/5","en"]' ${restored ? 'data-restored' : ''}>
      <input type="hidden" name="_token" value="secret">
      <input name="title" value="${title}">
      <div data-copy-offer hidden>From <time data-copy-time></time> <button type="button" data-copy-restore>Restore</button><button type="button" data-copy-dismiss>Dismiss</button></div>
    </form>
    <form data-discard></form>
    <form data-sign-out></form>`
  dirtyForms()
  formCopies()

  return document.querySelector<HTMLFormElement>('form[data-copy]')!
}

const type = (value: string) => {
  const title = document.querySelector<HTMLInputElement>('[name=title]')!

  title.value = value
  title.dispatchEvent(new Event('input', { bubbles: true }))
}

const offer = () => document.querySelector<HTMLElement>('[data-copy-offer]')!
const stored = () => JSON.parse(localStorage.getItem(key) ?? 'null')

beforeEach(() => {
  localStorage.clear()
  sessionStorage.clear()
})

test('keeps what a dirty form would post, less its token, and nothing once it is clean again', () => {
  open()
  type('Changed')

  expect(stored().data).toEqual([['title', 'Changed']])
  expect(typeof stored().at).toBe('string')

  type('Hello')
  expect(stored()).toBeNull()
})

test('offers a copy that differs from what is drawn, to its own account alone', () => {
  open()
  type('Changed')

  open(2)
  expect(offer().hidden).toBe(true)

  open(1)
  expect(offer().hidden).toBe(false)
  expect(offer().querySelector('time')!.textContent).toMatch(/\d/)

  offer().querySelector<HTMLButtonElement>('[data-copy-dismiss]')!.click()
  expect(offer().hidden).toBe(true)
  expect(stored()).toBeNull()
})

test('offers no copy that is what the form already draws', () => {
  open()
  type('Changed')

  /* Saved elsewhere since: the form now draws what the copy holds. */
  open(1, { title: 'Changed' })

  expect(offer().hidden).toBe(true)
})

test('restore posts the copy to the form with intent=restore, and the form it draws starts unsaved', () => {
  open()
  type('Changed')
  open()

  const submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {})

  offer().querySelector<HTMLButtonElement>('[data-copy-restore]')!.click()

  const posted = submit.mock.contexts[0] as HTMLFormElement
  expect(posted.action).toBe(new URL('/admin/page/5', location.href).href)
  expect([...new FormData(posted)]).toEqual([['title', 'Changed'], ['_token', 'secret'], ['intent', 'restore']])
  submit.mockRestore()

  const restored = open(1, { restored: true })
  expect(restored.hasAttribute('data-dirty')).toBe(true)
  expect(document.body.hasAttribute('data-dirty')).toBe(true)
})

test('a save drops its copy only on the page the save marker is on', () => {
  const form = open()
  type('Changed')
  form.dispatchEvent(new Event('submit'))

  // Refused, or never ran: no marker.
  open()
  expect(stored()).not.toBeNull()

  // Saved somewhere else: the marker, whatever the address.
  type('Changed again')
  form.ownerDocument.querySelector('form[data-copy]')!.dispatchEvent(new Event('submit'))
  open(1, { saved: true })
  expect(stored()).toBeNull()

  // The name is read once: a later page with a marker drops nothing.
  type('Third')
  open(1, { saved: true })
  expect(stored()).not.toBeNull()
})

test('discarding the draft drops its copy, and signing out drops every one', () => {
  open()
  type('Changed')
  localStorage.setItem('mainstay:copy:[1,"/admin/page/6","en"]', '{}')
  localStorage.setItem('kept', 'yes')

  document.querySelector('form[data-discard]')!.dispatchEvent(new Event('submit', { bubbles: true }))
  expect(stored()).toBeNull()
  expect(localStorage.getItem('mainstay:copy:[1,"/admin/page/6","en"]')).toBe('{}')

  document.querySelector('form[data-sign-out]')!.dispatchEvent(new Event('submit', { bubbles: true }))
  expect(localStorage.getItem('mainstay:copy:[1,"/admin/page/6","en"]')).toBeNull()
  expect(localStorage.getItem('kept')).toBe('yes')
})
