import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'

/*
 | The ground a control sits on. A control has to contrast with whatever is
 | behind it, and the admin has two backgrounds -- the page and the panels on
 | it. A control on the page takes the panel colour and one on a panel takes the
 | page colour, which is why this cannot be baked in.
 */
const grounds = {
  canvas: 'bg-canvas',
  surface: 'bg-surface',
} as const

/*
 | Two densities, matching MenuItem's `compact`: the forms are 14px type with
 | room around it, the bar controls are the same type in a shorter box so they
 | line up with the other things on a bar.
 |
 | The placeholder weight rides along with the size rather than sitting in the
 | base, and it is not an accident: a form placeholder is a sample value and
 | should stay behind the real one, while a bar placeholder is an instruction
 | ("Search") and has to be readable at rest.
 */
const sizes = {
  /* The caller owns the padding and the type size. For a control the two
     densities do not fit -- the command centre's combobox, which has to leave
     room for an icon and a shortcut hint -- so that it can still take its
     border, its ground and its focus ring from here. */
  none: '',
  sm: 'px-2.5 py-1 text-sm placeholder:text-muted',
  md: 'px-2.5 py-1.5 text-sm placeholder:text-muted/70',
} as const

export type ControlGround = keyof typeof grounds
export type ControlSize = keyof typeof sizes

export type ControlOptions = {
  size?: ControlSize
  ground?: ControlGround
  /*
   | No border, no ground and no focus ring, for a control that sits inside an
   | InputShell. The shell draws all three for the group, and a control that
   | draws its own inside it gives you a box in a box and two focus rings.
   |
   | Implies `size: 'none'`: a control inside a shell is spaced against the
   | shell's own padding, and the two sets fighting is how the tag box ended up
   | three times taller than its chips.
   */
  bare?: boolean
  /*
   | Off for a control that sets its own width. `w-full` cannot simply be
   | overridden from className -- at equal specificity the cascade goes by
   | stylesheet order, and `.w-full` is emitted after `.w-48` -- so the base has
   | to be asked not to claim the width rather than argued out of it.
   */
  fullWidth?: boolean
}

/*
 | One class list for every text control in the admin, so a border, a ground or
 | a focus ring cannot drift between an input, a textarea and a select.
 |
 | Exported because a control this does not cover yet -- the command centre's
 | combobox, with its own room for two icons -- should still take its border and
 | its focus ring from here rather than restating them.
 */
export function controlClass(
  { size = 'md', ground = 'canvas', bare = false, fullWidth = true }: ControlOptions = {},
  className?: string,
) {
  return [
    fullWidth ? 'w-full' : '',
    bare
      ? 'bg-transparent focus:outline-none'
      : `rounded-control border border-border ${grounds[ground]} focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent`,
    bare ? '' : sizes[size],
    className,
  ]
    .filter(Boolean)
    .join(' ')
}

/* The native `size` attribute is a character count, and intersecting it with
   ours yields `never`. Shadowed rather than renamed: nothing here sizes a
   control by character width, and `size` is the name the rest of the system
   already uses (Thumbnail, Logo). */
export type InputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'size'> & ControlOptions

/*
 | Every single-line control: text, search, date, whatever `type` says. Native
 | rather than rebuilt, so a date field brings its own picker, its own keyboard
 | handling and its own locale formatting, and a search field brings its own
 | clear button.
 */
export function Input({ size, ground, bare, fullWidth, className, ...props }: InputProps) {
  return <input className={controlClass({ size, ground, bare, fullWidth }, className)} {...props} />
}

export type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & ControlOptions

/* resize-y rather than the browser default of both: widening a textarea past
   its field drags the whole form's column out of line. */
export function Textarea({ size, ground, bare, fullWidth, className, ...props }: TextareaProps) {
  return (
    <textarea
      className={controlClass({ size, ground, bare, fullWidth }, `resize-y ${className ?? ''}`.trim())}
      {...props}
    />
  )
}

export type SelectProps = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'size'> & ControlOptions

/* The native select, which is the one control where the platform version beats
   anything rebuilt: it is the only one that becomes a proper wheel on a phone. */
export function Select({ size, ground, bare, fullWidth, className, ...props }: SelectProps) {
  return <select className={controlClass({ size, ground, bare, fullWidth }, className)} {...props} />
}

export type InputShellProps = {
  children: ReactNode
  className?: string
}

/*
 | A bordered box that behaves like one control while holding several things: a
 | prefix and a field, or a row of chips and the field that adds to them.
 |
 | focus-within rather than focus is the whole point -- the ring belongs to the
 | group, so focusing the input inside lights the box the user thinks they are
 | typing in rather than a smaller box inside it.
 */
export function InputShell({ children, className }: InputShellProps) {
  return (
    <div
      className={[
        'rounded-control border border-border bg-canvas',
        'focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-accent',
        className,
      ]
        .filter(Boolean)
        .join(' ')}
    >
      {children}
    </div>
  )
}
