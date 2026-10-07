import { beforeEach, expect, test } from 'vitest'
import { dirtyForms } from './dirty-form'

beforeEach(() => {
  document.body.removeAttribute('data-dirty')
  document.body.innerHTML = `
    <span data-save-hint="Unpublished changes">Unpublished changes</span>
    <form data-dirty-form><input name="title" value="Hello"></form>`

  dirtyForms()
})

const title = () => document.querySelector<HTMLInputElement>('[name=title]')!
const hint = () => document.querySelector('[data-save-hint]')!.textContent

test('an edit marks the form and the page dirty and says so', () => {
  title().value = 'Changed'
  title().dispatchEvent(new Event('input', { bubbles: true }))

  expect(document.body.hasAttribute('data-dirty')).toBe(true)
  expect(document.querySelector('form')!.hasAttribute('data-dirty')).toBe(true)
  expect(hint()).toBe('Unsaved changes')
})

test('an edit undone is clean again, and the hint goes back to what the server said', () => {
  title().value = 'Changed'
  title().dispatchEvent(new Event('input', { bubbles: true }))
  title().value = 'Hello'
  title().dispatchEvent(new Event('input', { bubbles: true }))

  expect(document.body.hasAttribute('data-dirty')).toBe(false)
  expect(hint()).toBe('Unpublished changes')
})
