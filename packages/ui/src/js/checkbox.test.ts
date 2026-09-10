import { expect, test } from 'vitest'
import { checkboxes } from './checkbox'

/* The whole reason the marker exists: indeterminate is a DOM property with no
   attribute behind it, so no server-rendered markup can set it and a
   part-selected page would show an empty box. */
test('indeterminate reaches the DOM, where markup cannot put it', () => {
  document.body.innerHTML = `
    <input type="checkbox" aria-label="Select all" data-indeterminate>
    <input type="checkbox" aria-label="Select Home">`

  checkboxes()

  const [part, plain] = [...document.querySelectorAll('input')] as HTMLInputElement[]

  expect(part!.indeterminate).toBe(true)
  expect(part!.checked).toBe(false)
  expect(plain!.indeterminate).toBe(false)
})
