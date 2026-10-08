import { expect, test } from 'vitest'
import { mediaDrops } from './media-drop'

test('a file dropped on the grid opens the upload with it', () => {
  document.body.innerHTML = `
    <div data-media-drop="upload"></div>
    <dialog id="upload"><input type="file" name="file"></dialog>`

  const dialog = document.querySelector('dialog')!
  const input = document.querySelector<HTMLInputElement>('input[type="file"]')!
  const files = [new File(['x'], 'pier.png', { type: 'image/png' })] as unknown as FileList
  const drop = new Event('drop', { bubbles: true, cancelable: true })

  dialog.showModal = function () {
    this.setAttribute('open', '')
  }
  Object.defineProperty(input, 'files', { writable: true, value: null })
  Object.defineProperty(drop, 'dataTransfer', { value: { files, types: ['Files'] } })
  mediaDrops()

  /* Over the grid, a file is let in: a browser drops nothing where
     dragover was not cancelled. */
  const over = new Event('dragover', { bubbles: true, cancelable: true })

  Object.defineProperty(over, 'dataTransfer', { value: { types: ['Files'] } })
  document.querySelector('[data-media-drop]')!.dispatchEvent(over)
  expect(over.defaultPrevented).toBe(true)

  document.querySelector('[data-media-drop]')!.dispatchEvent(drop)

  expect(drop.defaultPrevented).toBe(true)
  expect(input.files).toBe(files)
  expect(dialog.open).toBe(true)
})
