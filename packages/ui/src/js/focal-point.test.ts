import { expect, test } from 'vitest'
import { focalPoints } from './focal-point'

test('a click writes the focal point as two percentages and moves the marker', () => {
  document.body.innerHTML = `
    <form>
      <div data-focal><img><span data-focal-marker></span></div>
      <input data-focal-across name="focal[0]" value="50">
      <input data-focal-down name="focal[1]" value="50">
    </form>`

  const box = document.querySelector<HTMLElement>('[data-focal]')!
  let heard = 0

  box.getBoundingClientRect = () => ({ left: 100, top: 10, width: 200, height: 100 }) as DOMRect
  document.querySelector('form')!.addEventListener('input', () => heard++)
  focalPoints()

  box.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX: 150, clientY: 135 }))

  expect(new FormData(document.querySelector('form')!).getAll('focal[0]')).toEqual(['25'])
  expect(new FormData(document.querySelector('form')!).getAll('focal[1]')).toEqual(['100'])
  expect(document.querySelector<HTMLElement>('[data-focal-marker]')!.style.left).toBe('25%')
  expect(heard).toBe(1)

  /* Typed, the marker follows. */
  const down = document.querySelector<HTMLInputElement>('[data-focal-down]')!

  down.value = '30'
  down.dispatchEvent(new Event('input', { bubbles: true }))
  expect(document.querySelector<HTMLElement>('[data-focal-marker]')!.style.top).toBe('30%')
})
