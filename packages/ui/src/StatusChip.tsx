export type EntryStatus = 'Published' | 'Draft'

export type StatusChipProps = {
  status: EntryStatus
  /*
   | The ground behind the chip, so its border still reads. A chip on a panel
   | takes the page colour and one on the page takes the panel colour; `none`
   | is for a chip on a bar that already has its own ground.
   */
  ground?: 'surface' | 'none'
}

/*
 | Whether readers can see this entry yet.
 |
 | Draft is the state worth noticing, so it is the one that reads as a label;
 | published is the resting state and stays quiet. One place, because the two
 | screens that show it were drifting apart on exactly that rule.
 */
export function StatusChip({ status, ground = 'none' }: StatusChipProps) {
  return (
    <span
      className={[
        'inline-block rounded-control border border-border px-2 py-0.5 text-xs',
        ground === 'surface' ? 'bg-surface' : '',
        status === 'Published' ? 'text-muted' : 'text-ink',
      ]
        .filter(Boolean)
        .join(' ')}
    >
      {status}
    </span>
  )
}
