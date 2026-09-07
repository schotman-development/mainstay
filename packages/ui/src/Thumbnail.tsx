import type { ReactNode } from 'react'

/*
 | The three sizes the admin actually uses: a table row, a form field, and the
 | content card. A union rather than a className, so a fourth size has to be
 | added here and named -- which is the only thing stopping a design system from
 | quietly growing one size per call site.
 */
const sizes = {
  sm: 'h-8 w-12',
  md: 'h-14 w-20',
  lg: 'h-16 w-24',
} as const

export type ThumbnailSize = keyof typeof sizes

export type ThumbnailProps = {
  src?: string
  size?: ThumbnailSize
  /*
   | What the box holds when there is no image. A word where the point is that
   | nothing has been chosen yet, an initial where the point is telling this row
   | apart from the one above it.
   */
  fallback: ReactNode
  /*
   | Dashed reads as a slot waiting to be filled; solid reads as a stand-in for
   | the thing itself. A list beside real previews wants solid -- a dashed tile
   | in a column of solid ones looks like the column failed to load rather than
   | like the page has no preview.
   */
  dashed?: boolean
}

/*
 | A rendered preview, or a same-sized box when there is not one. Both states
 | are the same size on purpose: a thumbnail that appears when you choose an
 | image, in a space that was not already holding it, shifts everything below.
 |
 | Always decorative. Every call site has the title next to it, and an image
 | described twice is an image announced twice.
 */
export function Thumbnail({ src, size = 'sm', fallback, dashed = false }: ThumbnailProps) {
  /* shrink-0 unconditionally: two of the three sit in a flex row, and a
     thumbnail squeezed narrower than its own aspect ratio is worse than one
     that pushes the text. */
  const box = `${sizes[size]} shrink-0 rounded-control border border-border`

  return src ? (
    <img src={src} alt="" className={`${box} object-cover`} />
  ) : (
    <div
      aria-hidden="true"
      className={`${box} grid place-content-center text-xs text-muted ${
        /* font-medium is what makes a lone initial read as a glyph rather than
           as a stray letter. A word does not need it, and setting it there just
           makes "Empty" louder than the thing it is standing in for. */
        dashed ? 'border-dashed' : 'bg-canvas font-medium'
      }`}
    >
      {fallback}
    </div>
  )
}
