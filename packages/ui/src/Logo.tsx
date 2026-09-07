export type LogoMarkProps = {
  /* Drawn beside the wordmark, which holds the geometry at its 24px form. */
  lockup?: boolean
  /* Rendered size in px. The mark's geometry changes with it -- see geometry(). */
  size?: number
  /*
   | Names the mark for assistive tech. Leave unset when the mark sits next to
   | the wordmark, as it does in Logo: the word is already real text, and a
   | labelled mark beside it makes a screen reader say "Mainstay" twice.
   */
  label?: string
  className?: string
}

const TRIANGLE = 'M60 24 22 100h76L60 24Z'

/*
 | The stay triangle, drawn on the design's 120-unit box with the construction
 | rule of stroke = 1/12 of the box.
 |
 | It sheds parts as it shrinks rather than scaling down uniformly, because the
 | counters close up otherwise: under 40px the mast is shortened to clear the
 | apex, under 32px the spreader bar drops, and under 20px the mast goes too and
 | only the triangle survives -- favicon size. The stroke thickens as parts leave
 | so the silhouette keeps its weight.
 |
 | The design gives four rungs -- 64, 32, 24, 16 -- and each threshold sits just
 | under its rung so all four reproduce exactly. The bottom one is 18 rather than
 | 24 because the design drops the mast "at 16px", not across the whole range
 | below 24: anywhere above that, in a lockup, the bare triangle reads as a stray
 | arrowhead rather than a mark.
 */
function geometry(size: number) {
  if (size < 18) return { stroke: 13, triangle: 'M60 26 22 100h76L60 26Z', mast: null, spreader: false }
  if (size < 32) return { stroke: 11, triangle: TRIANGLE, mast: 'M60 50v50', spreader: false }
  if (size < 40) return { stroke: 11, triangle: TRIANGLE, mast: 'M60 40v60', spreader: true }

  return { stroke: 10, triangle: TRIANGLE, mast: 'M60 24v76', spreader: true }
}

export function LogoMark({ size = 24, label, className, lockup = false }: LogoMarkProps) {
  /*
   | The reduction ladder exists so the mark survives alone: at favicon size the
   | counters close up and everything but the triangle has to go. Beside the
   | wordmark none of that applies -- the lockup already says who it is, and a
   | lone triangle next to it reads as a stray arrowhead rather than a mark. So
   | a lockup keeps the 24px geometry however small it is drawn.
   */
  const { stroke, triangle, mast, spreader } = geometry(lockup ? Math.max(size, 24) : size)

  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 120 120"
      fill="none"
      // currentColor, so the mark inherits whatever text colour it sits in --
      // that is the whole of its reverse/knockout treatment.
      stroke="currentColor"
      strokeWidth={stroke}
      strokeLinejoin="miter"
      role={label ? 'img' : undefined}
      aria-label={label}
      aria-hidden={label ? undefined : true}
      className={className}
    >
      {mast && <path d={mast} />}
      <path d={triangle} />
      {spreader && <path d="M36 70h48" />}
    </svg>
  )
}

export type LogoProps = {
  /* Size of the mark; the wordmark and gap are derived from it. */
  size?: number
  className?: string
}

/*
 | The horizontal lockup. Proportions come off the design's lockups -- wordmark
 | at ~0.75 of the mark, gap at ~0.32 -- kept as ratios rather than hard pixel values so
 | one size prop drives the whole thing.
 |
 | The design doc sets the wordmark in Instrument Sans; this uses the theme's own
 | font-sans (Poppins) at the same weight, case and tracking. Shipping a second
 | family for eight letters is not worth 15 KB in the admin bundle.
 */
export function Logo({ size = 24, className }: LogoProps) {
  /*
   | No colour of its own. Setting text-ink here would sit at the same
   | specificity as a caller's text-canvas and win on sheet order, which is
   | exactly the knockout case -- so the lockup inherits, like the mark.
   */
  return (
    <span
      className={['inline-flex items-center', className].filter(Boolean).join(' ')}
      style={{ gap: size * 0.32 }}
    >
      <LogoMark size={size} lockup />
      <span
        className="font-sans font-medium uppercase leading-none"
        style={{ fontSize: size * 0.75, letterSpacing: '0.02em' }}
      >
        Mainstay
      </span>
    </span>
  )
}
