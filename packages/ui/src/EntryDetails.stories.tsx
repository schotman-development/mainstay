import { useState } from 'react'
import type { Decorator, Meta, StoryObj } from '@storybook/react-vite'
import { Button } from './Button'
import type { ButtonVariant } from './Button'
import { Dropdown, DropdownItem } from './Dropdown'
import { DateText } from './DateText'
import { Field, FieldGroup, FieldSection } from './Field'
import { MoreIcon, PageIcon } from './Icon'
import { Input, InputShell, Select, Textarea } from './Input'
import { StatusChip } from './StatusChip'
import { TagInput } from './TagInput'
import { Shell } from './Shell.stories'
import { Thumbnail } from './Thumbnail'
import type { Entry } from './PageList.stories'

/*
 | One entry of a collection, open for editing. The list hands us here: every
 | title in PageList links to `${path}/edit`, and this is what is on the other
 | side of that link.
 |
 | What this screen is NOT is where the writing happens. The block editor is a
 | front-end inline editor -- you edit the page on the rendered site, in place,
 | seeing the real layout. So the body never appears here, and the screen is
 | free to be what it actually is: everything true *about* an entry rather than
 | the entry itself. Slug, summary, filing, search metadata, and the workflow
 | state that decides who can see it.
 |
 | The one thing it does owe the content is a way back to it, which is what the
 | card at the top of the form is for. An entry screen with no trace of the body
 | reads as broken rather than as deliberate.
 */
export type EntryDetail = Entry & {
  excerpt: string
  tags: string[]
  seoTitle: string
  seoDescription: string
  /*
   | How much content there is, so the handoff card can say something true
   | without this screen having to load or understand the document. A count is
   | all the admin needs; the shape of it is the editor's business.
   */
  blocks: number
}

/* Stands in for a real rendered page preview, as in PageList. */
const preview =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 25'%3E%3Crect width='40' height='25' fill='%23e7e5e0'/%3E%3Crect x='4' y='4' width='22' height='3' rx='1.5' fill='%238b8880'/%3E%3Crect x='4' y='10' width='32' height='2' rx='1' fill='%23bdbab3'/%3E%3Crect x='4' y='14' width='28' height='2' rx='1' fill='%23bdbab3'/%3E%3Crect x='4' y='18' width='30' height='2' rx='1' fill='%23bdbab3'/%3E%3C/svg%3E"

/* Shared with the Shell story below, and with anything else wanting a
   populated screen rather than a blank one. */
export const draft: EntryDetail = {
  title: 'Shipping the new editor',
  path: '/blog/shipping-the-new-editor',
  status: 'Draft',
  category: 'Product',
  author: 'Ada Lovelace',
  released: null,
  modified: '2026-09-06',
  thumbnail: preview,
  excerpt: 'What changed, why it took three attempts, and what we threw away in between.',
  tags: ['editor', 'prosemirror', 'release'],
  seoTitle: '',
  seoDescription: '',
  blocks: 14,
}

/* Live, and filled in the way something that has been through review is. */
export const published: EntryDetail = {
  ...draft,
  title: 'What headless actually buys you',
  path: '/blog/what-headless-actually-buys-you',
  status: 'Published',
  category: 'Engineering',
  released: '2026-09-01',
  modified: '2026-09-02',
  tags: ['architecture', 'api'],
  seoTitle: 'What headless CMS architecture actually buys you',
  seoDescription:
    'The case for separating the editing experience from the delivery layer, and the three costs nobody mentions when they make it.',
  blocks: 22,
}

/* What "New post" opens. Nothing filled in, no content yet, and no release
   date -- an entry has none until it has been released. */
export const blank: EntryDetail = {
  title: '',
  path: '',
  status: 'Draft',
  category: 'Product',
  author: 'Ada Lovelace',
  released: null,
  modified: '2026-09-07',
  excerpt: '',
  tags: [],
  seoTitle: '',
  seoDescription: '',
  blocks: 0,
}

export type SaveState = {
  /* What the primary button says. */
  label: string
  variant: ButtonVariant
  disabled: boolean
  /* The quiet line beside the button: the screen's answer to "is my work
     safe?", read far more often than the button is pressed. */
  hint: string
}

/* Arrays are compared by contents rather than by reference, because tags are a
   fresh array on every edit and === would call every entry dirty forever. */
const same = (a: unknown, b: unknown) =>
  Array.isArray(a) && Array.isArray(b)
    ? a.length === b.length && a.every((value, index) => value === b[index])
    : a === b

/*
 | True when the form differs from what is on the server.
 |
 | Read off the entries rather than from a list of field names, so a field added
 | to EntryDetail is covered the day it is added. A hand-kept list is a list that
 | drifts, and the failure is silent in the worst possible way: an edit that
 | never registers as unsaved is an edit you lose without being warned.
 |
 | Both sides' keys, not just the saved one: optional fields exist, and one
 | being set for the first time is a key the saved entry does not have yet.
 | Reading only its keys misses the very edit that added it.
 |
 | modified is the one exclusion, and it is the server's rather than the form's:
 | it changes *as a result of* saving, so counting it would leave every entry
 | reading as dirty the instant it was saved.
 */
export function changed(saved: EntryDetail, current: EntryDetail) {
  const fields = new Set([...Object.keys(saved), ...Object.keys(current)]) as Set<keyof EntryDetail>

  return [...fields].some((field) => field !== 'modified' && !same(saved[field], current[field]))
}

/*
 | TODO(you): still the one real decision on this screen.
 |
 | Everything else here is layout. This function is the screen's behaviour:
 | given what the server holds and what the form is holding, it decides what the
 | primary button says, whether it is the accent button or the quiet one,
 | whether it is pressable at all, and what the line beside it tells you.
 |
 | It has to cover at least these, and they do not all want the same answer:
 |
 |   - a draft, untouched            nothing to do, but the screen should still
 |                                   offer the way forward (publish it?)
 |   - a draft, edited               save it -- but does saving publish it?
 |   - published, untouched          resting state; the button has no work
 |   - published, edited             editing something readers can already see
 |                                   is the case worth being loudest about
 |   - blank (no title)              cannot be saved yet, and should say why
 |
 | The trade-offs worth weighing:
 |
 |   One button or two? WordPress splits "Save draft" from "Publish" and makes
 |   you learn which is which; Notion has neither and saves as you type. One
 |   button whose label changes is the middle road, at the cost of a target that
 |   means something different depending on when you look at it.
 |
 |   Should an untouched screen's button be disabled or just quiet? A disabled
 |   control is honest about having nothing to do, but it also cannot be
 |   focused, so a keyboard user tabbing the header finds a hole.
 |
 |   How loud is "unsaved"? An edit to a published entry is a live document
 |   drifting from what readers see. Reaching for `variant: 'danger'` there is
 |   defensible -- so is deciding that red belongs to destruction alone.
 |
 |   And one this screen adds: content and metadata now save separately, since
 |   the body is edited on the site. Does this button speak for the whole entry
 |   or only for the form? Saying "Saved" while the inline editor still holds
 |   unsaved blocks would be a lie the user has no way to catch.
 |
 | changed(saved, current) above answers "is it dirty". This answers what to do
 | about it. The stub below gives every case the same answer so the story still
 | renders; replace it.
 */
export function saveState(saved: EntryDetail, current: EntryDetail): SaveState {
  return {
    label: 'Save',
    variant: 'primary',
    disabled: false,
    hint: changed(saved, current) ? 'Unsaved changes' : '',
  }
}

/* Google truncates around here. Not a limit -- going over costs you the tail of
   a snippet, not the entry -- so the counter warns and never blocks. */
const seoDescriptionLimit = 160

export function EntryDetails({
  entry,
  collection = 'Posts',
  /* Where the site is served from, for the slug field's prefix and the link
     out to the inline editor. */
  site = 'example.test',
}: {
  entry: EntryDetail
  /* The collection this entry belongs to, for the breadcrumb. */
  collection?: string
  site?: string
}) {
  /* What the server holds, and what the form is holding. Keeping both is what
     lets the bar answer "is my work safe?" at all -- a single piece of state
     can only say what the entry is, never whether it has moved. */
  const [saved, setSaved] = useState(entry)
  const [current, setCurrent] = useState(entry)

  const set = <Key extends keyof EntryDetail>(field: Key, value: EntryDetail[Key]) =>
    setCurrent((entry) => ({ ...entry, [field]: value }))

  const state = saveState(saved, current)
  const dirty = changed(saved, current)

  const listing = `/admin/collections/${collection.toLowerCase()}`

  return (
    <Shell
      current={`${listing}/42/edit`}
      /* The last crumb tracks the field rather than the saved entry: renaming
         should be visible in the trail before you commit to it. No href on it,
         which is what marks it as the page you are already on. */
      breadcrumb={[
        { label: 'Collections', href: '/admin/collections' },
        { label: collection, href: listing },
        { label: current.title || 'Untitled' },
      ]}
      actions={
        <>
          {/* aria-live so the answer to "is my work safe?" is announced rather
              than only shown. The region is always mounted; an element that
              appears at the same moment its text does is not announced. */}
          <span aria-live="polite" className="text-xs text-muted">
            {state.hint}
          </span>

          <StatusChip status={current.status} />

          <Dropdown
            align="end"
            chevron={false}
            label={<MoreIcon title={current.title || 'this entry'} />}
            triggerClassName="rounded-control p-1.5 text-muted hover:bg-surface hover:text-ink"
          >
            {current.status === 'Published' && (
              <DropdownItem onClick={() => console.log('view', current.path)}>View on site</DropdownItem>
            )}
            <DropdownItem onClick={() => console.log('duplicate', current.path)}>Duplicate</DropdownItem>
            <DropdownItem onClick={() => console.log('revisions', current.path)}>Revisions</DropdownItem>
            <DropdownItem onClick={() => setCurrent(saved)} disabled={!dirty}>
              Discard changes
            </DropdownItem>
            <DropdownItem onClick={() => console.log('trash', current.path)} danger>
              Move to trash
            </DropdownItem>
          </Dropdown>

          <Button variant={state.variant} disabled={state.disabled} onClick={() => setSaved(current)}>
            {state.label}
          </Button>
        </>
      }
    >
      {/* The shell hands this a box with a definite height; filling it rather
          than growing to fit is what keeps the bar and the navigation still
          while the two columns scroll under them. */}
      <div className="flex h-full min-h-0">
        {/*
          | min-h-0 on both columns. Shell.stories.tsx makes the point for the
          | page; it applies once more here, because a flex child that takes its
          | content height as a floor cannot scroll, and this row has two of
          | them. Miss it on either and the header goes off the top of the screen
          | instead of the body scrolling under it.
          */}
        <main className="min-h-0 min-w-0 flex-1 overflow-y-auto">
          <div className="mx-auto max-w-3xl space-y-6 px-6 py-8">
            <ContentCard entry={current} site={site} />

            <FieldGroup label="Details">
              <Field label="Title">
                {(id) => (
                  <Input
                    id={id}
                    value={current.title}
                    onChange={(event) => set('title', event.target.value)}
                    placeholder="Untitled"
                  />
                )}
              </Field>

              {/*
                | The prefix is shown rather than described, because a slug field
                | on its own is a box you have to guess the rules of: leading
                | slash or not, whole URL or the last part. Seeing the address
                | assemble as you type answers all of it without a hint line.
                */}
              <Field label="URL" hint="Changing this breaks any link already pointing at the old address.">
                {(id, describedBy) => (
                  <InputShell className="flex items-stretch">
                    <span className="flex select-none items-center border-r border-border px-2.5 font-mono text-xs text-muted">
                      {site}
                    </span>
                    <Input
                      bare
                      id={id}
                      aria-describedby={describedBy}
                      value={current.path}
                      onChange={(event) => set('path', event.target.value)}
                      placeholder="/blog/an-entry"
                      className="min-w-0 rounded-r-control px-2.5 py-1.5 font-mono text-xs"
                    />
                  </InputShell>
                )}
              </Field>

              <Field label="Summary" hint="Shown wherever this entry is listed, quoted or shared.">
                {(id, describedBy) => (
                  <Textarea
                    id={id}
                    aria-describedby={describedBy}
                    rows={3}
                    value={current.excerpt}
                    onChange={(event) => set('excerpt', event.target.value)}
                  />
                )}
              </Field>
            </FieldGroup>

            <FieldGroup label="Organisation">
              <Field label="Category">
                {(id) => (
                  <Select
                    id={id}
                    value={current.category}
                    onChange={(event) => set('category', event.target.value)}
                  >
                    {['Product', 'Engineering', 'Changelog', 'Company'].map((category) => (
                      <option key={category}>{category}</option>
                    ))}
                  </Select>
                )}
              </Field>

              <Field label="Tags">
                {(id, describedBy) => (
                  <TagInput
                    id={id}
                    describedBy={describedBy}
                    tags={current.tags}
                    onChange={(tags) => set('tags', tags)}
                  />
                )}
              </Field>

              <Field label="Featured image">
                {() => <Featured entry={current} onChange={(value) => set('thumbnail', value)} />}
              </Field>
            </FieldGroup>

            {/*
              | Its own group rather than fields mixed into Details, because
              | these are written for a machine and read by a stranger. Left
              | blank they fall back to the title and summary above, which is
              | why the placeholders show what would be used instead.
              */}
            <FieldGroup label="Search">
              <Field label="Meta title">
                {(id) => (
                  <Input
                    id={id}
                    value={current.seoTitle}
                    onChange={(event) => set('seoTitle', event.target.value)}
                    placeholder={current.title || 'Falls back to the title'}
                  />
                )}
              </Field>

              <Field
                label="Meta description"
                counter={{ length: current.seoDescription.length, limit: seoDescriptionLimit }}
              >
                {(id, describedBy) => (
                  <Textarea
                    id={id}
                    aria-describedby={describedBy}
                    rows={3}
                    value={current.seoDescription}
                    onChange={(event) => set('seoDescription', event.target.value)}
                    placeholder={current.excerpt || 'Falls back to the summary'}
                  />
                )}
              </Field>
            </FieldGroup>
          </div>
        </main>

        {/*
          | The rail holds what is deliberately *not* metadata: whether readers
          | can see this, from when, and whose name is on it. Workflow rather
          | than content about the content, which is why it survives the form
          | being the main column now.
          */}
        <aside
          aria-label="Publishing"
          className="min-h-0 w-72 shrink-0 overflow-y-auto border-l border-border bg-surface"
        >
          <FieldSection label="Status">
            {/* The heading above is visual grouping and labels nothing, so the
                trigger carries the field name itself -- otherwise the rail is a
                button called "Draft" with no way to tell what it sets. The same
                shape PageList uses for its own status filter. */}
            <Dropdown
              label={
                <>
                  <span className="sr-only">Status: </span>
                  {current.status}
                </>
              }
              triggerClassName="w-full justify-between rounded-control border border-border bg-canvas px-2.5 py-1.5 text-sm hover:bg-surface"
            >
              {(['Draft', 'Published'] as const).map((status) => (
                <DropdownItem
                  key={status}
                  onClick={() => set('status', status)}
                  aria-current={current.status === status ? 'true' : undefined}
                  className={current.status === status ? 'font-medium' : undefined}
                >
                  {status}
                </DropdownItem>
              ))}
            </Dropdown>
          </FieldSection>

          {/* Native, so it brings its own picker, its own keyboard handling and
              its own locale formatting for free. An empty string is how the
              control spells null. */}
          <Field label="Released" rail>
            {(id) => (
              <Input
                id={id}
                type="date"
                value={current.released ?? ''}
                onChange={(event) => set('released', event.target.value || null)}
              />
            )}
          </Field>

          {/* Not a control: who wrote it is not yours to set from here. */}
          <FieldSection label="Author">
            <p className="text-sm">{current.author}</p>
            <p className="pt-1 text-xs text-muted">
              Last saved <DateText iso={saved.modified} />
            </p>
          </FieldSection>
        </aside>
      </div>
    </Shell>
  )
}

/*
 | The handoff. Editing happens on the rendered site, in place, so this screen's
 | only job regarding the content is to prove it exists and get you to it.
 |
 | Given the preview rather than a button alone: a thumbnail is the fastest way
 | to confirm you are about to edit the right page, and it is the only thing on
 | this screen that shows what a reader would actually see.
 */
function ContentCard({ entry, site }: { entry: EntryDetail; site: string }) {
  const empty = entry.blocks === 0

  return (
    <section
      aria-label="Content"
      className="flex items-center gap-4 rounded-control border border-border bg-surface p-4"
    >
      {/* undefined rather than the thumbnail when there are no blocks: a
          preview of a page with nothing on it is a preview of nothing. */}
      <Thumbnail size="lg" dashed src={empty ? undefined : entry.thumbnail} fallback="Empty" />

      <div className="min-w-0 flex-1">
        <h2 className="text-sm font-medium">Content</h2>
        <p className="pt-0.5 text-xs text-muted">
          {empty
            ? 'Nothing written yet. The editor opens on the page itself.'
            : `${entry.blocks} blocks. Edited on the page, not here.`}
        </p>
      </div>

      {/*
        | Leaves the admin, so it is an anchor and says so. Not target="_blank":
        | the inline editor replaces this screen for as long as you are writing,
        | and coming back to a stale form in the tab behind you is how you save
        | over your own metadata.
        */}
      <a
        href={`https://${site}${entry.path}?edit=1`}
        className="inline-flex shrink-0 items-center gap-2 rounded-control bg-accent px-3 py-1.5 text-sm font-medium text-accent-ink transition-opacity hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
      >
        {empty ? 'Start writing' : 'Edit content'}
        <PageIcon />
      </a>
    </section>
  )
}

/* The image, or the reason there is not one. Both states are the same size, so
   setting one does not shift the fields below. */
function Featured({
  entry,
  onChange,
}: {
  entry: EntryDetail
  onChange: (value: string | undefined) => void
}) {
  return (
    <div className="flex items-center gap-3">
      <Thumbnail size="md" dashed src={entry.thumbnail} fallback="None" />

      <div className="flex gap-2">
        <Button variant="secondary" onClick={() => onChange(preview)}>
          {entry.thumbnail ? 'Replace' : 'Choose'}
        </Button>

        {entry.thumbnail && (
          <Button variant="secondary" onClick={() => onChange(undefined)}>
            Remove
          </Button>
        )}
      </div>
    </div>
  )
}

const meta = {
  title: 'Entry details',
  component: EntryDetails,
  args: { entry: draft },
  parameters: { layout: 'fullscreen', controls: { disable: true } },
  excludeStories: ['EntryDetails', 'changed', 'saveState', 'draft', 'published', 'blank'],
  /*
   | The screen brings the shell with it -- it has to, because the Save button's
   | label and variant come from state only it holds, and a decorator cannot
   | reach into that to fill the bar's actions slot. So the decorator only has
   | to give the whole thing a viewport to sit in.
   */
  decorators: [(Story) => <div className="-m-6 h-dvh"><Story /></div>] as Decorator[],
} satisfies Meta<typeof EntryDetails>

export default meta

/* Nobody has read it yet, so nothing on this screen is dangerous. */
export const DraftEntry: StoryObj<typeof meta> = {}

/* The case the save state has to be loudest about: readers can already see
   this, and the form is now holding something they cannot. Also the only story
   with the search fields filled in, which is what having been through review
   looks like. */
export const PublishedEntry: StoryObj<typeof meta> = {
  args: { entry: published },
}

/*
 | What "New post" opens on. Worth its own story because it is where the
 | handoff has to work hardest: there is no content, no preview to recognise the
 | page by, and the card has to read as an invitation rather than as a hole.
 */
export const NewEntry: StoryObj<typeof meta> = {
  args: { entry: blank },
}
