import { beforeEach, expect, test } from 'vitest'
import { dropdowns } from './dropdown'

function menu() {
  document.body.innerHTML = `
    <details data-dropdown open>
      <summary>New</summary>
      <div>
        <p id="header">ada@mainstay.test</p>
        <button type="button" id="item">Page</button>
      </div>
    </details>
    <button type="button" id="outside">Elsewhere</button>`

  dropdowns()

  return document.querySelector('details')!
}

beforeEach(() => (document.body.innerHTML = ''))

test('a pointer down outside dismisses it', () => {
  const details = menu()

  document.getElementById('outside')!.dispatchEvent(new Event('pointerdown', { bubbles: true }))

  expect(details.open).toBe(false)
})

test('a pointer down inside leaves it alone', () => {
  const details = menu()

  document.getElementById('item')!.dispatchEvent(new Event('pointerdown', { bubbles: true }))

  expect(details.open).toBe(true)
})

test('Escape dismisses it and puts focus back on the trigger', () => {
  const details = menu()
  const item = document.getElementById('item')!

  item.focus()
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))

  expect(details.open).toBe(false)
  expect(document.activeElement).toBe(details.querySelector('summary'))
})

/* Enter on a focused item dispatches a bubbling click, so one rule covers mouse
   and keyboard alike. */
test('activating an item dismisses it', () => {
  const details = menu()

  document.getElementById('item')!.click()

  expect(details.open).toBe(false)
})

/* Closing on any click at all would tear the menu away from someone dragging to
   select the email address in the user menu's header. */
test('clicking something that is not a control leaves it open', () => {
  const details = menu()

  document.getElementById('header')!.click()

  expect(details.open).toBe(true)
})

/* The summary's own click is the native toggle; treating it as an activation as
   well would close the menu in the same tick it opened. Started shut, because
   that is the direction the bug would show up in. */
test('the trigger opens rather than being treated as an item', () => {
  const details = menu()

  details.open = false
  details.querySelector('summary')!.click()

  expect(details.open).toBe(true)
})

test('tabbing out of an open menu dismisses it', () => {
  const details = menu()

  details.querySelector('#item')!.dispatchEvent(
    new FocusEvent('focusout', { bubbles: true, relatedTarget: document.getElementById('outside') }),
  )

  expect(details.open).toBe(false)
})
