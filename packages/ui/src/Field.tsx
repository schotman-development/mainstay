import { useId } from 'react'
import type { ReactNode } from 'react'

export type FieldGroupProps = { label: string; children: ReactNode }

/*
 | A card of related fields. The heading is a real <h2> rather than a styled
 | div, so a form has an outline a screen reader can jump through instead of one
 | long undifferentiated list of inputs.
 */
export function FieldGroup({ label, children }: FieldGroupProps) {
  return (
    <section className="rounded-control border border-border bg-surface">
      <h2 className="border-b border-border px-4 py-2.5 text-xs font-medium text-muted">{label}</h2>
      {/* The last row's rule would double with the card's own bottom edge. */}
      <div className="[&>*:last-child]:border-b-0">{children}</div>
    </section>
  )
}

export type FieldProps = {
  label: string
  hint?: string
  /* Length against a soft limit, shown beside the label where it is read before
     you type rather than discovered after. */
  counter?: { length: number; limit: number }
  /* The rail is narrower and its rows are tighter than a form's. */
  rail?: boolean
  /* Handed both ids rather than rendering the control itself, so a field can
     hold an input, a textarea, a select or a composite without this knowing
     which. */
  children: (id: string, describedBy: string | undefined) => ReactNode
}

/*
 | One control and its label. useId rather than a caller-supplied string,
 | because two of these on a screen with the same hand-written id give a screen
 | reader one control called "Tags" and one that is unlabelled.
 */
export function Field({ label, hint, counter, rail = false, children }: FieldProps) {
  const id = useId()
  const note = useId()

  const over = counter ? counter.length > counter.limit : false

  return (
    <div className={`border-b border-border ${rail ? 'px-4 py-3' : 'px-4 py-3.5'}`}>
      <div className="flex items-baseline justify-between gap-2 pb-1.5">
        <label htmlFor={id} className="text-xs font-medium text-muted">
          {label}
        </label>

        {counter && (
          /* Over the limit is information, not an error: the snippet gets
             truncated, nothing breaks. So it colours and never blocks. */
          <span className={`text-xs tabular-nums ${over ? 'text-danger' : 'text-muted'}`}>
            {counter.length}/{counter.limit}
          </span>
        )}
      </div>

      {children(id, hint ? note : undefined)}

      {/* Tied to the control with aria-describedby, not just sitting under it:
          a hint a screen reader never reaches is a hint only some people get. */}
      {hint && (
        <p id={note} className="pt-1.5 text-xs text-muted">
          {hint}
        </p>
      )}
    </div>
  )
}

export type FieldSectionProps = { label: string; children: ReactNode }

/*
 | A labelled block for a control that cannot take an htmlFor: a Dropdown's
 | trigger is a <summary> generated inside the component, with no id to point a
 | label at. The heading is visual grouping only, so the control inside carries
 | its own accessible name. Anything with a real form control should use Field
 | instead and get a genuine <label>.
 */
export function FieldSection({ label, children }: FieldSectionProps) {
  return (
    <div className="border-b border-border px-4 py-3">
      <h2 className="pb-1.5 text-xs font-medium text-muted">{label}</h2>
      {children}
    </div>
  )
}
