import { useEffect, useId, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react'
import { Icon } from './Icon'
import { controlClass } from './Input'
import { rankCommands, type Command } from './rankCommands'

export type { Command }

export type CommandCenterProps = {
  commands: Command[]
  placeholder?: string
  className?: string
}

/*
 | Navigation and quick actions behind one input. The list opens on focus with
 | every command showing, so it reads as a menu before it is used as a search.
 |
 | The listbox suppresses mousedown rather than racing a click against the loss
 | of focus. Keeping focus in the input means there is no gap between the input
 | letting go and the option being clicked, and no timeout guarding it.
 */
export function CommandCenter({ commands, placeholder = 'Search or jump to…', className }: CommandCenterProps) {
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const input = useRef<HTMLInputElement>(null)
  const id = useId()

  const results = rankCommands(commands, query)
  // The result list gets shorter as the query grows; clamp on read so no
  // keystroke can strand the highlight past the end of it.
  const index = Math.min(active, results.length - 1)

  useEffect(() => {
    const focus = (event: KeyboardEvent) => {
      if (event.key !== 'k' || !(event.metaKey || event.ctrlKey)) return

      event.preventDefault()
      input.current?.focus()
      input.current?.select()
    }

    window.addEventListener('keydown', focus)

    return () => window.removeEventListener('keydown', focus)
  }, [])

  const run = (command: Command) => {
    command.run()
    setQuery('')
    setOpen(false)
    setActive(0)
  }

  const onKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setOpen(true)
      // Escape closes the list without moving focus, so ArrowDown is also how a
      // still-focused input reopens it -- and that has to start at the top
      // rather than stepping off the highlight the closed list was holding.
      // The lower clamp keeps an empty result list from writing back -1.
      setActive(open ? Math.max(0, Math.min(index + 1, results.length - 1)) : 0)
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActive(Math.max(index - 1, 0))
    } else if (event.key === 'Enter' && open && results[index]) {
      event.preventDefault()
      run(results[index])
    } else if (event.key === 'Escape') {
      // First Escape closes the list, a second clears the query -- so the key
      // never jumps straight from "browsing" to "lost what I typed".
      if (open) setOpen(false)
      else setQuery('')
    }
  }

  return (
    <div className={['relative', className].filter(Boolean).join(' ')}>
      <SearchIcon />

      <input
        ref={input}
        type="text"
        role="combobox"
        aria-expanded={open}
        aria-controls={open ? `${id}-list` : undefined}
        aria-activedescendant={open && results[index] ? `${id}-${results[index].id}` : undefined}
        aria-autocomplete="list"
        aria-label="Search or jump to"
        autoComplete="off"
        value={query}
        placeholder={placeholder}
        onChange={(event) => {
          setQuery(event.target.value)
          setActive(0)
          setOpen(true)
        }}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onKeyDown={onKeyDown}
        /* Its own room for the icon and the shortcut hint, but the border, the
           ground and the focus ring come from the same place as every other
           control's. */
        className={controlClass({ size: 'none' }, 'h-6.5 pl-8 pr-12 text-xs text-ink placeholder:text-muted')}
      />

      {/*
        | Sized rather than padded: a fixed 18px box inside the 26px field leaves
        | 4px of air either side whatever the type does.
        |
        | Centred by matching the line height to that box rather than by flex.
        | align-items centres the line box, and at line-height 1 the box is 11px
        | while the glyphs need about 13 -- so they hang out of it, unevenly,
        | and land low. Giving the line box the box's own height lets the browser
        | split the leading either side of the glyphs, which is what centres
        | them -- so the two have to move together.
        */}
      <kbd className="pointer-events-none absolute right-1.5 top-1/2 h-4.5 -translate-y-1/2 rounded-control border border-border px-1.5 text-center font-sans text-[0.6875rem] leading-4.5 text-muted">
        {shortcut()}
      </kbd>

      {open && results.length === 0 && (
        <p className={`${panel} px-3 py-2 text-sm text-muted`}>No matches</p>
      )}

      {open && results.length > 0 && (
        <ul
          id={`${id}-list`}
          role="listbox"
          onMouseDown={(event) => event.preventDefault()}
          className={`${panel} max-h-80 overflow-y-auto py-1`}
        >
          {results.map((command, position) => (
            <li
              key={command.id}
              id={`${id}-${command.id}`}
              // The list scrolls past ten or so commands; 'nearest' is a no-op
              // while the row is already visible, so this only fires on the
              // keystroke that walks the highlight off the edge.
              ref={(node) => {
                if (position === index) node?.scrollIntoView({ block: 'nearest' })
              }}
              role="option"
              aria-selected={position === index}
              onMouseMove={() => setActive(position)}
              onClick={() => run(command)}
              className={[
                'flex cursor-pointer items-center justify-between gap-4 px-3 py-1.5 text-sm',
                position === index ? 'bg-accent text-accent-ink' : 'text-ink',
              ].join(' ')}
            >
              <span className="truncate">{command.label}</span>
              {command.group && (
                // Not dimmed on the active row: the foreground at 80% over the
                // highlight is 3.76:1, under AA, and this is the row a user reads.
                <span className={position === index ? 'text-xs' : 'text-xs text-muted'}>
                  {command.group}
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

const panel =
  'absolute z-20 mt-1 w-full rounded-control border border-border bg-canvas shadow-lg'

// Cosmetic only -- the handler accepts either modifier regardless of what this
// renders, so a wrong guess costs a misleading badge, not a broken shortcut.
const shortcut = () =>
  typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘K' : 'Ctrl K'

function SearchIcon() {
  return (
    <Icon className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted">
      <circle cx="7" cy="7" r="4.5" />
      <path d="m10.5 10.5 4 4" />
    </Icon>
  )
}
