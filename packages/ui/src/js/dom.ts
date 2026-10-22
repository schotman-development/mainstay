/*
 | Two things every behaviour here needs, now that they run in two places: the
 | admin, where the document is loaded once, and Storybook, where the canvas is
 | replaced on every story change.
 */

const armed = new Set<string>()

/* A delegated listener belongs to the document and wants attaching once for the
   life of the page, however many times the markup under it is replaced. */
export function once(name: string, attach: () => void): void {
  if (armed.has(name)) return

  armed.add(name)
  attach()
}

const seen = new Map<string, WeakSet<Element>>()

/*
 | The elements of this kind that have not been wired yet. Asked of the element
 | rather than recorded in the markup, so nothing about mounting twice can leak
 | into what a component renders -- and so an element that is thrown away takes
 | its entry with it. By selector, since one element can carry two behaviours:
 | the entry form is both dirty-checked and copied.
 */
export function fresh<E extends Element>(root: ParentNode, selector: string): E[] {
  const wired = seen.get(selector) ?? seen.set(selector, new WeakSet()).get(selector)!

  return [...root.querySelectorAll<E>(selector)].filter((element) => {
    if (wired.has(element)) return false

    wired.add(element)

    return true
  })
}
