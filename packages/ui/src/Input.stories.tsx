import { useState } from 'react'
import type { Decorator, Meta, StoryObj } from '@storybook/react-vite'
import { Field, FieldGroup } from './Field'
import { Input, InputShell, Select, Textarea } from './Input'
import { TagInput } from './TagInput'

const meta = {
  component: Input,
  args: { placeholder: 'Untitled' },
  parameters: { layout: 'centered' },
  decorators: [(Story) => <div className="w-96 font-sans text-ink"><Story /></div>] as Decorator[],
} satisfies Meta<typeof Input>

export default meta

type Story = StoryObj<typeof meta>

export const Default: Story = {}

/* The bar density, against the panel ground the filter bar sits on. */
export const Compact: Story = {
  args: { size: 'sm', ground: 'surface', type: 'search', placeholder: 'Search' },
}

/*
 | Every text control at once, which is the only way to see that they are one
 | control with three tags rather than three controls that look similar.
 */
export const EveryControl: Story = {
  parameters: { controls: { disable: true } },
  render: () => (
    <div className="space-y-2">
      <Input placeholder="An input" />
      <Textarea rows={3} placeholder="A textarea" />
      <Select defaultValue="Engineering">
        {['Product', 'Engineering', 'Changelog'].map((option) => (
          <option key={option}>{option}</option>
        ))}
      </Select>
    </div>
  ),
}

/*
 | The reason `bare` exists. The ring belongs to the shell, so focusing the
 | input lights the box the user thinks they are typing in -- not a smaller box
 | inside it. Click either one.
 */
export const Composite: Story = {
  parameters: { controls: { disable: true } },
  render: function Composite() {
    const [tags, setTags] = useState(['editor', 'release'])

    return (
      <div className="space-y-2">
        <InputShell className="flex items-stretch">
          <span className="flex select-none items-center border-r border-border px-2.5 font-mono text-xs text-muted">
            example.test
          </span>
          <Input bare defaultValue="/blog/an-entry" className="min-w-0 rounded-r-control font-mono text-xs" />
        </InputShell>

        <TagInput tags={tags} onChange={setTags} />
      </div>
    )
  },
}

/* In a form, which is what they are actually for: the label, the hint and the
   counter come from Field, and the control never knows about any of them. */
export const InAForm: Story = {
  parameters: { controls: { disable: true } },
  render: function InAForm() {
    const [description, setDescription] = useState('')

    return (
      <FieldGroup label="Search">
        <Field label="Meta title">
          {(id) => <Input id={id} placeholder="Falls back to the title" />}
        </Field>

        <Field label="Meta description" counter={{ length: description.length, limit: 160 }}>
          {(id, describedBy) => (
            <Textarea
              id={id}
              aria-describedby={describedBy}
              rows={3}
              value={description}
              onChange={(event) => setDescription(event.target.value)}
            />
          )}
        </Field>
      </FieldGroup>
    )
  },
}
