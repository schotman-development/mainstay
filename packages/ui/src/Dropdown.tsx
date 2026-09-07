import { useEffect, useRef, type ButtonHTMLAttributes, type ReactNode } from 'react'
import { PathIcon } from './Icon'

export type DropdownProps = {
  label: ReactNode
  children: ReactNode
  /* Which edge of the trigger the panel hangs from. */
  align?: 'start' | 'end'
  /* An icon-only trigger carries its own affordance; a chevron beside it reads
     as a second one. */
  chevron?: boolean
  className?: string
  triggerClassName?: string
}

/*
 | A disclosure, not a role="menu". The ARIA menu pattern takes over the arrow
 | keys and drops its items out of the tab order, which is right for an
 | application menu bar and wrong for a short list of ordinary controls in a
 | header. <details> gives the open/closed state, the button semantics on
 | <summary> and the keyboard toggle for free; the handlers below add the three
 | things it has no native answer for -- dismiss on outside click, dismiss on
 | Escape, dismiss on focus leaving.
 */
export function Dropdown({
  label,
  children,
  align = 'start',
  chevron = true,
  className,
  triggerClassName,
}: DropdownProps) {
  const details = useRef<HTMLDetailsElement>(null)

  const close = () => {
    const element = details.current

    if (!element?.open) return

    // Closing puts the panel behind display:none, so anything focused inside it
    // would drop focus to the document body -- a keyboard user would land back
    // at the top of the page after choosing an item.
    const held = element.contains(document.activeElement)

    element.open = false

    if (held) element.querySelector('summary')?.focus()
  }

  useEffect(() => {
    const dismiss = (event: PointerEvent) => {
      const element = details.current

      if (element?.open && !element.contains(event.target as Node)) element.open = false
    }

    const escape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') close()
    }

    document.addEventListener('pointerdown', dismiss)
    document.addEventListener('keydown', escape)

    return () => {
      document.removeEventListener('pointerdown', dismiss)
      document.removeEventListener('keydown', escape)
    }
  }, [])

  return (
    <details
      ref={details}
      /*
       | pointerdown covers dismissing with a mouse; this covers dismissing with
       | the keyboard. Without it, tabbing out of an open menu leaves the panel
       | overlaying the page while focus is somewhere else entirely. React's
       | onBlur is focusout, so it fires for descendants too; a null
       | relatedTarget means focus left the document, which also counts as gone.
       */
      onBlur={(event) => {
        const element = details.current

        if (element?.open && !element.contains(event.relatedTarget)) element.open = false
      }}
      className={['relative', className].filter(Boolean).join(' ')}
    >
      <summary
        className={[
          'flex cursor-pointer list-none items-center gap-1.5 rounded-control',
          'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
          '[&::-webkit-details-marker]:hidden',
          triggerClassName ?? 'px-2.5 py-1.5 text-sm text-muted hover:bg-canvas hover:text-ink',
        ].join(' ')}
      >
        {label}
        {chevron && (
          <PathIcon d="m4 6 4 4 4-4" className="size-3.5 opacity-70" />
        )}
      </summary>

      {/*
        | Activating a control inside the panel dismisses it, which is why no
        | consumer has to wrap its own handlers to close the menu. It listens
        | here rather than on the items because Enter on a focused item
        | dispatches a bubbling click, so this one handler covers mouse and
        | keyboard alike -- but it has to ask what was hit: closing on any click
        | at all would tear the menu away from someone dragging to select the
        | email address in UserMenu's header.
        */}
      <div
        onClick={(event) => {
          if ((event.target as Element).closest('a, button')) close()
        }}
        className={[
          'absolute z-20 mt-1 min-w-48 rounded-control border border-border bg-canvas py-1 shadow-lg',
          align === 'end' ? 'right-0' : 'left-0',
        ].join(' ')}
      >
        {children}
      </div>
    </details>
  )
}

export type DropdownItemProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  danger?: boolean
}

/*
 | danger is a prop rather than something a caller passes in className, because
 | a text-danger handed in that way loses: it and the text-ink below are both
 | single classes, so the stylesheet order decides and text-ink is emitted last.
 | The destructive item rendered in ordinary ink.
 */
export function DropdownItem({ danger = false, className, ...props }: DropdownItemProps) {
  return (
    <button
      type="button"
      className={[
        'block w-full px-3 py-1.5 text-left text-sm hover:bg-surface',
        danger ? 'text-danger' : 'text-ink',
        'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent',
        className,
      ]
        .filter(Boolean)
        .join(' ')}
      {...props}
    />
  )
}
