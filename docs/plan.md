# Plan

How `packages/cms` gets built, in the order the decisions in `decisions.md` allow. Each phase ends
at something that works and can be looked at, not at a layer that is finished.

The package today is a skeleton: a service provider, a config file, a catch-all admin view and an
API route that reports its own version. `packages/ui` is ahead of it — Blade components, a theme,
and a behaviour layer in `src/js` — and `packages/editor` is a built ProseMirror island. Nothing
stores content yet.

## What is already settled and does not get re-opened

Structure is PHP classes read by reflection. Content lives in per-type tables with real columns for
scalars and one JSON column for blocks. There is one query layer and HTTP is a transport over it.
The admin renders in Blade. Client state is vanilla TypeScript. Everything is soft-deleted, scoped
to a site, and localizable per field.

## Corrections to carry into the work

Three things in `decisions.md` are stale or contradicted by a later entry. They are recorded here
rather than edited into the log, which is append-only by construction.

- **Schema sync never has to plan child tables.** Its consequences say "repeaters and blocks become
  child tables with ordering columns and foreign keys, and that is where the complexity arrives."
  The later blocks decision puts blocks in a JSON column and says a repeater is a block with one
  type. So the diff stays scalar-only, permanently, and phase 2 is much smaller than that
  consequence implies. Localization is what adds a second table per type, not blocks.
- **`config/mainstay.php` mounts the API with no guard.** The API decision names this a
  placeholder. It stays wrong until phase 11; nothing should be built on the assumption that an API
  route is reachable.
- **`Navigation::sections()` lists a "Content types" screen.** Types are classes and there is no UI
  for authoring them. That item can only ever be a read-only inspector, and the navigation is
  generated from the registry in phase 5 regardless.

## Phase 1 — Declarations

Field attributes and the registry that reads them. `Entry`, `GlobalSet` and taxonomy base classes.
The field type contract: what column it wants (or that it lives in JSON), its validation rules, its
JSON Schema fragment, its cast in both directions, and the Blade component that draws it.

Types are registered by the host in a service provider — `Mainstay::types([Article::class, ...])` —
which is the same mechanism a host already uses to add a field type and an onboarding step. One
extension point rather than three. The cost is that the set only exists once the application has
booted, so the sync and drift commands in phase 2 are artisan commands rather than anything that
could read the filesystem alone. They were always going to be artisan commands.

Only the field types the phases before 7 need: text, textarea, number, boolean, date, select. Rich
text and blocks arrive in phase 7, media in 8, relation and terms in 10, each registering through
the same contract a host would use — because a contract only exercised by third parties is a
contract nobody has tested.

**The contract is provisional until phase 8.** Six scalar types agree with each other too easily to
prove anything. The types that will actually shape it are the ones that store in JSON, need a
sibling row, or draw an interface with state in it, and they are deliberately not here. Expect to
reshape it when blocks and media land, and do not build a public extension API on it before then.

Reflection turns a class into a field list. Everything downstream reads that list and never the
class.

No database. The check is a fixture content type reflected into the expected field list and JSON
Schema.

## Phase 2 — Schema

The field list becomes a column plan: a main table, a `_locales` sibling for localized fields,
`site_id` and `deleted_at` on both. Those columns exist now, in the first migration, because
retrofitting either is a migration of every table in the system.

`mainstay:sync` alters the database to match, refuses to run in production, and sits behind a config
flag that is off. `mainstay:schema:check` compares live to declared and exits non-zero, which is
what CI runs.

Mainstay's own tables arrive with the phase that needs them, as ordinary package migrations. Here
that is `sites`, seeded with one row, and the URI lookup keyed on `(site_id, locale, uri)`.

The check is a declared type synced into sqlite, then a property renamed and the drift check failing.

## Phase 3 — Identity

Mainstay's own users table, guard, session cookie and login view. A setup route that creates the
first account and is reachable only while the user table is empty — checked when it renders *and*
when it submits.

Authorization lands with it, because it cannot be retrofitted onto a query layer that was written
without it. Capabilities are strings derived from each type's declared capability type and never
stored. `mainstay_roles` holds a name and a JSON array of them. `Gate::before` resolves primitives
from the role plus per-user grants and denials; one policy per shape does the ownership mapping.

The check is a capability set derived from a fixture type, and a policy test for
`edit_others_pages` against an entry someone else owns.

## Phase 4 — The query layer

`(type, where, sort, depth, locale, site)`, executing against the database with no HTTP hop.
Models resolve their table from the type, cast through the field types, and carry the site scope and
`SoftDeletes` from the first query written rather than the hundredth.

Access control is on by default and opted out of explicitly. `#[Private]` is honoured here, not in a
controller, or the local caller and the HTTP caller diverge — which is the whole reason this layer
exists. `depth` resolves relations defensively and skips what it cannot load.

The check is one call returning identical shapes at depth 0 and depth 1, and a private field absent
without an override and present with one.

## Phase 5 — The admin, for scalar fields

Navigation generated from the registry. A list screen per type on top of `EntryList::shape()`, which
already exists and is tested. A form built from the field list, one Blade component per field type,
drawn with the components in `packages/ui`.

Save validates from the declared rules, writes the row, and writes the URI lookup by interpolating
the route pattern. A slug colliding with a URI another entry holds is a validation error on the
field, not a silent suffix.

Blocks, media and rich text are not in this phase. Everything else about editing is.

The check is a round trip: create through the admin, read back through the query layer.

## Phase 6 — Public rendering

`#[Route]` and `#[Template]`. One catch-all registered last, matching a path against every declared
pattern through the lookup table, falling through to an ordinary 404. Template resolution cascades:
per-entry override, then the type's declaration, then convention from the type name.

Host templates call the query layer directly. They do not touch Eloquent and they do not make HTTP
requests to their own server.

The host is a real site in its own repository, consuming `mainstay/cms` through a path repository,
not the testbench skeleton. Real requirements drive the design — which is what the design-phase
decision assumes when it says the `@foreach` a designer wrote is where the content model comes
from. The consequence is that nothing end-to-end runs in this repository's CI, so the phases here
stay covered by their own checks and the site is where the package is found to be wrong.

Preview is a signed URL behind Mainstay's guard. Until phase 9 there are no drafts, so it renders
the published row; the seam is here and the draft read is added there.

**This is the milestone that matters.** Declare a type, edit an entry, visit a URL. Everything after
it makes that loop richer rather than making it exist.

## Phase 7 — Rich text and blocks

The JSON column holding an ordered array of `{ id, type, data }`. The editor island wired into the
form for rich text fields, storing ProseMirror document JSON.

A PHP walker turning that document into HTML at render time. The node schema is closed and an
unknown node renders as nothing.

The block list is built by moving DOM nodes, not re-rendering a list. `insertBefore` on a live node
is a move, so typed-in state, focus and any editor instance inside a block survive a reorder. Adding
clones a `<template>`; removing is `.remove()`; reordering is the platform's `draggable`. Serializing
walks `[data-block]` in DOM order into a hidden input inside the form, so `FormData` sees it and
`dirty-form.ts` covers blocks with no change at all.

Block Blade templates on the site are the other half, and they are the only part of a design
conversion that is not a rename.

The check is a document fixture rendered to expected HTML, and a reorder that preserves an untouched
sibling's value.

## Phase 8 — Media

Upload, then derivatives generated immediately and written as plain files. Originals kept
permanently. Derivative filenames are the original's content hash plus the size and crop parameters,
so regenerating writes new files and no cache needs invalidating.

Sizes are declared in code beside the field. Crops and focal points are data on the media record, so
reprocessing honours them. `mainstay:media:reprocess` runs on the queue.

The picker and the browser are admin screens on the behaviour layer.

## Phase 9 — Drafts, publishing, revisions, trash

At most one draft row per entry, in a parallel table. The entry row always holds published content,
so the site's read stays a single-row select with no version resolution on it.

Publishing is one transaction: the draft overwrites the entry row, the outgoing content is appended
to revisions, the draft is deleted. That transaction is also the single place where "content
changed" is known, which is where the deferred publishing question will attach.

Revisions are one shared table — type, id, JSON snapshot, author, timestamp, and a hash of the
declared field set. Restore drops unknown fields, fills missing ones with defaults, tells the editor
what changed, and writes a draft rather than republishing.

Trash is `SoftDeletes`, but the URI lookup row is really deleted so the path stops resolving in the
same request. Restore rewrites it from the route pattern and takes the next free URI if it is gone,
saying which one it took.

## Phase 10 — Globals and taxonomies

Globals reuse the field attributes, the draft row and the revisions table, and differ only in having
no route, no slug, and one row per site.

Taxonomies are the deliberate exception to the relations decision: a term page exists to run the
reverse query, so the pivot is real. One polymorphic `(term_id, entry_type, entry_id)` indexed both
ways. Terms are flat, take `#[Template]`, and route through the same lookup table.

## Phase 11 — The API as a product

The guard goes on. `#[PublicRead]` opts a type into public reads, which never touch the draft table,
at every depth. Tokens are issued by Mainstay and stored hashed beside its own users table.

The JSON Schema derived in phase 1 is served from a discovery endpoint and written out as a `.d.ts`,
from the same source rather than generated twice.

The payload has to satisfy that schema, and for a timestamp field it is the one place where it does
not come from `Field::serialize()`. That method writes what the column takes -- `2026-09-10
06:30:00` -- and the schema says `format: date-time`, which is RFC 3339 and wants the `T` and the
offset. Phase 1 settled that both are UTC, so the conversion is a formatting choice here and not a
zone question, but it is a choice this phase has to make rather than inherit.

## Phase 12 — The second locale and the second site

Nothing new in the schema — phase 2 put the columns there. This is where they are proven by
configuring a second of each: the locale switcher, per-locale publish state, fallback config, and
the request host matched to a site. With one of each configured, none of it renders.

## Phase 13 — Onboarding

`spatie/laravel-onboard`, required by this phase and not before it. Two lists: an installation
checklist on a plain `Installation` class, and a per-user list. Steps are declared in the service
provider and every one is a `completeIf` closure, so nothing is stored and nothing can drift.
`excludeIf` takes the capability check, so a step an editor cannot perform is hidden rather than
shown and refused.

Settings the checklist collects are globals, so it gets no storage of its own.

## Not in this plan

Generated migrations. A content type builder UI. A derived relations table. Nested terms. Diff-based
revisions. A cached rendered-HTML column. A bulk export endpoint. Redirects when a routed field
changes. Each is available later and each is recorded in `decisions.md` with the reason it is not
needed yet.

How rendered pages reach the public is still deferred, and phase 9's publish transaction is the hook
it will attach to.

## Decided while writing this

- **Types are registered, not discovered.** A directory scan and a config array were the
  alternatives. Registration in a service provider wins on there being one extension mechanism for
  types, field types and onboarding steps rather than three.
- **Phase 1 ships six scalar field types, not eleven.** Declaring the awkward ones early would only
  freeze a contract before anything had tested it.
- **Phases 6 and 9 keep this order.** Rendering first buys the end-to-end loop sooner and leaves
  preview showing published content for three phases. Drafts first would make preview honest
  immediately and delay the first visible page. The loop is the more useful thing to have early.
