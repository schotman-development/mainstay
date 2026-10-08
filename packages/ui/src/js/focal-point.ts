import { fresh } from './dom'

/*
 | An image's focal point, set by clicking where it is: the two percentages
 | written into the form's inputs, which move the marker when typed in too.
 */
export function focalPoints(root: ParentNode = document): void {
  for (const box of fresh<HTMLElement>(root, '[data-focal]')) {
    const form = box.closest('form') ?? document
    const across = form.querySelector<HTMLInputElement>('[data-focal-across]')
    const down = form.querySelector<HTMLInputElement>('[data-focal-down]')
    const marker = box.querySelector<HTMLElement>('[data-focal-marker]')

    if (!across || !down || !marker) continue

    const place = () => {
      marker.style.left = `${across.value}%`
      marker.style.top = `${down.value}%`
    }

    box.addEventListener('click', (event) => {
      const area = box.getBoundingClientRect()

      if (area.width === 0 || area.height === 0) return

      const percent = (offset: number, size: number) => String(Math.min(100, Math.max(0, Math.round((offset / size) * 100))))

      across.value = percent(event.clientX - area.left, area.width)
      down.value = percent(event.clientY - area.top, area.height)
      place()
      across.dispatchEvent(new Event('input', { bubbles: true }))
    })

    across.addEventListener('input', place)
    down.addEventListener('input', place)
  }
}
