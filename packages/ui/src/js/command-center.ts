import { fresh, once } from './dom'
import { rankCommands, type Command } from './rankCommands'

/*
 | The one component here that is genuinely stateful: a query, a filtered list,
 | and a highlight that has to survive both the keyboard and the pointer.
 |
 | rankCommands is the React version's, unchanged -- ranking was never about
 | rendering. What changed is where a command goes: `run` was a callback, and a
 | server-rendered admin navigates, so it is an href.
 */

type Entry = Command & { href: string }

/* The list opens on focus with everything showing, so it reads as a menu before
   it is used as a search. */
type State = { query: string; open: boolean; active: number }

function commands(root: Element): Entry[] {
  const json = root.querySelector('script[type="application/json"][data-commands]')?.textContent

  try {
    return json ? (JSON.parse(json) as Entry[]) : []
  } catch {
    return []
  }
}

function draw(root: HTMLElement, state: State, all: Entry[]): void {
  const field = root.querySelector<HTMLInputElement>('[data-command-field]')!
  const list = root.querySelector<HTMLUListElement>('[data-command-list]')!
  const empty = root.querySelector<HTMLElement>('[data-command-empty]')!
  const template = root.querySelector<HTMLTemplateElement>('template[data-command-option]')!

  // rankCommands takes the shape it always did; href rides along untouched.
  const results = rankCommands(all, state.query) as Entry[]

  // The result list gets shorter as the query grows; clamp on read so no
  // keystroke can strand the highlight past the end of it.
  const index = Math.min(state.active, results.length - 1)

  list.replaceChildren()

  for (const [position, command] of results.entries()) {
    const option = template.content.firstElementChild!.cloneNode(true) as HTMLLIElement
    const group = option.querySelector<HTMLElement>('[data-command-group]')!

    option.id = `${list.id}-${command.id}`
    option.setAttribute('aria-selected', String(position === index))
    option.dataset.commandIndex = String(position)
    option.querySelector<HTMLElement>('[data-command-label]')!.textContent = command.label

    if (command.group) {
      group.textContent = command.group
      group.hidden = false
    }

    list.append(option)
  }

  list.hidden = !state.open || results.length === 0
  empty.hidden = !state.open || results.length > 0

  field.setAttribute('aria-expanded', String(state.open))

  const selected = results[index]

  if (state.open && selected) field.setAttribute('aria-activedescendant', `${list.id}-${selected.id}`)
  else field.removeAttribute('aria-activedescendant')

  if (state.open) field.setAttribute('aria-controls', list.id)
  else field.removeAttribute('aria-controls')

  // The list scrolls past ten or so commands; 'nearest' is a no-op while the
  // row is already visible, so this only moves on the keystroke that walks the
  // highlight off the edge.
  if (!list.hidden) list.children[index]?.scrollIntoView({ block: 'nearest' })
}

function attach(root: HTMLElement): void {
  const field = root.querySelector<HTMLInputElement>('[data-command-field]')
  const list = root.querySelector<HTMLUListElement>('[data-command-list]')

  if (!field || !list) return

  const all = commands(root)
  const state: State = { query: '', open: false, active: 0 }
  const render = () => draw(root, state, all)
  const results = () => rankCommands(all, state.query) as Entry[]

  const run = (command: Entry | undefined) => {
    if (!command) return

    state.query = ''
    state.open = false
    state.active = 0
    field.value = ''
    render()

    location.assign(command.href)
  }

  field.addEventListener('input', () => {
    state.query = field.value
    state.active = 0
    state.open = true
    render()
  })

  field.addEventListener('focus', () => {
    state.open = true
    render()
  })

  field.addEventListener('blur', () => {
    state.open = false
    render()
  })

  field.addEventListener('keydown', (event) => {
    const found = results()
    const index = Math.min(state.active, found.length - 1)

    if (event.key === 'ArrowDown') {
      event.preventDefault()
      // Escape closes the list without moving focus, so ArrowDown is also how a
      // still-focused input reopens it -- and that has to start at the top
      // rather than stepping off the highlight the closed list was holding.
      // The lower clamp keeps an empty result list from writing back -1.
      state.active = state.open ? Math.max(0, Math.min(index + 1, found.length - 1)) : 0
      state.open = true
      render()
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      state.active = Math.max(index - 1, 0)
      render()
    } else if (event.key === 'Enter' && state.open && found[index]) {
      event.preventDefault()
      run(found[index])
    } else if (event.key === 'Escape') {
      // First Escape closes the list, a second clears the query -- so the key
      // never jumps straight from "browsing" to "lost what I typed".
      if (state.open) state.open = false
      else {
        state.query = ''
        field.value = ''
      }

      render()
    }
  })

  // Suppressing mousedown rather than racing a click against the loss of focus.
  // Keeping focus in the input means there is no gap between the input letting
  // go and the option being clicked, and no timeout guarding it.
  list.addEventListener('mousedown', (event) => event.preventDefault())

  list.addEventListener('mousemove', (event) => {
    const option = (event.target as Element | null)?.closest<HTMLElement>('[data-command-index]')

    if (!option) return

    const position = Number(option.dataset.commandIndex)

    if (position !== state.active) {
      state.active = position
      render()
    }
  })

  list.addEventListener('click', (event) => {
    const option = (event.target as Element | null)?.closest<HTMLElement>('[data-command-index]')

    if (option) run(results()[Number(option.dataset.commandIndex)])
  })

  render()
}

export function commandCenters(root: ParentNode = document): void {
  const mac = /Mac|iPhone|iPad/.test(navigator.userAgent)

  for (const centre of fresh<HTMLElement>(root, '[data-command-center]')) {
    const shortcut = centre.querySelector('[data-command-shortcut]')

    if (mac && shortcut) shortcut.textContent = '⌘K'

    attach(centre)
  }

  once('command-center', () => {
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'k' || !(event.metaKey || event.ctrlKey)) return

    const field = document.querySelector<HTMLInputElement>('[data-command-field]')

    if (!field) return

    event.preventDefault()
    field.focus()
    field.select()
  })
  })
}
