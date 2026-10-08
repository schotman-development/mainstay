import { once } from './dom'

/*
 | A relation's chosen entries as rows, posted in the rows' order, each by its
 | one hidden input. Reordered by dragging -- the platform's `draggable` and an
 | insertBefore -- or by a row's Up and Down, removed by its Remove, and added
 | from a search the admin answers with rows as HTML. A single relation's pick
 | replaces its one row. Every change dispatches `input`, which the
 | unsaved-changes warning hears.
 */

let dragged: HTMLElement | null = null
const pending = new WeakMap<Element, number>()
const asked = new WeakMap<Element, number>()

const changed = (element: Element) => element.dispatchEvent(new Event('input', { bubbles: true }))

function add(field: HTMLElement, pick: HTMLElement): void {
  const rows = field.querySelector('[data-relation-rows]')
  const template = field.querySelector<HTMLTemplateElement>('template[data-relation-template]')
  const row = template?.content.firstElementChild?.cloneNode(true) as HTMLElement | undefined
  const value = pick.dataset.value ?? ''

  if (!rows || !row) return

  /* One already in the list is not added twice, which the save would
     refuse. */
  if ([...rows.querySelectorAll<HTMLInputElement>('input[type="hidden"]')].some((input) => input.value === value)) return

  row.querySelector<HTMLInputElement>('input[type="hidden"]')!.value = value
  row.querySelector('[data-relation-title]')!.textContent = pick.dataset.title ?? ''

  const type = row.querySelector('[data-relation-type]')

  if (type) type.textContent = pick.dataset.type ?? ''

  if (field.dataset.many === 'true') rows.append(row)
  else rows.replaceChildren(row)

  const query = field.querySelector<HTMLInputElement>('[data-relation-query]')

  if (query) query.value = ''

  const results = field.querySelector<HTMLElement>('[data-relation-results]')

  /* A search still on its way answers a query the pick has cleared. */
  asked.set(field, (asked.get(field) ?? 0) + 1)
  results?.replaceChildren()
  delete results?.dataset.for
  changed(rows)
}

/*
 | The rows the admin finds for `query`, put under the search and marked as
 | its answer. Only the newest search's answer is put there, so a slow
 | answer to an older one cannot replace it. True once it is there.
 */
async function search(field: HTMLElement, query: string): Promise<boolean> {
  const url = new URL(field.dataset.search ?? '', window.location.href)
  const turn = (asked.get(field) ?? 0) + 1

  asked.set(field, turn)
  url.searchParams.set('q', query)

  const response = await fetch(url, { headers: { Accept: 'text/html' } })
  const html = response.ok ? await response.text() : null
  const results = field.querySelector<HTMLElement>('[data-relation-results]')

  if (asked.get(field) !== turn || html === null || !results) return false

  results.innerHTML = html
  results.dataset.for = query

  return true
}

export function relationFields(): void {
  once('relation-fields', () => {
    document.addEventListener('click', (event) => {
      const target = event.target as Element | null
      const field = target?.closest<HTMLElement>('[data-relation]')
      const row = target?.closest<HTMLElement>('[data-relation-row]')
      const rows = row?.parentElement

      if (!field) return

      if (row && rows && target?.closest('[data-relation-up]')) {
        if (row.previousElementSibling) rows.insertBefore(row, row.previousElementSibling)
        changed(rows)
      } else if (row && rows && target?.closest('[data-relation-down]')) {
        if (row.nextElementSibling) rows.insertBefore(row.nextElementSibling, row)
        changed(rows)
      } else if (row && rows && target?.closest('[data-relation-remove]')) {
        row.remove()
        changed(rows)
      } else if (target?.closest('[data-relation-pick]')) {
        add(field, target.closest<HTMLElement>('[data-relation-pick]')!)
      }
    })

    document.addEventListener('input', (event) => {
      const query = (event.target as Element | null)?.closest<HTMLInputElement>('[data-relation-query]')
      const field = query?.closest<HTMLElement>('[data-relation]')

      if (!query || !field) return

      window.clearTimeout(pending.get(field))
      pending.set(field, window.setTimeout(() => void search(field, query.value.trim()), 200))
    })

    /* Enter in the search would submit the entry's form: it picks the first
       entry found for what is typed now instead, searching first when what
       is under the search answers something older. */
    document.addEventListener('keydown', (event) => {
      const query = (event.target as Element | null)?.closest<HTMLInputElement>('[data-relation-query]')
      const field = query?.closest<HTMLElement>('[data-relation]')

      if (!query || !field || event.key !== 'Enter') return

      event.preventDefault()
      window.clearTimeout(pending.get(field))

      const typed = query.value.trim()
      const first = () => {
        const pick = field.querySelector<HTMLElement>('[data-relation-pick]')

        if (pick) add(field, pick)
      }

      if (field.querySelector<HTMLElement>('[data-relation-results]')?.dataset.for === typed) first()
      else void search(field, typed).then((answered) => answered && first())
    })

    /* Only a row's own drag is this module's: text dragged in a field
       carries its text, which setting the data here would blank. */
    document.addEventListener('dragstart', (event) => {
      dragged = (event.target as Element | null)?.closest?.<HTMLElement>('[data-relation-row]') ?? null

      if (dragged) event.dataTransfer?.setData('text/plain', '')
    })

    document.addEventListener('dragover', (event) => {
      const over = (event.target as Element | null)?.closest?.<HTMLElement>('[data-relation-row]')

      if (!dragged || !over || over === dragged || over.parentElement !== dragged.parentElement) return

      event.preventDefault()

      const box = over.getBoundingClientRect()

      over.parentElement!.insertBefore(dragged, event.clientY > box.top + box.height / 2 ? over.nextElementSibling : over)
    })

    document.addEventListener('drop', (event) => {
      if (dragged) event.preventDefault()
    })

    document.addEventListener('dragend', () => {
      if (dragged) changed(dragged)
      dragged = null
    })
  })
}
