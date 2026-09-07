import { useId, useState, type ReactNode } from 'react'
import { MenuItem, menuItemClass } from './MenuItem'
import { PathIcon } from './Icon'

export type SidebarItem = {
  href: string
  label: string
  /* Rendered at 16px by convention; the wrapper hides it from assistive tech. */
  icon?: ReactNode
  /*
   | Sub items. What a parent does with them while it is shut is the caller's
   | call, because the two readings are different: a group of sections is
   | something you open and leave open, whereas a collection's own verbs are
   | something you want beside the row without it pushing the rest of the
   | navigation down the panel every time you go looking.
   */
  items?: SidebarItem[]
  /*
   | How the sub items are reached while shut. "toggle" makes the whole row the
   | disclosure -- the row is a button, not a link, and its own href is only
   | ever used to work out whether the section is the one being looked at.
   | "flyout" leaves the row a link and hangs the list beside it on hover or
   | focus. Either way, walking into the section opens the list in the
   | navigation, which is what withdraws a flyout once you are inside it.
   */
  submenu?: 'toggle' | 'flyout'
}

export type SidebarSection = {
  label?: string
  items: SidebarItem[]
}

export type SidebarProps = {
  sections: SidebarSection[]
  /* The pathname of the page being viewed, e.g. location.pathname. */
  current?: string
  className?: string
}

/*
 | Query strings and hashes are not part of the route. Without stripping them a
 | caller who hands over location.href instead of location.pathname fails both
 | tests below, and the match falls through to a shallower item -- a wrong
 | section marked, which reads worse than none.
 |
 | Repeated slashes are collapsed and trailing ones dropped, on both sides, so an
 | href and a pathname that disagree about them still match -- a server will
 | serve /admin//pages quite happily and location.pathname keeps it verbatim.
 | Everything collapses to "/" rather than "", which would otherwise
 | prefix-match every path in existence.
 |
 | Stripping the hash means an href that routes through one (/admin#/pages) is
 | not supported; every item would flatten to /admin and tie.
 */
function normalise(path: string): string {
  return path.replace(/[?#].*$/, '').replace(/\/{2,}/g, '/').replace(/\/$/, '') || '/'
}

/*
 | Every item in the tree, parents before their own children. Matching has to
 | see the whole depth: a sub item is by construction a longer path than the
 | parent it hangs off, so leaving it out does not merely fail to mark it, it
 | hands the mark to the parent instead.
 */
function flatten(items: SidebarItem[]): SidebarItem[] {
  return items.flatMap((item) => [item, ...flatten(item.items ?? [])])
}

/*
 | Which item the pathname belongs to.
 |
 | Prefix matching, so /admin/pages/42 still marks Pages -- an exact test would
 | leave every detail page with nothing marked at all. Longest wins, because a
 | dashboard mounted at /admin is a prefix of every other section and would
 | otherwise claim all of them. A root item is the one exception: "/" prefixes
 | the whole site, so it only ever matches itself.
 |
 | Returns the item rather than its href so that two items pointing at the same
 | route -- a pinned shortcut alongside its section, or a collection beside its
 | own "view posts" -- mark one link, not both.
 */
export function activeItem(sections: SidebarSection[], current?: string): SidebarItem | undefined {
  if (!current) return undefined

  const path = normalise(current)

  return flatten(sections.flatMap((section) => section.items))
    .map((item) => ({ item, href: normalise(item.href) }))
    .filter(({ href }) => path === href || (href !== '/' && path.startsWith(`${href}/`)))
    .sort((a, b) => b.href.length - a.href.length)[0]?.item
}

/*
 | One row of the navigation, and its sub items if it has any.
 |
 | A toggle's row is a button and a flyout's row is a link, because the two do
 | different things when clicked and only one of them goes anywhere. What they
 | share is the look, which lives in menuItemClass rather than in a copy here.
 |
 | Open, shut and flown out are the same <ul> wearing different classes, rather
 | than a list per state behind a ternary: one set of links, one id for
 | aria-controls, and no way for the renderings to drift apart.
 |
 | The flyout sits flush against the row rather than a few pixels off it: a gap
 | belongs to neither element, so crossing it slowly drops :hover, and
 | visibility flips with no transition to wait behind -- the panel goes, and
 | the pointer is over nothing that can bring it back.
 |
 | A flyout's hover and focus are handled in CSS against the direct child list,
 | so a collection sitting inside an open Collections opens its own panel
 | without its parent's hover dragging every sibling open too. Hiding with
 | visibility rather than display is what makes the keyboard work: the links are
 | out of the tab order until the parent link takes focus, and focus-within then
 | reveals them.
 */
function Item({ item, active, depth = 0 }: { item: SidebarItem; active?: SidebarItem; depth?: number }) {
  const panel = useId()
  const [folded, setFolded] = useState<{ route: boolean; open: boolean }>()
  const children = item.items ?? []

  const link = (
    <MenuItem
      href={item.href}
      current={item === active}
      small={depth >= 2}
      className="flex flex-1 items-center gap-2"
    >
      {item.icon && (
        <span className="flex shrink-0" aria-hidden="true">
          {item.icon}
        </span>
      )}
      {item.label}
    </MenuItem>
  )

  if (children.length === 0) return <li>{link}</li>

  /*
   | The route decides whether the section is open, and the reader overrides it.
   | Recording which route the override was made against keeps the answer from
   | leaking across the boundary: folding Collections away while standing in it
   | says nothing about how it should look from outside, where the route's own
   | answer applies again. Within a side of that line the choice sticks for the
   | session -- fold it away and walk deeper and it stays folded, walk out and
   | back and it is as you left it.
   |
   | The alternative was pinning it open while you are inside and refusing to
   | close, which was defensible while the disclosure was a chevron off to one
   | side. It is not defensible when the disclosure is the whole row: a row
   | that does nothing when clicked is worse than one that closes on you.
   */
  const flyout = item.submenu === 'flyout'
  const inside = !!active && flatten([item]).includes(active)
  const expanded = flyout ? inside : folded?.route === inside ? folded.open : inside

  /*
   | A folded row stands in for the page it is hiding. Without this, closing
   | the section you are standing in leaves the only aria-current in the tree
   | behind display:none: nothing marked on screen, and nothing for a screen
   | reader to find either. "true" rather than "page" because the row is not
   | the page -- it is the current item in the set, which is what is left to
   | say once the page itself is out of sight.
   */
  const mark = item === active ? 'page' : inside && !expanded ? 'true' : undefined

  /*
   | The second level steps in on its own; the third takes a shorter step and a
   | rule down its left, which is what stops a collection's verbs reading as
   | more collections. Shorter because the smaller type is already doing some
   | of that work -- a full step again would staircase the panel away to the
   | right for no more legibility than this buys.
   */
  const list = expanded
    ? depth === 0
      ? 'mt-1 ml-3.5 flex flex-col gap-1 pl-2'
      : 'mt-1 ml-2 flex flex-col gap-1 border-l border-border pl-2'
    : flyout
      ? 'invisible absolute left-full top-0 z-20 flex min-w-44 flex-col gap-1 rounded-control border border-border bg-canvas p-1 shadow-lg'
      : 'hidden'

  return (
    <li
      className={['relative', flyout && '[&:focus-within>ul]:visible [&:hover>ul]:visible']
        .filter(Boolean)
        .join(' ')}
    >
      {flyout ? (
        link
      ) : (
        <button
          type="button"
          aria-current={mark}
          aria-expanded={expanded}
          aria-controls={panel}
          onClick={() => setFolded({ route: inside, open: !expanded })}
          className={menuItemClass(
            { current: !!mark, small: depth >= 2 },
            'flex w-full items-center gap-2',
          )}
        >
          {item.icon && (
            <span className="flex shrink-0" aria-hidden="true">
              {item.icon}
            </span>
          )}
          {item.label}

          <PathIcon
            d="m6 4 4 4-4 4"
            className={['ml-auto size-3.5 shrink-0 opacity-70 transition-transform', expanded && 'rotate-90']
              .filter(Boolean)
              .join(' ')}
          />
        </button>
      )}

      {/* A shut toggle keeps its list in the document rather than dropping it,
          so the button's aria-controls points at something a screen reader can
          go and find. */}
      <ul id={panel} className={list}>
        {children.map((child) => (
          <Item key={child.href} item={child} active={active} depth={depth + 1} />
        ))}
      </ul>
    </li>
  )
}

/*
 | The section navigation. Items are data rather than children so a caller
 | states the pathname once instead of computing `current` for every link.
 */
export function Sidebar({ sections, current, className }: SidebarProps) {
  const id = useId()
  const active = activeItem(sections, current)

  return (
    <nav
      aria-label="Sections"
      /*
       | ponytail: no scroll of its own, because a flyout hung at left-full is
       | outside the padding box and a scroll container clips it -- overflow-y
       | auto forces overflow-x to auto with it, so the panel would be cut off
       | at the divider. The navigation scrolls with the page, as WordPress's
       | does. If it ever grows past the viewport, position the flyout fixed
       | from the row's bounding rect and put the scroll back.
       */
      className={[
        'flex w-48 shrink-0 flex-col gap-5 border-r border-border bg-surface px-2 py-3',
        className,
      ]
        .filter(Boolean)
        .join(' ')}
    >
      {sections.map((section, position) => {
        const heading = `${id}-${position}`

        return (
          <div key={position}>
            {section.label && (
              <h2
                id={heading}
                className="px-2 pb-2 text-xs font-medium uppercase tracking-wider text-muted"
              >
                {section.label}
              </h2>
            )}

            {/* aria-labelledby is what ties the group name to its items; without
                it a screen reader announces four unlabelled lists in a row. */}
            <ul
              aria-labelledby={section.label ? heading : undefined}
              className="flex flex-col gap-1"
            >
              {section.items.map((item) => (
                <Item key={item.href} item={item} active={active} />
              ))}
            </ul>
          </div>
        )
      })}
    </nav>
  )
}
