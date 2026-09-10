/*
 | The sidebar's disclosure. One listener on the document rather than one per
 | row: every toggle in the panel looks the same to this, and a nested
 | navigation would otherwise wire up a handler per branch.
 |
 | The server renders the route's own answer -- you are inside this section, so
 | it is open. This is only the reader's override on top of that.
 */

import { fresh, once } from './dom'

type Fold = { route: boolean; open: boolean }

/*
 | Recording which side of the route the override was made on keeps the answer
 | from leaking across the boundary: folding Collections away while standing in
 | it says nothing about how it should look from outside, where the route's own
 | answer applies again. Within a side of that line the choice sticks for the
 | session -- fold it away and walk deeper and it stays folded, walk out and
 | back and it is as you left it.
 |
 | sessionStorage rather than a variable, because every navigation in the admin
 | is a page load: an override held in memory would not survive the click that
 | follows it.
 */
const key = 'mainstay:sidebar'

function folds(): Record<string, Fold> {
  try {
    return JSON.parse(sessionStorage.getItem(key) ?? '{}') as Record<string, Fold>
  } catch {
    return {}
  }
}

function remember(panel: string, fold: Fold): void {
  try {
    sessionStorage.setItem(key, JSON.stringify({ ...folds(), [panel]: fold }))
  } catch {
    /* Private browsing, or a storage quota. The fold still works for the page
       it was made on; it just stops surviving the next one. */
  }
}

function apply(toggle: HTMLElement, panel: HTMLElement, open: boolean): void {
  toggle.setAttribute('aria-expanded', String(open))
  panel.dataset.open = String(open)

  /*
   | A folded row stands in for the page it is hiding. Without this, closing the
   | section you are standing in leaves the only aria-current in the tree behind
   | display:none: nothing marked on screen, and nothing for a screen reader to
   | find either.
   |
   | "true" rather than "page" because the row is not the page -- it is the
   | current item in the set, which is what is left to say once the page itself
   | is out of sight. A row that IS the page keeps saying so either way, which
   | is what data-current holds.
   */
  const own = toggle.dataset.current

  if (own) toggle.setAttribute('aria-current', own)
  else if (!open && toggle.dataset.inside === 'true') toggle.setAttribute('aria-current', 'true')
  else toggle.removeAttribute('aria-current')
}

export function sidebar(root: ParentNode = document): void {
  for (const toggle of fresh<HTMLElement>(root, '[data-sidebar-toggle]')) {
    const id = toggle.getAttribute('aria-controls')
    const panel = id ? document.getElementById(id) : null

    if (!id || !panel) continue

    // Whatever the server rendered is the route's answer, before any override.
    const route = panel.dataset.open === 'true'
    const fold = folds()[id]

    panel.dataset.route = String(route)

    if (fold && fold.route === route) apply(toggle, panel, fold.open)
  }

  once('sidebar', () => {
  document.addEventListener('click', (event) => {
    const toggle = (event.target as Element | null)?.closest<HTMLElement>('[data-sidebar-toggle]')
    const id = toggle?.getAttribute('aria-controls')
    const panel = id ? document.getElementById(id) : null

    if (!toggle || !id || !panel) return

    const open = panel.dataset.open !== 'true'

    apply(toggle, panel, open)
    remember(id, { route: panel.dataset.route === 'true', open })
  })
  })
}
