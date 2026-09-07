import type { InputHTMLAttributes } from 'react'

export type CheckboxProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> & {
  /*
   | Part-checked: some of what this box covers is selected, not all. A third
   | state the DOM has and JSX cannot reach.
   */
  indeterminate?: boolean
  /* Required rather than optional: every checkbox in the admin is a bare box in
     a table cell with no visible label beside it, so there is nothing else for a
     screen reader to read. */
  label: string
}

/*
 | A checkbox.
 |
 | indeterminate is a DOM property with no attribute behind it, so it cannot be
 | set from JSX -- it has to be written onto the node. Without it a part-selected
 | page shows an empty box, which reads as "nothing here is selected" when the
 | truth is the opposite.
 */
export function Checkbox({ indeterminate = false, label, className, ...props }: CheckboxProps) {
  return (
    <input
      type="checkbox"
      aria-label={label}
      ref={(node) => {
        if (node) node.indeterminate = indeterminate
      }}
      className={[
        'size-4 accent-accent align-middle',
        'disabled:opacity-40',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
        className,
      ]
        .filter(Boolean)
        .join(' ')}
      {...props}
    />
  )
}
