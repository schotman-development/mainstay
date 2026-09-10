import { fresh } from './dom'

/*
 | Row selection for a list screen.
 |
 | Everything else about the list -- search, filter, sort, page -- is in the
 | query string and shaped on the server. Selection is not: ticking a row is not
 | a navigation, and a page reload per checkbox is not a list screen.
 |
 | It outlives paging, which is the whole reason the header checkbox reports
 | "all the rows you can see" rather than "all the rows there are". Held in
 | sessionStorage because every page of the list is a fresh document.
 */

const key = 'mainstay:selection'

function stored(list: HTMLElement): Set<string> {
  try {
    const all = JSON.parse(sessionStorage.getItem(key) ?? '{}') as Record<string, string[]>

    return new Set(all[list.dataset.entryList || 'default'] ?? [])
  } catch {
    return new Set()
  }
}

function remember(list: HTMLElement, selected: Set<string>): void {
  try {
    const all = JSON.parse(sessionStorage.getItem(key) ?? '{}') as Record<string, string[]>

    all[list.dataset.entryList || 'default'] = [...selected]
    sessionStorage.setItem(key, JSON.stringify(all))
  } catch {
    /* Private browsing, or a quota. Selection still works on this page; it just
       stops surviving the step to the next one. */
  }
}

function boxes(list: HTMLElement): HTMLInputElement[] {
  return [...list.querySelectorAll<HTMLInputElement>('[data-select-row]')]
}

/*
 | Every path the search left, which the server rendered beside the rows. The
 | bulk bar acts on the selection, so what it counts has to be scoped the way
 | the actions are -- otherwise paging forward with three rows ticked shows
 | "Nothing selected" while the buttons would still reach all three.
 */
function matched(list: HTMLElement): string[] {
  const json = list.querySelector('script[type="application/json"][data-entry-matched]')?.textContent

  try {
    return json ? (JSON.parse(json) as string[]) : boxes(list).map((box) => box.value)
  } catch {
    return boxes(list).map((box) => box.value)
  }
}

function draw(list: HTMLElement): void {
  const rows = boxes(list)

  /* The header checkbox is about this page -- "all" has to mean every row you
     can see, or it never fills in on a paged list. The bar is about the whole
     selection. Two different questions, which is why they count differently. */
  const onPage = rows.filter((box) => box.checked).length
  const held = stored(list)
  const count = matched(list).filter((path) => held.has(path)).length

  const head = list.querySelector<HTMLInputElement>('[data-select-page]')
  const bar = list.querySelector<HTMLElement>('[data-selection-bar]')
  const live = list.querySelector<HTMLElement>('[data-selection-live]')
  const shown = list.querySelector<HTMLElement>('[data-selection-count]')

  if (head) {
    head.checked = rows.length > 0 && onPage === rows.length
    head.indeterminate = onPage > 0 && onPage < rows.length
    head.setAttribute('aria-label', head.checked ? 'Deselect all on this page' : 'Select all on this page')
  }

  if (bar) bar.hidden = count === 0
  if (shown) shown.textContent = String(count)
  if (live) live.textContent = count === 0 ? 'Nothing selected' : `${count} selected`
}

export function entryLists(root: ParentNode = document): void {
  for (const list of fresh<HTMLElement>(root, '[data-entry-list]')) {
    const selected = stored(list)

    for (const box of boxes(list)) box.checked = selected.has(box.value)

    draw(list)

    list.addEventListener('change', (event) => {
      const target = event.target as HTMLInputElement | null

      if (target?.matches('[data-select-page]')) {
        for (const box of boxes(list)) box.checked = target.checked
      } else if (!target?.matches('[data-select-row]')) {
        return
      }

      const next = stored(list)

      for (const box of boxes(list)) {
        if (box.checked) next.add(box.value)
        else next.delete(box.value)
      }

      remember(list, next)
      draw(list)
    })

    /* Clears the whole selection, not just the page: the bar is counting rows
       you cannot see, so a Clear that left them behind would be a lie. */
    list.querySelector('[data-selection-clear]')?.addEventListener('click', () => {
      for (const box of boxes(list)) box.checked = false

      remember(list, new Set())
      draw(list)
    })
  }
}
