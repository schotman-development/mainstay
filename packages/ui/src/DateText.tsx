/*
 | Built once rather than per cell: constructing a formatter is the expensive
 | part, and a list of a few hundred rows would otherwise build one per date.
 |
 | timeZone is pinned because a date-only ISO string parses as UTC midnight, and
 | rendering that in any negative offset lands on the day before -- an entry
 | released on the 3rd showing as the 2nd to every reader west of Greenwich.
 | This is the single most repeatable way to get dates wrong here, which is why
 | there is one formatter rather than one per screen.
 */
const formatter = new Intl.DateTimeFormat('en-GB', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
  timeZone: 'UTC',
})

export function formatDate(iso: string): string {
  return formatter.format(new Date(iso))
}

export type DateTextProps = {
  /* null is a date that has not happened: nothing has a release date until it
     has been released. */
  iso: string | null
  /* What to say when there is no date, for a screen reader. The dash is for
     everyone else. */
  empty?: string
}

/*
 | A date, or the absence of one.
 |
 | <time dateTime> so the machine-readable value survives the formatting. An em
 | dash reads as "nothing here" to a sighted reader and as noise to a screen
 | reader, so only one of them is given it.
 */
export function DateText({ iso, empty = 'Not set' }: DateTextProps) {
  if (!iso) {
    return (
      <>
        <span aria-hidden="true">&mdash;</span>
        <span className="sr-only">{empty}</span>
      </>
    )
  }

  return <time dateTime={iso}>{formatDate(iso)}</time>
}
