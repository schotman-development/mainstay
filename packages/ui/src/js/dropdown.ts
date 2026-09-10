/*
 | The three things <details> has no native answer for: dismiss on outside
 | click, dismiss on Escape, dismiss on focus leaving.
 |
 | Delegated at the document, so one set of listeners covers every dropdown on
 | the page -- and any that arrive later. React needed an effect per instance
 | for exactly this, which is the part that does not survive the port.
 */

import { once } from './dom'

const OPEN = 'details[data-dropdown][open]'

/*
 | Closing puts the panel behind display:none, so anything focused inside it
 | would drop focus to the document body -- a keyboard user would land back at
 | the top of the page after choosing an item.
 */
function close(dropdown: HTMLDetailsElement): void {
  if (!dropdown.open) return

  const held = dropdown.contains(document.activeElement)

  dropdown.open = false

  if (held) dropdown.querySelector('summary')?.focus()
}

export function dropdowns(): void {
  once('dropdown', () => {
  document.addEventListener('pointerdown', (event) => {
    for (const dropdown of document.querySelectorAll<HTMLDetailsElement>(OPEN)) {
      // Focus has not moved yet, so there is nothing to put back.
      if (!dropdown.contains(event.target as Node)) dropdown.open = false
    }
  })

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return

    for (const dropdown of document.querySelectorAll<HTMLDetailsElement>(OPEN)) close(dropdown)
  })

  /*
   | pointerdown covers dismissing with a mouse; this covers dismissing with the
   | keyboard. Without it, tabbing out of an open menu leaves the panel
   | overlaying the page while focus is somewhere else entirely. A null
   | relatedTarget means focus left the document, which also counts as gone.
   */
  document.addEventListener('focusout', (event) => {
    const dropdown = (event.target as Element | null)?.closest<HTMLDetailsElement>('details[data-dropdown]')

    if (dropdown?.open && !dropdown.contains(event.relatedTarget as Node | null)) dropdown.open = false
  })

  /*
   | Activating a control inside the panel dismisses it. It asks what was hit
   | rather than closing on any click at all, which would tear the menu away
   | from someone dragging to select the email address in the user menu's
   | header -- and it ignores the summary, whose own click is the toggle.
   */
  document.addEventListener('click', (event) => {
    const target = event.target as Element | null
    const dropdown = target?.closest<HTMLDetailsElement>('details[data-dropdown]')

    if (!dropdown?.open || target?.closest('summary')) return

    if (target?.closest('a, button')) close(dropdown)
  })
  })
}
