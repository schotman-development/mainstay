import type { ReactNode } from 'react'

export type IconProps = {
  children: ReactNode
  className?: string
  /* Outlines are the house style; the few solid glyphs (the ellipsis) fill
     instead of stroking. */
  filled?: boolean
}

/*
 | The svg preamble, once. Every icon in the admin is a 16-unit box drawn in the
 | current colour, and the six copies of these attributes that used to exist
 | were six chances for one icon to be a different weight from the rest.
 |
 | Always aria-hidden: an icon here is either decorative or sits beside a label
 | that already says the thing. Anything that needs a name gets an sr-only span
 | from whatever wraps it.
 */
export function Icon({ children, className = 'size-4', filled = false }: IconProps) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 16 16"
      className={className}
      fill={filled ? 'currentColor' : 'none'}
      stroke={filled ? undefined : 'currentColor'}
      strokeWidth={filled ? undefined : 1.5}
      strokeLinecap={filled ? undefined : 'round'}
      strokeLinejoin={filled ? undefined : 'round'}
    >
      {children}
    </svg>
  )
}

/*
 | An icon that is one path, which most of them are. Saves every navigation
 | entry from spelling out an <Icon><path/></Icon> pair just to hold a `d`.
 */
export function PathIcon({ d, className }: { d: string; className?: string }) {
  return (
    <Icon className={className}>
      <path d={d} />
    </Icon>
  )
}

/*
 | The row/entry menu trigger. Every one of these looks identical, so without
 | the title a screen reader gets a list of buttons called "Actions" and no way
 | to tell which row any of them belongs to.
 */
export function MoreIcon({ title }: { title: string }) {
  return (
    <>
      <Icon filled>
        <circle cx="8" cy="3.25" r="1.25" />
        <circle cx="8" cy="8" r="1.25" />
        <circle cx="8" cy="12.75" r="1.25" />
      </Icon>
      <span className="sr-only">Actions for {title}</span>
    </>
  )
}

/* Marks the one link that leaves the admin. Without it "Visit website" reads as
   another section of the panel, and target="_blank" is a surprise. */
export function ExternalIcon() {
  return (
    <Icon className="size-3.5 opacity-70">
      <path d="M9.5 3.5H12.5V6.5" />
      <path d="M12.5 3.5 7 9" />
      <path d="M12 9.5v2a1 1 0 0 1-1 1H4.5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h2" />
    </Icon>
  )
}

/* Marks a link as going to the rendered page itself rather than to another
   admin screen. */
export function PageIcon() {
  return (
    <Icon className="size-3.5">
      <path d="M9.5 1.75H4.5a1 1 0 0 0-1 1v10.5a1 1 0 0 0 1 1h7a1 1 0 0 0 1-1V4.75z" />
      <path d="M9.5 1.75V4.75h3M6 8h4M6 10.5h2.5" />
    </Icon>
  )
}
