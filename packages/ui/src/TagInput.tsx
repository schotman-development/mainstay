import { useState } from 'react'
import { Input, InputShell } from './Input'

export type TagInputProps = {
  id?: string
  describedBy?: string
  tags: string[]
  onChange: (tags: string[]) => void
  placeholder?: string
}

/*
 | Tags as chips with a text input after them. Enter and comma both commit,
 | because both are what people already type; Backspace on an empty input takes
 | the last one back, which is the one thing a chip list is always missing.
 |
 | Case-insensitively deduplicated, so "Editor" typed after "editor" does not
 | quietly create a second tag that filters to a different set of entries.
 */
export function TagInput({ id, describedBy, tags, onChange, placeholder = 'Add a tag' }: TagInputProps) {
  const [entered, setEntered] = useState('')

  const commit = () => {
    const value = entered.trim()

    if (value && !tags.some((tag) => tag.toLowerCase() === value.toLowerCase())) onChange([...tags, value])

    setEntered('')
  }

  return (
    <InputShell className="flex flex-wrap items-center gap-1.5 px-2 py-1.5">
      {tags.map((tag) => (
        <span
          key={tag}
          className="inline-flex items-center gap-1 rounded-control border border-border bg-surface py-0.5 pl-2 pr-1 text-xs"
        >
          {tag}
          <button
            type="button"
            onClick={() => onChange(tags.filter((other) => other !== tag))}
            className="rounded-control px-0.5 text-muted hover:text-danger focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent"
          >
            <span aria-hidden="true">&times;</span>
            {/* Every remove button is an identical glyph; without this a screen
                reader gets a row of buttons called "x". */}
            <span className="sr-only">Remove {tag}</span>
          </button>
        </span>
      ))}

      <Input
        bare
        id={id}
        aria-describedby={describedBy}
        value={entered}
        onChange={(event) => setEntered(event.target.value)}
        onKeyDown={(event) => {
          if (event.key === 'Enter' || event.key === ',') {
            /* Enter in a form submits it, and a comma would otherwise land in
               the field as the first character of the next tag. */
            event.preventDefault()
            commit()
          }

          if (event.key === 'Backspace' && entered === '' && tags.length > 0) onChange(tags.slice(0, -1))
        }}
        /* Committing on blur as well, because a tag typed and left sitting in
           the box looks added and is not -- and the save button is a click
           away, which is exactly the blur that loses it. */
        onBlur={commit}
        placeholder={tags.length === 0 ? placeholder : ''}
        fullWidth={false}
        className="min-w-24 flex-1 py-0.5 text-sm placeholder:text-muted/70"
      />
    </InputShell>
  )
}
