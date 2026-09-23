# Plan

How `packages/cms` gets built, in the order the decisions in `decisions.md` allow. Each phase ends
at something that works and can be looked at, not at a layer that is finished.

Phases 1 to 3 are done: content types are declared, reflected into a field list, synced into
tables that `mainstay:schema:check` holds CI to, and read and written from PHP through one query
layer. `packages/ui` is ahead of the package — Blade components, a theme, and a behaviour layer in
`src/js` — and `packages/editor` is a built ProseMirror island. Nothing renders content yet.

The order puts content working before anyone can log in to it. Up to phase 8 everything is driven
from PHP — the site's own seeders, import commands, tinker — against a real site. Identity and the
admin arrive after the model has been proven, as a surface over a layer that already works.

## What is already settled and does not get re-opened

Structure is PHP classes read by reflection. Content lives in per-type tables with real columns for
scalars and one JSON column for blocks. There is one query layer and HTTP is a transport over it.
The admin renders in Blade. Client state is vanilla TypeScript. Everything is soft-deleted, scoped
to a site, and localizable per field.

## Corrections to carry into the work

Eight things in `decisions.md` are stale or contradicted by a later entry. They are recorded here
rather than edited into the log, which is append-only by construction.

- **Schema sync never has to plan child tables.** Its consequences say "repeaters and blocks become
  child tables with ordering columns and foreign keys, and that is where the complexity arrives."
  The later blocks decision puts blocks in a JSON column and says a repeater is a block with one
  type. So the diff stays scalar-only, permanently, and phase 2 is much smaller than that
  consequence implies. Localization is what adds a second table per type, not blocks.
- **`config/mainstay.php` mounts the API with no guard.** The API decision names this a
  placeholder. It stays wrong until phase 11; nothing should be built on the assumption that an API
  route is reachable.
- **`GlobalSet` is a base class, not an attribute.** The globals entry says "the attribute is
  `#[GlobalSet]`". Phase 1 builds the three shapes as base classes a type extends, so there is no
  attribute of that name to write and nothing reads one.
- **`Navigation::sections()` lists a "Content types" screen.** Types are classes and there is no UI
  for authoring them. That item can only ever be a read-only inspector, and the navigation is
  generated from the registry in phase 10 regardless.
- **`#[Private]` is `#[Internal]`.** `private` is a reserved word, so `class Private` does not
  parse — the reason `Global` is `GlobalSet`. The API and localization entries mean `#[Internal]`
  wherever they say `#[Private]`.
- **The trash is the query layer's, not `SoftDeletes`.** The trash entry says delete goes "through
  Laravel's `SoftDeletes`". Phase 3 is the query builder over the declared class, so there is no
  Eloquent model to carry the trait. `deleted_at` and the scope on it are the layer's own, applied
  in the one query every read starts from, which is the guarantee the trait was wanted for.
- **A content type's policy is chosen, never guessed.** The authorization entry says a host writes
  a policy for a Mainstay type "the way Laravel documents". It does, with `Gate::policy()` or
  `#[UsePolicy]`, but not by naming convention: Laravel would hand a content type called `Post`
  the host's `App\Policies\PostPolicy`, written for an Eloquent model of the same name and its own
  users, and every public read would be refused. Anything the host did not choose is `EntryPolicy`.
- **An untranslated entry is absent, not a fallback.** The localization entry has fallback on by
  default. Phase 3 has none, and phase 12 adds it as an opt-in, so a live multilingual site's
  listings do not start mixing languages on the deploy that ships it.

## Phase 1 — Declarations

Field attributes and the registry that reads them. `Entry`, `GlobalSet` and taxonomy base classes.
The field type contract: what column it wants (or that it lives in JSON), its validation rules, its
JSON Schema fragment, its cast in both directions, and the Blade component that draws it.

Types are registered by the host in a service provider — `Mainstay::types([Article::class, ...])` —
which is the same mechanism a host already uses to add a field type and an onboarding step. One
extension point rather than three. The cost is that the set only exists once the application has
booted, so the sync and drift commands in phase 2 are artisan commands rather than anything that
could read the filesystem alone. They were always going to be artisan commands.

Only the field types the phases before 5 need: text, textarea, number, boolean, date, select. Rich
text and blocks arrive in phase 5, media in 6, relation and terms in 7, each registering through
the same contract a host would use — because a contract only exercised by third parties is a
contract nobody has tested.

**The contract is provisional until phase 10.** Six scalar types agree with each other too easily
to prove anything. The types that will actually shape it are the ones that store in JSON, need a
sibling row, or draw an interface with state in it, and they are deliberately not here. Expect to
reshape its storage half when blocks and media land in phases 5 and 6, and its interface half when
the admin draws them in phase 10. Do not build a public extension API on it before then.

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

## Phase 3 — Content, from code

`find`, `findById` and `paginate` for reads, taking `(type, where, sort, limit, locale)`, and
`create`, `update` and `delete` through the same layer, executing against the database with no HTTP
hop. `where` and `sort` are data rather than closures, so phase 11's transport carries the same call
a template makes. A read hydrates the declared class through the field types, and every read starts
from one query scoped to the site, to the locale's row and out of the trash. The query builder, not
Eloquent: the declared class is the model, and an Eloquent one would be a second object per row.

`locale` is here rather than in phase 12, because the real site is multilingual from the start. It
defaults to the request's locale and has to be one of `mainstay.locales`, the first of which is the
default. There is no fallback: an entry with no row in a locale is not there in it. `depth` waits
for phase 7, where relations give it something to follow, and `site` for phase 12. Both are named
arguments, so adding them breaks no caller.

Writes belong to the layer rather than to the admin, for the same reason reads do. A save validates
the entry as it will be stored from the declared rules, writes the row and its `_locales` sibling in
one transaction, and rebuilds every locale's URI lookup from the type's `#[Route]`: one pattern, or
one per locale where a segment is translated. An update in a locale with no row yet adds that
translation. A slug colliding with a URI another entry holds is a validation error on the field, not
a silent suffix, and the lookup's unique index decides it. A routed field holds what `Str::slug`
writes, since that index folds case on MySQL and SQL Server and not on the others; deriving it from
the title is a convenience for someone typing, and waits for the admin. Delete sets `deleted_at` and
really deletes the lookup rows, so the path stops resolving in the same request. The admin's form
and any write over HTTP are transports over this, and cannot validate differently from a seeder.

Every main table gains `created_at` and `updated_at`, which writes fill, and a nullable `owner_id`,
which nothing fills until phase 9. It goes on now for the reason phase 2 put `site_id` on first: the
real site starts writing rows in this phase, and a column added later is a migration of every table
it has. All three join the reserved names, with `uri`.

Access control is on by default and opted out of explicitly, from the first call written. The old
order put identity first to guarantee that, and the guarantee is about where the check sits, not
about who it asks about. Every call asks `Gate::forUser()` with Mainstay's own user, never the
default guard's — on a host with members of its own, that would be a site visitor answering
Mainstay's policies. Until phase 9 there is no Mainstay user, so the one asked about is null: a read
passes with `#[Internal]` fields absent, and a write is refused unless the caller passes
`overrideAccess: true`, the way a seeder bypasses authorization. Payload's Local API has the
opposite default — it skips access control unless `overrideAccess: false` is passed — and that
default is the one not to copy. Phase 9 changes where the user comes from and nothing else in this
layer.

`#[Internal]` is honoured here, not in a controller, or the local caller and the HTTP caller diverge
— which is the whole reason this layer exists. Filtering or sorting on an internal field is refused
in the words an unknown field is, so its value cannot be read back off which entries match.

The check is a round trip: create through the layer and read back through it, in two locales. A
colliding slug is refused with nothing written, an internal field is absent without an override
and present with one, and a write without an override is refused. It runs on all four drivers.

## Phase 4 — Public rendering

`#[Template]`, and one catch-all registered last, matching a path through the lookup table phase 3
writes and falling through to an ordinary 404. Template resolution cascades: per-entry override,
then the type's declaration, then convention from the type name.

Host templates call the query layer directly. They do not touch Eloquent and they do not make HTTP
requests to their own server.

A locale has a base, a path prefix or a host, and the catch-all strips it and looks up
`(site, locale, uri)`; phase 3 stores the path without one, so choosing between them rewrites no
rows. A link is the locale's base and the entry's `uri`. An unprefixed default locale can hold a
path whose first segment is another locale's prefix, so the rule against that belongs here too.

The host is a real site in its own repository, consuming `mainstay/cms` through a path repository,
not the testbench skeleton. Real requirements drive the design — which is what the design-phase
decision assumes when it says the `@foreach` a designer wrote is where the content model comes
from. The consequence is that nothing end-to-end runs in this repository's CI, so the phases here
stay covered by their own checks and the site is where the package is found to be wrong.

Content reaches the site through phase 3, from the site's own seeders and import commands. Every
field an editor would fill is one a seeder can, so that is enough to prove the model, and the model
is the first thing the site should be allowed to find wrong.

**This is the milestone that matters.** Declare a type, write an entry, visit a URL. Everything
after it makes that loop richer rather than making it exist.

## Phase 5 — Rich text and blocks

The JSON column holding an ordered array of `{ id, type, data }`, and rich text stored as
ProseMirror document JSON, both written through phase 3's layer.

A PHP walker turning that document into HTML at render time. The node schema is closed and an
unknown node renders as nothing.

Block Blade templates on the site are the other half, and they are the only part of a design
conversion that is not a rename.

Until phase 10 a document is written by hand, as a nested array in a seeder. That is the cost of
testing the model before the editor exists.

The check is a document fixture rendered to expected HTML.

## Phase 6 — Media

Upload, then derivatives generated immediately and written as plain files. Originals kept
permanently. Derivative filenames are the original's content hash plus the size and crop parameters,
so regenerating writes new files and no cache needs invalidating.

Sizes are declared in code beside the field. Crops and focal points are data on the media record, so
reprocessing honours them. `mainstay:media:reprocess` runs on the queue.

Upload is a call from code first, so a seeder attaches an image the same way it writes a title. The
picker and the browser are the admin's, in phase 10.

## Phase 7 — Globals, taxonomies and relations

Globals reuse the field attributes and phase 3's layer, and differ only in having no route, no slug,
and one row per site. The draft row and the revisions table reach them in phase 8, alongside
entries.

A relation is a field storing a plain ID, resolved defensively at every `depth`. This is where
`depth` first has something to follow.

Taxonomies are the deliberate exception to the relations decision: a term page exists to run the
reverse query, so the pivot is real. One polymorphic `(term_id, entry_type, entry_id)` indexed both
ways. Terms are flat, take `#[Template]`, and route through the same lookup table.

The check is one call returning identical shapes at depth 0 and depth 1, and a trashed target
skipped rather than failing the read.

## Phase 8 — Drafts, publishing, revisions, trash

At most one draft row per entry and per global, in a parallel table. The entry row always holds
published content, so the site's read stays a single-row select with no version resolution on it.

Publishing is one transaction: the draft overwrites the entry row, the outgoing content is appended
to revisions, the draft is deleted. That transaction is also the single place where "content
changed" is known, which is where the deferred publishing question will attach.

Phase 3's create and update keep writing the entry row, so the site's seeders and import commands go
on changing the site. A direct write is a publish with no draft before it and runs through the same
transaction, so it appends a revision too. Saving a draft is its own call, and it is the one the
admin's save button makes.

Revisions are one shared table — type, id, JSON snapshot, author, timestamp, and a hash of the
declared field set. The author is null for anything published before phase 9 gives a write someone
to be. Restore drops unknown fields, fills missing ones with defaults, tells the caller what
changed, and writes a draft rather than republishing.

Delete already trashes, from phase 3. Restore rewrites the lookup row from the route pattern and
takes the next free URI if it is gone, saying which one it took.

Publish and restore are calls on the layer here, and buttons in phase 10.

## Phase 9 — Identity

Mainstay's own users table, guard, session cookie and login view. A setup route that creates the
first account and is reachable only while the user table is empty — checked when it renders *and*
when it submits.

Authorization is the other half. Capabilities are strings derived from each type's declared
capability type and never stored. `mainstay_roles` holds a name and a JSON array of them.
`Gate::before` resolves primitives from the role plus per-user grants and denials; one policy per
shape does the ownership mapping. The query layer has asked `Gate` about Mainstay's user on every
call since phase 3; the one change there is that the user now comes from this guard rather than
being null.

`owner_id` is filled from here, and every row written before this phase has none.
`edit_others_pages` has to answer for a null owner, and "someone else's" is the answer that fails
closed.

The check is a capability set derived from a fixture type, and a policy test for
`edit_others_pages` against an entry someone else owns and against one nobody owns.

## Phase 10 — The admin

Everything the phases before this built from code, drawn. Navigation generated from the registry. A
list screen per type on top of `EntryList::shape()`, which already exists and is tested. A form built
from the field list, one Blade component per field type, drawn with the components in
`packages/ui`, and saving through phase 3's write, so the admin validates exactly as a seeder does.
A slug fills from the title as it is typed.

The editor island wired into the form for rich text fields. The block list built by moving DOM
nodes, not re-rendering a list. `insertBefore` on a live node is a move, so typed-in state, focus and
any editor instance inside a block survive a reorder. Adding clones a `<template>`; removing is
`.remove()`; reordering is the platform's `draggable`. Serializing walks `[data-block]` in DOM order
into a hidden input inside the form, so `FormData` sees it and `dirty-form.ts` covers blocks with no
change at all.

The media picker and browser on the behaviour layer. Publish, restore and the trash as buttons over
the calls phase 8 made.

Preview is a signed URL behind Mainstay's guard, in an iframe beside the form, reading the draft
when there is one.

This is the largest phase, and it cuts by field type: scalars first, then rich text, blocks and
media, each a screen that works before the next begins. It is also where the field contract's
interface half is first exercised, so expect its last reshape here.

The check is a round trip — create through the admin, read back through the query layer — and a
block reorder that preserves an untouched sibling's value.

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

Nothing new in the schema — phase 2 put the columns there, and phase 3 already reads and writes
more than one locale. This is where the rest is proven by configuring a second of each: the locale
switcher, per-locale publish state and trash, fallback as an opt-in, and the request host matched to
a site. With one of each configured, none of it renders.

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

How rendered pages reach the public is still deferred, and phase 8's publish transaction is the hook
it will attach to.

## Decided while writing this

- **Types are registered, not discovered.** A directory scan and a config array were the
  alternatives. Registration in a service provider wins on there being one extension mechanism for
  types, field types and onboarding steps rather than three.
- **Phase 1 ships six scalar field types, not eleven.** Declaring the awkward ones early would only
  freeze a contract before anything had tested it.
- **Content works before anyone can log in to it.** Identity and the admin were phases 3 and 5,
  ahead of the query layer and rendering. They are now 9 and 10, so a real site tests the content
  model from phase 4, fed by seeders rather than an editor. Identity came first so that access
  control could not be left out of the query layer. That is kept by putting the `Gate` check in the
  layer from its first call and having code opt out explicitly, so identity arrives as a user for
  `Gate` to ask about rather than as a change to the layer. The costs are rows written before phase
  9 with no owner, rich text written by hand for five phases, and an admin phase that carries every
  field type's interface at once.
- **Globals, taxonomies and relations come before drafts.** A real site needs its navigation, its
  categories and its related entries before it needs an editorial workflow. Globals were after
  drafts only to reuse the draft row; the drafts phase now adds that row to both shapes at once.
- **Locales arrive in phase 3, not phase 12.** The real site is multilingual from the start, so
  the layer takes `locale` on every call and writes a row and a path per locale from its first save.
  Phase 12 keeps what only a second locale in the admin needs.
- **Preview moves to the admin.** It sat in rendering, showing published rows until drafts arrived.
  It needs Mainstay's guard, which now arrives after drafts, so it is built once, reading the draft,
  beside the form that edits it.
