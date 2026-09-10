import { fresh } from './dom'

/*
 | The publishing rail's status control.
 |
 | A <details> is not a form control, so the value it sets lives in a hidden
 | input beside it. Choosing an option writes that, retitles the trigger, and
 | moves the chip in the bar -- which is the one piece of this screen where two
 | places have to say the same thing at once.
 */
export function entryStatus(root: ParentNode = document): void {
  for (const status of fresh<HTMLElement>(root, '[data-status]')) {
    status.addEventListener('click', (event) => {
      const option = (event.target as Element | null)?.closest<HTMLElement>('[data-status-option]')
      const chosen = option?.getAttribute('value')

      if (!option || !chosen) return

      const form = status.closest('form') ?? document

      const value = document.querySelector<HTMLInputElement>('[data-status-value]')
      const label = status.querySelector('[data-status-label]')

      if (value) {
        value.value = chosen
        // The hidden input is what the dirty check reads, and it does not fire
        // input events of its own.
        value.dispatchEvent(new Event('change', { bubbles: true }))
      }

      if (label) label.textContent = chosen

      for (const sibling of status.querySelectorAll('[data-status-option]')) {
        const current = sibling === option

        sibling.classList.toggle('font-medium', current)

        if (current) sibling.setAttribute('aria-current', 'true')
        else sibling.removeAttribute('aria-current')
      }

      /* Draft is the state worth noticing, so it is the one that reads as a
         label; published is the resting state and stays quiet. The same rule
         the chip component encodes -- restated here because this is the one
         place the chip changes without the server drawing it again. */
      const chip = (form instanceof Element ? form.ownerDocument : document).querySelector('[data-status-chip] span')

      if (chip) {
        chip.textContent = chosen
        chip.classList.toggle('text-muted', chosen === 'Published')
        chip.classList.toggle('text-ink', chosen !== 'Published')
      }
    })
  }
}
