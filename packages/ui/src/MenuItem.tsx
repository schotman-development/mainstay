import type { AnchorHTMLAttributes } from 'react'

export type MenuItemProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
  current?: boolean
  /*
   | Bar sizing: 24px tall with 13px type, against the sidebar's 14px. A prop
   | rather than something a caller passes in className, because a size or a
   | display handed in that way is a single class competing with a single class,
   | and the winner is whichever the stylesheet emits last rather than whichever
   | the caller wrote.
   */
  compact?: boolean
  /*
   | Smaller type in the same box. Orthogonal to compact, which resizes the box
   | itself: this is for a row that is already a sub item of a sub item, where
   | the padding has to stay put so the text still lines up with the row above.
   | A prop for the same reason compact is one -- text-xs and text-sm are both
   | single classes, so a caller passing one in className loses to whichever
   | the stylesheet happens to emit last.
   */
  small?: boolean
}

/*
 | The bar-link look on its own, so a row that cannot be an anchor still wears
 | it -- a sidebar parent whose whole row is the disclosure has to be a button,
 | and a second copy of this class list is a second copy to keep in step.
 */
export function menuItemClass(
  { current = false, compact = false, small = false }: Pick<MenuItemProps, 'current' | 'compact' | 'small'>,
  className?: string,
) {
  return [
    'rounded-control transition-colors',
    compact ? 'inline-flex h-6 items-center px-2.5 text-xs' : `px-2 py-1.5 ${small ? 'text-xs' : 'text-sm'}`,
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
    current ? 'bg-canvas font-medium text-ink' : 'text-muted hover:bg-canvas hover:text-ink',
    className,
  ]
    .filter(Boolean)
    .join(' ')
}

/*
 | A top bar link. Anchors rather than buttons so middle-click, cmd-click and
 | "copy link address" keep working -- an admin panel that breaks opening a
 | section in a new tab is a worse admin panel.
 |
 | aria-current is what marks the active section for a screen reader; the styling
 | is keyed off the same prop so the two cannot drift apart.
 */
export function MenuItem({ current = false, compact = false, small = false, className, ...props }: MenuItemProps) {
  return (
    <a
      aria-current={current ? 'page' : undefined}
      className={menuItemClass({ current, compact, small }, className)}
      {...props}
    />
  )
}
