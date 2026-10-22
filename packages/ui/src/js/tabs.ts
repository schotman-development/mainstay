import { fresh } from './dom'

/*
 | Tabs, as the ARIA pattern has them: a click or the arrow keys pick a tab,
 | Home and End the first and the last, and the tab picked is the one in the
 | tab order and the one whose panel shows. The bar is drawn hidden and every
 | panel open, which is the page without JavaScript; this shows the bar and
 | the picked panel alone. Each pick is a `tabchange` on the bar, naming the
 | panel, which a panel's own behaviour listens for.
 |
 | The bar's `data-tabs-open` names a panel to open on whatever was picked
 | before -- the one a refused save's messages are in. Otherwise the tab
 | picked survives a reload and a save of the form around the bar:
 | leaving the page writes it to the tab's session storage, read once by the
 | page that comes next -- the same address for a reload, any after a submit,
 | since a save lands where it lands -- so any other opening starts on the
 | first tab.
 */

const key = 'mainstay:tab'

type Kept = { name: string; tab: string; path: string | null }

const here = () => location.pathname + location.search

function kept(): Kept | null {
  try {
    const value = sessionStorage.getItem(key)

    sessionStorage.removeItem(key)

    return value === null ? null : (JSON.parse(value) as Kept)
  } catch {
    return null
  }
}

export function tabs(root: ParentNode = document): void {
  /* A page without tabs takes the kept tab too: the page after it is
     another opening. */
  if (document.querySelector('[data-tabs]') === null) kept()

  for (const list of fresh<HTMLElement>(root, '[data-tabs]')) {
    const all = [...list.querySelectorAll<HTMLButtonElement>('[role="tab"]')]
    const name = list.dataset.tabs ?? ''
    let submitted = false

    const pick = (tab: HTMLButtonElement, focus = false) => {
      for (const each of all) {
        const on = each === tab

        each.setAttribute('aria-selected', String(on))
        each.tabIndex = on ? 0 : -1

        const panel = document.getElementById(each.getAttribute('aria-controls') ?? '')

        if (panel) panel.hidden = !on
      }

      if (focus) tab.focus()

      list.dispatchEvent(new CustomEvent('tabchange', { bubbles: true, detail: tab.getAttribute('aria-controls') }))
    }

    list.addEventListener('click', (event) => {
      const tab = (event.target as Element | null)?.closest<HTMLButtonElement>('[role="tab"]')

      if (tab && all.includes(tab)) pick(tab)
    })

    list.addEventListener('keydown', (event) => {
      const at = all.indexOf(document.activeElement as HTMLButtonElement)
      const to = { ArrowLeft: at - 1, ArrowRight: at + 1, Home: 0, End: all.length - 1 }[event.key]

      if (at === -1 || to === undefined) return

      event.preventDefault()
      pick(all[(to + all.length) % all.length]!, true)
    })

    list.closest('form')?.addEventListener('submit', () => (submitted = true))

    window.addEventListener('pagehide', () => {
      const tab = all.find((each) => each.getAttribute('aria-selected') === 'true')

      try {
        sessionStorage.setItem(key, JSON.stringify({ name, tab: tab?.getAttribute('aria-controls') ?? '', path: submitted ? null : here() } satisfies Kept))
      } catch {
        /* Private browsing, or a storage quota: the next page opens on the
           first tab. */
      }
    })

    const before = kept()
    const asked = list.dataset.tabsOpen ?? (before?.name === name && (before.path === null || before.path === here()) ? before.tab : null)

    list.hidden = false
    pick(all.find((tab) => tab.getAttribute('aria-controls') === asked) ?? all[0]!)
  }
}
