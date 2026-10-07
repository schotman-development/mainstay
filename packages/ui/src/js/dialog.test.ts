import { beforeEach, expect, test } from 'vitest'
import { dialogs } from './dialog'

beforeEach(() => {
  document.body.innerHTML = `
    <button data-dialog-open="confirm">Delete</button>
    <dialog id="confirm"><button data-dialog-close>Keep it</button></dialog>`

  /* jsdom has the element and not its modal behaviour. */
  const dialog = document.querySelector('dialog')!
  dialog.showModal = function () {
    this.setAttribute('open', '')
  }
  dialog.close = function () {
    this.removeAttribute('open')
  }

  dialogs()
})

test('a control naming a dialog opens it, and its close control closes it', () => {
  const dialog = document.querySelector('dialog')!

  document.querySelector<HTMLElement>('[data-dialog-open]')!.click()
  expect(dialog.open).toBe(true)

  document.querySelector<HTMLElement>('[data-dialog-close]')!.click()
  expect(dialog.open).toBe(false)
})
