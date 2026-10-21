# Plan

How `packages/cms` gets built, in the order the decisions in `decisions.md` allow. Each phase ends
at something that works and can be looked at, not at a layer that is finished.

Phases 1 to 9 are done: content types are declared, reflected into a field list, synced into
tables that `mainstay:schema:check` holds CI to, read and written from PHP through one query
layer, and served at their paths in Blade by a host site, `mainstay-site`. Rich text and blocks
are field types like the scalars, each kept in a `json` column of its own, and the site's bodies
and front page are written in them. Images live in an installation-wide library, written at every
declared size in AVIF and WebP when they are uploaded. Entries point at each other through
relations a read follows to a depth, tags are the entries of a taxonomy found again through its
pivot, and a global holds the site's menu and footer. A change waits as a draft of what differs
from live until it is published, every version a write replaces is kept as a revision a draft can be
made from again, and the trash is restored from or emptied. The admin has accounts of its own
behind a login, and the query layer asks Gate about the one signed in, whose role's capabilities --
each type's own, or a form covering every type -- decide what it may write and publish. Phase
10 is done too: every entry has a title, and the admin lists each type's entries and edits their
fields in every language -- rich text in a ProseMirror editor, a page's blocks as raw JSON, images
picked from a media library of its own, relations as rows and tags typed -- saving a draft or
publishing it, with the trash, the front page and each entry's history, naming who published each
version, as screens. Phase 11 is done: accounts are invited from the admin to choose their password
by mail, roles are a grid of the capabilities the types derive, nobody hands out what they do not
hold, everyone has settings of their own, and an entry's author is changed in its form.
`packages/ui` is ahead of the package — Blade components, a theme, and a behaviour layer in
`src/js` — and `packages/editor` is the rich text editor, ProseMirror without React, declaring the
document schema the package renders.

The order puts content working before anyone can log in to it. Up to phase 8 everything is driven
from PHP — the site's own seeders, import commands, tinker — against a real site. Identity and the
admin arrive after the model has been proven, as a surface over a layer that already works.

## What is already settled and does not get re-opened

Structure is PHP classes read by reflection. Content lives in per-type tables with real columns for
scalars and a JSON column for each tree: a document, a list of blocks. There is one query layer and HTTP is a transport over it.
The admin renders in Blade. Client state is vanilla TypeScript. Everything is soft-deleted, scoped
to a site, and localizable per field.

## Corrections to carry into the work

Twenty-five things in `decisions.md` are stale or contradicted by a later entry. They are recorded here
rather than edited into the log, which is append-only by construction.

- **Schema sync never has to plan child tables.** Its consequences say "repeaters and blocks become
  child tables with ordering columns and foreign keys, and that is where the complexity arrives."
  The later blocks decision puts blocks in a JSON column and says a repeater is a block with one
  type. So the diff stays scalar-only, permanently, and phase 2 is much smaller than that
  consequence implies. Localization is what adds a second table per type, not blocks.
- **`config/mainstay.php` mounts the API with no guard.** The API decision names this a
  placeholder. It stays wrong until phase 13; nothing should be built on the assumption that an API
  route is reachable.
- **`GlobalSet` is a base class, not an attribute.** The globals entry says "the attribute is
  `#[GlobalSet]`". Phase 1 builds the three shapes as base classes a type extends, so there is no
  attribute of that name to write and nothing reads one.
- **`Navigation::sections()` lists a "Content types" screen.** Types are classes and there is no UI
  for authoring them. That item can only ever be a read-only inspector, and phase 10 drops it,
  building the navigation from the registry.
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
  default. Phase 3 has none, and phase 14 adds it as an opt-in, so a live multilingual site's
  listings do not start mixing languages on the deploy that ships it.
- **The catch-all needs no registration order.** The routing entry says it "must be registered
  after everything else, so ordering becomes a documented requirement." It is a fallback route,
  which Laravel matches after every other route whatever order they were registered in, so there
  is no requirement to document. The phase 4 entry has why it is not `Route::fallback()` itself.
- **A field stored as JSON has a column of its own.** The field types entry says "a custom field that
  stores in JSON requires no migration", which one JSON column shared by every such field on a table
  would give. Laravel cannot update one key of a JSON column on SQL Server, so every save of one of
  those fields would read the column, merge and write it back under a lock, to keep a save writing
  only what it was given. So a document or a list of blocks is a `json` column of its own, which
  `mainstay:schema:check` sees like any other: adding one to a type is a sync and a migration, as
  adding a text field is. Adding a field inside a block still costs nothing, which is the case the
  blocks decision was about.
- **`mainstay.locales` is a map, not a list.** The localization entry has it carrying "`locales`, a
  required default, and a fallback flag." It maps each locale to where it is served, the first
  being the default, and there is no fallback flag until phase 14 makes fallback an opt-in.
- **Terms are entries.** The globals and taxonomies entry has three shapes of content, and the public
  pages entry keeps `template` an entry's alone, "so globals and taxonomies keep the name for their
  fields". `Taxonomy extends Entry`, so a term has the column and may name a view, and is a shape of
  its own only in being what a Terms field points at.
- **The term pivot is a table per taxonomy.** The same entry has "a single polymorphic table of
  `(term_id, entry_type, entry_id)`" with a real foreign key. Each taxonomy's terms are a table of
  their own numbering its own rows, so one table would need a taxonomy column and could carry no
  foreign key. It is `{handle}_entries`, one per taxonomy, built by sync -- still no table per pairing.
- **A global's row exists once it is written.** The trash entry has it that "their row always exists,
  one per site". Nothing creates it before the first save -- sync is off in production, and a
  migration inserting content is a migration per global -- so one not written yet reads as null. It is
  still never deleted.
- **A relation whose target is away keeps its place.** The relations entry has the renderer skip what
  it cannot load, and phase 7's check had a trashed target skipped. Phase 6 read an image that is away
  as a Media marked missing and kept it through saves, and a relation does the same; the template is
  what skips it.
- **A draft is the changes, kept as JSON in one table.** The drafts entry has a draft in "a parallel
  table" that the admin's read left-joins. A draft may be incomplete, which a NOT NULL column cannot
  hold; it carries an entry's terms, which live in a pivot; and it is read whole and never filtered.
  So it is the fields that differ from what is live, in a `json` column of `mainstay_drafts`, and a
  never-published entry is a draft with no entry at all.
- **A revision is the whole entry.** The localization entry has a revision snapshot "the entry as read
  in one locale, so restoring is per locale too". Restoring one language would bring back the shared
  fields every other language reads as well, and a publish touches every locale its draft names, so
  one publish files one revision of every locale.
- **Revisions carry no field-set hash, and a field added since keeps its live value.** The drafts entry
  stores a hash and fills missing fields with defaults on restore. Restore compares the snapshot with
  the declaration directly, which finds a field retyped under its old name where a hash only says
  something changed; and since a restored draft is the difference from live, a field the snapshot
  never had stays as live has it rather than being reset to a default.
- **Restoring from the trash changes the slug with the path.** The trash entry has restore take the
  next free URI, and `Entry::$uri` was written to hold a path the pattern would not give. The next
  save would build the old path from the slug and be refused, so the field that ends the pattern is
  suffixed instead, and slug and path agree.
- **Terms are written live.** The drafts entry gives every type drafts and revisions. A taxonomy has
  neither: a term is written straight to the site, and nothing records its history.
- **Capabilities are named from the handle, and no type declares a capability type.** The
  authorization entry has types declare one, from which their set is derived. Each type's set is
  named from its handle, and beside it every capability has an all-types form covering every type of
  its shape, so a role names a few types or all of them, those deployed later included.
- **There is no `Gate::before`.** The authorization entry resolves primitive capabilities there. The
  policies ask the user what it holds, and Mainstay's Gate carries no callbacks at all, since the
  host's are typed for the host's own users.
- **A page's content is edited on its page, in the admin.** The rendering entry has "Notion-style
  inline block editing is off the table" and preview beside the form, and the blocks entry picks a
  form-based list of blocks on the strength of it. Phase 12 gives every entry content, as WordPress's
  `post_content`, and edits it in the admin on the page the site renders from the form, blocks in
  place, with no preview beside it. Blocks stay data, a node tree and not markup, and are drawn by
  Blade alone. So the admin builds no block list but a block's own items in its sidebar, the
  client-state entry's blocks moved as DOM nodes included. The entries that keep preview "a signed URL
  behind Mainstay's guard" get the guard without the signature: the page is drawn at an admin route,
  and nothing outside the admin opens a draft.
- **The log's later phase numbers are the old ones.** `decisions.md` has onboarding as phase 13 and
  per-site locales as phase 12. With accounts and the page builder phases of their own, they are 15
  and 14.
- **A front page's path is a setting.** The routing entry has every path from its type's pattern.
  The site's front page, chosen in the admin as WordPress's static front page is, is served at `/`
  whatever its pattern builds, and goes back to that path when another is chosen.

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
hop. `where` and `sort` are data rather than closures, so phase 13's transport carries the same call
a template makes. A read hydrates the declared class through the field types, and every read starts
from one query scoped to the site, to the locale's row and out of the trash. The query builder, not
Eloquent: the declared class is the model, and an Eloquent one would be a second object per row.

`locale` is here rather than in phase 14, because the real site is multilingual from the start. It
defaults to the request's locale and has to be one of `mainstay.locales`, the first of which is the
default. There is no fallback: an entry with no row in a locale is not there in it. `depth` waits
for phase 7, where relations give it something to follow, and `site` for phase 14. Both are named
arguments, so adding them breaks no caller.

Writes belong to the layer rather than to the admin, for the same reason reads do. A save validates
the entry as it will be stored from the declared rules, writes the row and its `_locales` sibling in
one transaction, and rebuilds every locale's URI lookup from the type's `#[Route]`: one pattern, or
one per locale where a segment is translated. An update naming a locale the entry has no row in adds
that translation; one taking the request's locale finds the entry absent there, as a read would. A
slug colliding with a URI another entry holds is a validation error on the field, not a silent
suffix, and the lookup's unique index decides it. A routed field holds what `Str::slug` writes,
since that index folds case on MySQL and SQL Server and not on the others; deriving it from the
title is a convenience for someone typing, and waits for the admin. Delete sets `deleted_at` and
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
in the words an unknown field is, so its value cannot be read back off which entries match, and so
is writing one, for a caller who may not see it.

The check is a round trip: create through the layer and read back through it, in two locales. A
colliding slug is refused with nothing written, an internal field is absent without an override
and present with one, and a write without an override is refused. It runs on all four drivers.

## Phase 4 — Public rendering

One catch-all, answering every path no route the host wrote does: the locale from the request's
host and path prefix, then the entry the rest of the path leads to, then its view. A path that
leads nowhere is an ordinary 404. It is a fallback route, so the host's own routes always win
whatever order they were registered in.

The entry is found by `Mainstay::findByUri()`, a read on phase 3's layer like the others: the lookup
row, then `findById()`, so the site, the trash and Gate apply as on every read. A type the caller
may not read is null rather than refused, since the caller named a path and not a type. So is a
path that is not lowercase segments, which no path is stored as and which MySQL and SQL Server
would match to one anyway.

The view cascades: the entry's own `template`, then the type's `#[Template]`, then the type's
handle. The first one given wins, not the first one that exists, so a view named and missing is an
error. `template` is a nullable column on every entry's main table and a reserved name, written in
a save's data like a field and checked when it is written: one of the views the type's
`#[Template]` lists, `#[Template('docs.page', 'docs.wide')]`, since a view is written against a
type's fields. The view is handed the entry as `$entry`.

`mainstay.locales` maps each locale to where it is served — a path prefix, a host, or both — and
either every locale names a host or none does. The catch-all takes the most specific base, a
prefix matching whole segments, strips it, looks up `(site, locale, uri)`, and sets the locale as
the application's, so every read the template makes is in it. Phase 3 stored the path without a
base, so choosing between prefix and host rewrites no rows. A link is the locale's base and the
entry's `uri`: `$entry->url()`. A path that another locale's prefix or a route of Mainstay's own
would answer instead is refused when it is written, and a `#[Route]` whose every path leads there is
refused for every entry.

Host templates call the query layer directly. They do not touch Eloquent and they do not make HTTP
requests to their own server.

The host is `mainstay-site`, Mainstay's own project site, in its own repository beside this one and
consuming `mainstay/cms` through a path repository rather than the testbench skeleton. It serves
English and Dutch on hosts of their own, from seven types written by its seeders — a front page, the
features it lists, pages, the blog and the docs indexes, articles and docs — and has no route of its
own. Nothing end-to-end runs in
this repository's CI, so the phases here stay covered by their own checks and the site is where the
package is found to be wrong.

Content reaches the site through phase 3, from the site's own seeders and import commands. Every
field an editor would fill is one a seeder can, so that is enough to prove the model, and the model
is the first thing the site should be allowed to find wrong.

The check is an entry written through the layer and visited at its path, by prefix and by host:
each step of the view cascade, a host's route and a host's fallback beside the catch-all, a path
that leads nowhere, a type the visitor may not read and an internal field kept off the page, the
locale map refused where a request could not be told apart, and a path another locale or the admin
would answer refused. It runs on all four drivers.

**This is the milestone that matters.** Declare a type, write an entry, visit a URL. Everything
after it makes that loop richer rather than making it exist.

## Phase 5 — Rich text and blocks

Two field types, and nothing else in the layer changes shape. A field whose `column()` is `['json']`
serializes to an array, which the store encodes on the way in and decodes on the way out, so a field
type never handles a string and a field nested in a block's JSON serializes to the same array a
column of its own is written from. A JSON field is not filtered or sorted on: a tree is not a value
to compare, and Postgres has no operator for a `json` column anyway. A save checks what is stored
there as a read would hand it back, not as the string it is kept in.

`#[RichText] public ?Document $body` is a ProseMirror document, written as the nested array its
`toJSON()` gives or as the Document a read handed out. The node schema is closed and ProseMirror's
own: paragraph, heading (2 to 4, since h1 is the page's title), blockquote, code block, both lists
and their items, rule and break; link, em, strong and code marks. `packages/editor/src/schema.ts`
declares the same one, and one fixture document is loaded by both. A save refuses a document
outside it, naming the path to the node that is wrong, nesting included, so a document a seeder
writes by hand is one the editor in phase 10 can open. A link leads to a web, mail or phone address
or a path, decided from the text before the colon, since `parse_url()` finds no scheme in the
`java\tscript:` a browser runs. The Document is Htmlable, so a template prints it. The walker
escapes everything, draws an unknown node or one whose shape is wrong as nothing, and drops a mark
it does not know or a link it would not have saved while keeping the text. No empty value: the
empty document is a paragraph holding nothing, and storing one for an unset body would print it.

`#[Blocks(of: [Hero::class, ...])] public array $blocks` is an ordered list of `{ id, type, data }`.
A block is a class extending `Block`, its properties carrying the same field attributes an entry's
do, its type its handle. The layer gives a block with no id a new one and keeps an id it is given.
It reads back as a list of block objects, each field cast through its type, and each item is checked
by its own block's rules, reported as `blocks.0.data.heading`. A block type the field no longer
lists is refused in a write and skipped in a read, and a save of the entry does not trip on one still
stored. A field of a block may itself be blocks, which is all a repeater is. The field is translated
whole or not at all, so `localized` and `#[Internal]` inside a block are refused, and so are a block
that holds itself and two blocks of one handle, anywhere. Every field of a block needs a default, or
has to read nothing as something -- nullable, or a type with an empty value, as text has -- since a
block stored before the field was added does not have it, and reads with its default or that. One
with neither is refused where it is declared, so a document or a date a block must have is declared
nullable with `required: true`. `mainstay:schema:check` reads every stored document and list of
blocks, on entries and inside blocks, and fails naming the row that its field cannot read or holds a
value of the wrong kind for -- a block field retyped under stored blocks, prose left in a column now
rich text. A value only missing is not drift: sync fills a required document with an empty one and a
required list with none. That check is the guard: a read shows a value a retype coerced -- a number
field reading 'heavy' as 0 -- and a save giving the blocks back writes what it showed. A Block is
Htmlable too, drawn by the view `blocks.{handle}`; a missing one is an error, as an entry's is.

Everything a site draws a block with is the site's. The package holds the field types and the base
class, and its tests hold the blocks they need.

The site's pages, articles and docs have rich text bodies, written by its seeder from the plain
text they were, and its front page is a list of blocks, which the Feature type it listed was only
standing in for.

The check is a document fixture rendered to expected HTML, every node and mark, with an unknown
node and mark, a refused link and escaping beside it; the same fixture opened by the editor's
schema; and documents and blocks written and read back through the layer on all four drivers, in
two locales, with a nested repeater, a date inside a block, a refused block type, a save of another
field on a row that already holds both, and the drift check passing over the json columns.

## Phase 6 — Media

Images, and only raster ones: JPEG, PNG, GIF and WebP, recognised by their bytes rather than their
name. Other downloads and SVG wait for a site that needs them; an SVG can carry script. A seeder
uploads a file through `Mainstay::media()` and gets a `Media` back, and an entry's field holds its id.
Every declared size is written at upload, in AVIF and in WebP, as plain files.

**The library** is `mainstay_media`, a package migration, installation-wide rather than per site: the
SHA-256 of the original's bytes, unique; its type, its name as uploaded and its byte size; its width
and height once upright; a focal point; alt text; the timestamps and `deleted_at`. `owner_id` waits
for phase 9, where it is one migration of one table, and the name joins the tables a content type
cannot take. Uploading bytes the library holds is refused naming the image that holds them, trashed or
not: an upload neither edits an image nor restores one. The unique index decides two uploads racing
with the same bytes, in a savepoint, so the refusal leaves a seeder's transaction usable on Postgres.

Alt text belongs to the image, per locale, in a `json` column -- Payload's model. An upload names
every content locale, an empty string marking an image decorative, so a language left out is refused
rather than shipped undescribed; an update merges the locales it names. A locale added later reads as
null until someone writes it. The focal point is a whole percentage across and down, `[50, 50]` until
one is set.

**Files.** The original is kept permanently on `mainstay.media.originals`, Laravel's private `local`,
as `media/{hash}.{ext}`, and never served: it carries the camera's EXIF, GPS included, which the
re-encoded copies do not. The copies go to `mainstay.media.disk`, `public`, as
`media/{hash}-{w}x{h}-{x}-{y}.{avif|webp}` for a size that crops and `media/{hash}-{w}.{avif|webp}` for
one that only scales, so moving the point leaves the scaled files alone. Every file is written under a
temporary name and moved into place, each step checked whatever the disk's `throw` says, so a name
that exists is a whole file: generating skips it, and nothing needs invalidating. Files are written
before the row. Moving the point writes new files beside the old, and old URLs go on serving what they
served. A removed size orphans its files until something prunes them, as `decisions.md` accepted.

**Processing** is `spatie/image` on GD everywhere, chosen explicitly since the package prefers
Imagick: one engine is one set of tests proving the output. `loadFile()` turns the image upright from
its EXIF orientation. A crop is `manualCrop()` of a region Mainstay works out around the focal point,
then `resize()` -- spatie's `focalCrop()` takes a centre in pixels and does not scale, and
`focalCropAndResize()` enlarges. `Mainstay\Media\Size` gives the region, the size written and what a
template is told, so they cannot disagree. GD decodes into PHP's memory, so an image over what
`memory_limit` leaves room for, at ten bytes a pixel and read from its header, is refused before it is
decoded. A GD that cannot write AVIF or WebP refuses the upload naming which.

**Sizes** are declared on the field:
`#[Image(sizes: ['cover' => [1600, 900], 'card' => [640]])] public ?Media $cover`. A width and a
height crop to fill that shape around the focal point; a width alone scales to it. Neither enlarges:
an original smaller than the size is written at its own size, in the shape asked for. An upload does
not know which field it is for, so it writes every size any registered type or block declares, and a
size two fields declare alike is one file. `mainstay:media:reprocess` queues a `Reprocess` job per
image out of the trash, writing what the current declarations name and nothing written yet.

**The field** stores a plain id in an `unsignedBigInteger` column, and inside a block's JSON the same
way, with no foreign key. The property is nullable, refused otherwise: null is no image chosen, and
`required: true` makes a write need one. A write takes a Media or an id, and refuses on the field one
that is not an id, or that the call gives and the field does not already hold and the library does
not hold out of its trash. What a row holds is never asked about again, so trashing an image stops no
save and fails no `mainstay:schema:check`, which still names a value inside a block that is not an id.

An image trashed or gone reads as a Media marked `missing` that keeps its id: `picture()` prints
nothing, `url()` gives null, and writing it back writes the id. So a list of blocks saved while it is
away keeps the reference, and its return brings back every place it was used.

A read loads the images its rows hold in one query, nested blocks included, inlined with
`whereIntegerInRaw` past SQL Server's 2100 parameters. `Field::images()` names the ids a value holds,
Blocks walking its items by their declarations, and `cast()` is handed what was loaded, which Blocks
passes down. This is the reshape of the contract's storage half phase 1 expected: a field lives as
long as the process, so what one read loaded travels with the cast. A cast handed nothing -- the entry
a write shows Gate -- reads every image as missing. Every read resolves its images; whether depth 0
hands back the id instead is phase 7's question.

**In a template** `$entry->cover` has `alt` in the locale it was read in, `url('card')` for the WebP
and `url('card', 'avif')`, and `width('card')` and `height('card')` as written. `picture('card')`
prints a `<picture>` with the AVIF as its source and the WebP as the `<img>`, alt and dimensions on it,
lazy unless the attributes passed say otherwise. A Media from `find()` or `upload()`, read through no
field, has no sizes and says so. A `srcset` across sizes waits until a site's templates repeat one;
an image inside rich text waits too, since a node for one changes the closed schema in both places.

`Mainstay::media()` takes `upload()`, `find()`, `update()` and `delete()`, which ask `Gate` through a
`MediaPolicy` shaped like `EntryPolicy`: reading is open, and until phase 9 a write needs
`overrideAccess: true`. Delete trashes and the files stay; restore is phase 8's.

**The site.** Articles have a cover, drawn above the body and as a card in the blog listing and the
front page's latest articles; the Hero block has a screenshot under its text and code. The images are
screenshots of the site and of the admin's design in Storybook, uploaded once each by the seeder with
English and Dutch alt text -- the post list is both the Hero's and an article's, one image. The site
links `storage`, and its tests fake both disks.

**Locally** the host's PHP has neither GD nor Imagick. The `mainstay-php-sqlsrv` podman image has GD
with JPEG, WebP and AVIF, exif, every PDO driver and composer, and runs every driver's suite and the
site's `composer content` and tests; the dev server stays on the host's PHP, serving plain files. CI's
runners have GD and exif; whether their GD writes AVIF is what the first push confirms. MediaTest runs
in the Postgres, MySQL and SQL Server jobs beside the three suites they already ran.

The check is `MediaTest`, on all four drivers: the original kept privately and every declared size
written in both formats under its hash; a second upload refused naming the first, from a second
connection racing it too, inside a caller's transaction and out; a JPEG with an EXIF rotation upright;
a crop holding the focal point's colour where the point lands, and moving it writing new files beside
the old; nothing enlarged, and every file the size a template is told; a file that is not an image, one
too large for memory, and alt or a focal point out of shape refused with nothing written; a failed write
leaving no row and no part of a file; images read back through an entry and two blocks down with alt
in two locales; a listing's images in one query; a write naming an image the library does not hold
refused where it is; writes refused without the override; a trashed image read as missing and kept
through saves and the drift check; and reprocess queueing one job per image and writing a size declared
since.

## Phase 7 — Globals, taxonomies and relations

Three ways content points at other content, and `depth` to follow them. Every target is read through
the query every read starts from, so the site, the locale, the trash and Gate apply to it as to
anything else read.

**Relations.** `#[Relation(to: Article::class)]` points at entries. With one type in `to:` it stores a
plain id: `public ?Article $author` in an `unsignedBigInteger` column of its own, which a `where` can
filter on, `where: ['author' => 3]`, and `public array $related` as a JSON list of ids. With several,
`to: [Page::class, Blog::class, Docs::class]`, each reference is `{type, id}` by the type's handle, in
JSON whether one or a list, since an id alone does not say which table: each type numbers its own
rows. The property says one or a list: nullable and typed as the target or a union of the targets for
one, `array` for a list, which holds none rather than null. No foreign key, in a column or inside a
block, as `decisions.md` chose. `to:` names entries, and a reference to a type that is not registered
is refused the first time one is read or written, since types register after the attributes are
read. A global is no target: there is nothing to choose between. Changing a one-type `to:` to another type points every
stored id at the new type's rows, and nothing can tell: a stored id says no type. That is a migration
of the ids, by hand.

A write takes an entry or an id, or for several types an entry or `['type' => 'page', 'id' => 3]`. It
refuses on the field, at its path, a type the field does not name, a list naming one target twice
(at the second), and a target the call gives and the field does not already hold that the writer
cannot read -- absent, trashed, on another site, or of a type the writer may not read, in one
wording, so the refusal does not tell them apart. The locale is not asked: a target not translated
into the write's locale is written, and reads as missing there. What a row already holds is not
asked about again, as with images.

**Depth.** Every read takes `depth`, 1 unless the call says otherwise: `find`, `findById`,
`findByUri`, `paginate` and `global`. At 1 a relation holds the entry it points at, read as its type's
own read would read it at depth 0, with its internal fields shown or not by its own type's policy;
at 2 that entry's relations are read too. A write hands back what a read at the default depth would.
No cap here; phase 13 caps what a request may ask, and a cycle only costs the levels asked for.

A target not loaded is an object of its class holding only its id, `missing` true, as an image is:
depth ran out, or it is trashed, gone, untranslated in the locale, or of a type the reader may not
read. Every field is absent, `url()` is null, and writing it back writes the reference, so a list of
blocks or of relations saved while it is away keeps it, and its return brings back every place it was
used. A list keeps it in its place, so a template skips it: `@continue($related->missing)`. The
property is the same type at every depth, which is the check. A reference whose type the field no
longer names is left out of a read and of the next save, as a block whose type its field no longer
lists is. `missing` is a public bool on `Entry`, as on `Media`, and joins the reserved names: a type
redeclaring it as a bool is refused by sync, and one redeclaring it as anything else fails to load
with PHP's message about types.

A level costs one query per target type and one per Terms field's pivot, for all the rows read,
however many: the references they hold are gathered first, nested blocks included, and each type's
ids read in one `whereIntegerInRaw`, past SQL Server's 2100 parameters. A Terms field's pivot is read
at depth 0 too, since the ids are there and nowhere else. Images are not a hop. Every entry loaded at
any level has its images, one query a level, so a related article's card has its cover and a listing
at depth 0 still prints covers; `missing` on an image keeps meaning trashed or gone. Phase 6's
`images()` becomes `references()`, naming the images and entries a value holds by path, and `cast()`
is handed what the read loaded of both, which Blocks passes down: the same reshape carried a step
further rather than a second mechanism beside it.

**Globals.** `Mainstay::global(Layout::class)` reads one and `Mainstay::saveGlobal(Layout::class,
[...], locale: 'en')` writes one, through phase 3's validation, locales and Gate. A global has no
route, no slug, no view, and one row per site, which a unique index on `site_id` holds -- a declared
key, so the drift check sees it. Its read is the entry read without the lookup join and the
`template` column, which a global does not have and may name a field after; hydrating it hands back a
`ContentType`. One nobody has written, or not written in the locale read, is null, which is what
"absent, not a fallback" means for a global: `Mainstay::global(Layout::class)?->footer`. The first
save creates the row; two racing for it leave one, in a savepoint so Postgres keeps a caller's
transaction usable, and the loser reads the winner's row again with a lock where a plain read would
answer from its snapshot -- MySQL inside a caller's transaction -- and updates it. Without a locale a
save updates the global as the request's locale reads it and is refused where that has no row, as an
update is: the request's language never creates content. A global is never deleted. Gate answers for
a global with `GlobalPolicy` unless the host chose another: reading is open, and every save asks
`update` about the type, whether or not the row exists yet, which until phase 9 needs
`overrideAccess: true`. `find()` and the other entry calls go on refusing a global. The draft row and
revisions reach globals in phase 8.

**Taxonomies.** A taxonomy's terms are entries: `Taxonomy extends Entry`, so `class Tag extends
Taxonomy` has its tables, a route, a view, `url()`, the reads and the writes with nothing new. What is
new is that a Terms field points at it and a read filters by it. A term may name a view of its own, as
any entry may, and phase 8 decides whether terms get drafts.

`#[Terms(of: Tag::class)] public array $tags` holds an entry's terms in the order given, and refuses
one named twice. It is the first field with no column: `column()` answers null, and the schema plan,
the read's select, a write's row and what `stored()` reads all skip it; its values come from the
pivot when a save loads what is stored, validates it and hands it back. Its rows are in
`tag_entries`, which sync builds beside `tag` and `tag_locales`: an `id`, `term_id` with a real
foreign key to `tag`, `entry_type` and `entry_id` for whichever type holds the field, and a position.
Unique on `(term_id, entry_type, entry_id)` and indexed on `(entry_type, entry_id)`, so each direction
is one index, both named short and explicitly, since Laravel's generated names pass MySQL's 64
characters for a handle past 21. The drift check compares keys today by their columns as a set and
reads only unique ones, so it learns plain indexes, compared in column order. A type whose handle ends
in `_entries` is refused as `_locales` is. The store writes the rows in the save's transaction, only
what changed, as it writes paths. The pivot has no locale, so an entry's terms are the same in every
language and the field refuses `localized`. It is refused inside a block, where the reverse query
could not see it, and on a global; two Terms fields of one taxonomy on one type are refused, since a
row does not say which field it is for.

The reverse query is a `where`, so it pages and sorts as any filter:
`Mainstay::paginate(Article::class, where: ['tags' => $tag->id], sort: '-publishedOn')`. `=` finds
entries holding a term and `in` entries holding any of several, each an exists on the pivot; the other
operators and a sort on the field are refused until something needs them. Only terms out of the trash
count, so a trashed term gathers nothing, and its pivot rows stay for its restore. A term in a Terms
field is otherwise a relation's target: what a write gives is checked, one not loaded is `missing`,
and depth follows it.

**The site.** A `Layout` global holds the header menu, a relation to Page, Blog and Docs kept per
language, so the Dutch menu can differ from the English, and the footer text; `menuPosition` goes from
the three types. Articles get tags: `Tag`, a title and a slug, at `/blog/tag/{slug}` and
`/nieuws/tag/{slug}`, each page listing its articles newest first with paging, and an article's tags
linked from its page. And related articles, `#[Relation(to: Article::class)] public array $related`,
drawn as cards under the body. The blog listing reads at depth 0, since it draws no related articles.
The seeder writes the global in both languages, a few tags, and the links between articles. The site's
tests visit every entry of every type but the global, which `find()` refuses, and count the tag pages
among them; keep the listing's covers to the one query they were; and see the menu in both languages,
a tag page's second page, and an article's related cards with their covers.

The check is `RelationTest`, on all four drivers, beside the suites each driver's job already runs:
relations written as an entry and as an id, one and a list, one type and several, in a column and two
blocks down, read at depth 0, 1 and 2 with the property the same class throughout; depth 1 over a
listing costing one query per target type, one per Terms pivot and one for images, whatever the row
count; a target's internal field shown or not by its own type's policy; a trashed, untranslated, other
site's and unreadable target read as missing in its place and kept through saves of the blocks and the
list and through the drift check; a write naming a target that is absent, trashed, or of a type the
writer may not read refused at its path in the same words, with nothing written; a repeat refused; an
untranslated target written; a single relation filtered on and a list refused as JSON. A global null
before its first save and in an untranslated locale, the second save updating the first's row, two
first saves racing from a second connection leaving one row, inside a caller's transaction whose
snapshot was taken before the other committed and out of one, a save without a locale refused where
the request's has no row, writes refused without the override, and `SiteSettings`, whose field is
called `template`, read and written. Terms written and read in order; a repeat refused; the reverse
query paging with `=` and `in`; a trashed term gathering nothing and keeping its rows; a term page
served by the catch-all in two locales with its own view; a Terms field refused localized, in a block,
on a global and twice for one taxonomy; and the drift check failing on a pivot without its foreign
key, without its unique index and without its plain one, each on its own.

## Phase 8 — Drafts, publishing, revisions, trash

What an editor is working on, beside what the site shows, and the way back from a bad publish and
from a delete. The entry row stays what is live, so the site's read is the single-row select it was
and nothing a visitor reaches changes. Every call here is made from PHP, as the writes before it are,
and becomes a button in phase 10.

**Drafts.** `mainstay_drafts`, a package migration: `site_id`, the type's handle, the `entry_id` it
changes, a `json` column of changes, and the timestamps. Who wrote it waits for phase 9, a migration
of this table and the revisions' as `owner_id` on the media library is. A draft is the changes, not a
copy: the fields that differ from what is live, as their columns hold them -- shared ones once,
localized ones under their locale, `template` beside them as a write takes it -- and a locale the
entry does not have yet, which the draft adds whether or not anything in it is filled in. A draft
changing only shared fields names no locale, and publish writes it in the first the entry has.

Two values are compared as their field serializes them, the live one read through its cast first, so
a date, a number or a boolean is one shape whichever driver returned it, and a map compares in any
key order, since MySQL sorts a JSON object's keys. A block given without an id is a new block and
differs; one given with the id a read handed out compares as stored.

`Mainstay::drafts()`, beside `media()`. `save(Article::class, [...], entry: 5, locale: 'nl')` merges
the keys given into the entry's draft, starting one if there is none, each compared with what is
live in that locale and kept only where it differs: a form posting every field leaves a draft of what
was changed, and a draft left with no change is deleted. The locale is taken as `update()` takes it,
so without one a draft of a translation the entry lacks is refused, and with one it adds it. Without
`entry:` it starts a draft of a new entry, which has no row, no id and no path until it is first
published, and is saved again by `draft:`, the draft's own id. A global's is
`save(Layout::class, [...])`, one per site, whether or not the global has been written. `find($id,
locale:)` and `of(Article::class, 5, locale:)` read one: what is live in that locale with the draft
over it, hydrated as the declared class with what it points at loaded to a depth as any read's, in a
`Draft` that carries its id, the entry's id or none, and the fields it changes by locale. `discard($id)`
deletes one.

A draft may be incomplete. The keys it holds are checked as a write checks them -- every rule of the
fields it names, a block's items by their own, an image or entry it names asked to be there -- except
that each field's own presence rule gives way, at every level: `required` becomes `nullable`, and a
required Boolean's `accepted` becomes `nullable|boolean`. The rules that keep a value's shape stay, a
block's `type` and a relation's `id` among them, since the field reads them back. So `rulesAt()`
takes a draft flag, passed down through blocks and relations, and a field type with a presence rule
of its own overrides `drafted()`: the contract's reshape here. An empty value is kept in the
draft as null rather than through `serialize()`, which a RichText, a Date or a Select with no empty
value refuses, and the encoding check skips it. An article saves with no summary yet. A field it
leaves empty that its property cannot hold, a required date, is absent from the entry it reads as;
phase 12's preview says so rather than drawing it. Whether a path is free is publish's question.

One draft per entry is a unique index on `(site_id, type, entry_id)`. A global's draft is entry 0,
since a global is the site's one and not addressed by id; a new entry's is null, each its own. SQL
Server counts nulls equal in a unique index, so there it is a filtered one, by statement, where the
other three already let nulls repeat. A save reads the draft plainly, then by its id with a lock,
which on MySQL locks that row and no gap; one not there is inserted in a savepoint, and two first
saves racing are settled by the index as two first saves of a global are, the loser reading the
winner's with a lock and merging into it. So two editors' saves merge rather than one undoing the
other.

Nothing but publish and discard touches a draft. A direct write leaves it as it is, so a field an
import changed and the editor did not keeps the import's value when the draft goes live, and one both
changed takes the editor's. Trashing an entry keeps its draft, and deleting it for good takes it.

**Publishing.** `publish($id)` puts a draft live in one transaction: the draft read with a lock, each
locale it names validated as an update of that locale is -- `required` back, and the path -- and
written through phase 3's write, the outgoing entry filed as a revision, the draft deleted. A new
entry's is a create in its first locale and an update in each other, with nothing outgoing to file.
It is refused with nothing written as a save is: a required field still empty, a path taken, a target
trashed since the draft named it, the entry trashed. A draft holding a change to an internal field is
refused, in access terms that name no field, to a publisher who may not see internal fields. That
transaction is the one place "content changed" is known. Nothing listens to it yet; the deferred
publishing question attaches there.

Phase 3's `create`, `update` and `saveGlobal` go on writing what is live, so the site's seeders and
imports go on changing the site, and each is a publish with no draft before it. Filing is one private
step that publish and these share, once per call, after the call's last locale is written: it files
the entry as it was before the call when a value that was live is replaced. A create has nothing
outgoing; a translation added replaces nothing live, so the seeder's English create and Dutch update
file nothing; and a write that changes nothing files nothing, so a seeder run twice leaves no copies.

**Revisions.** `mainstay_revisions`, a package migration: `site_id`, type, `entry_id`, 0 for a
global, a `json` snapshot, and `created_at`, when the content stopped being live, indexed on
`(site_id, type, entry_id)` so listing and pruning read one entry's rows. A snapshot is the whole
entry as it was live -- shared fields, every locale's localized ones, `template` and its terms -- in
the shape a draft holds, so the two are one format. One publish files one revision, whatever locales
it touched. Past `mainstay.revisions`, 50 unless the host says otherwise, an entry's oldest are
pruned in the transaction that files the newest: ordered by id, since `created_at` is only to the
second, and deleted by primary key, so the delete locks those rows and no gap beside them -- and
needs no subquery on its own table, which MySQL refuses. Both tables join the names a content type
cannot take.

`Mainstay::revisions()->of(Article::class, 5)` lists an entry's, newest first, without their
content. `restore($id)` gives drafts' `save()` every field and locale the snapshot holds, so the draft
becomes the difference from live and leaves through publish with no semantics of its own; fields the
draft already changed that the snapshot does not hold stay changed. It reports what it could not bring
back -- a field the type no longer declares, a value its field now refuses, retyped or a block type
no longer listed -- each left as live has it. A field declared since keeps live's value, the snapshot
having nothing to say about it. An internal field comes back only for a caller who sees it, and is
named in the report, as in a Draft's list of what it changes, only to one.

**Terms are written live.** A taxonomy has no drafts and no revisions: `drafts()` refuses one, naming
`update()`, and writing a term files nothing. A Terms field on an article is the article's, drafted
and filed with it.

**Trash.** `Mainstay::restore(Article::class, 5)` brings an entry out of the trash and builds its
paths again as a save does. A path taken since is the one place a suffix is right: where the pattern
in that locale ends in a Text or Textarea placeholder, the slug, it becomes `launch-2`, then
`launch-3`, the first giving a free path in every locale built from it -- a shared slug in every
locale -- cut short where the suffix would pass the field's `max` or a path's 255 characters, and
restore says which paths it took. Anything else is refused naming the path taken: a route with no
placeholder, one ending in a literal segment or a select, whose options a suffix would leave, and a
path another route or locale answers, which no suffix frees. Slug and path agree afterwards, so the
next save works unchanged, and `Entry::$uri` no longer holds what the pattern would not give.
`Mainstay::media()->restore($id)` brings an image back; its files never went.

`Mainstay::destroy(Article::class, 5)` deletes for good an entry in the trash, and refuses one that is
not: its row, every locale's, its draft, its revisions, and its rows in every taxonomy's pivot; for a
term, the pivot rows pointing at it first, which its foreign key needs gone. What links to it read it
as missing while it was trashed and go on doing so. `Mainstay::media()->destroy($id)` removes a
trashed image's row. Its files stay, the original kept permanently as `decisions.md` has it, so the
same bytes can be uploaded again and find their files written.

**Access.** Gate is asked as on every call, and until phase 9 each of these needs
`overrideAccess: true`. Saving, reading and discarding a draft ask what the write it becomes would:
`create` about the type for a new entry's, `update` about the entry for an existing one's -- neither
is public -- and the revisions ask `update` too. Publishing asks `publish`, so phase 9 can let a
writer draft without publishing, about the entry or, for a new one, the type: `publish(?object $user,
?Entry $entry = null)`, since Gate hands a policy asked about a class the user alone. The trash asks
`restore` and `forceDelete`. `EntryPolicy`, `GlobalPolicy` and `MediaPolicy` grow the methods, each
answering nobody.

**The site.** The seeder writes one article as an editor would -- a draft in English, its Dutch
added, published -- on drafts and revisions, then saves a further draft of it, a new title and a
tag, and leaves it pending; and leaves the next article as a draft only. The site's tests: the
upcoming article's paths 404 in both languages and it is in no listing; the published one serves its
live title, is on no page of the pending tag, and its related cards and listings show it as live; and
every entry visited is still every entry there is.

The check is `DraftTest`, on all four drivers, added to both of CI's test lines beside the suites
each driver's job runs. A new entry's draft saved incomplete -- a required document, date, select and
boolean empty -- unread by `find`, `findByUri` and `paginate`, published into an entry with an id and
a path in two locales and gone; two new drafts of one type side by side; its publish refused with a
required field empty, a path taken and a target trashed, nothing written; a block without its type
refused in a draft. An entry's draft keeping only what differs, a form posting every field included --
a document, blocks and a relation to several types among them, read back and given unchanged -- and
deleted when nothing does; a draft changing only a shared field published; read in each locale as
live with it over, and without its internal fields to a reader who may not see them, who cannot
publish one that changes them;
the site's read unchanged; a direct write to another field between save and publish surviving it, and
to the same field overwritten; two saves from a second connection merging, and two first saves leaving
one draft, inside a caller's transaction and out; publish filing one revision holding both locales.
An update filing the outgoing entry, and a create, a translation added, an unchanged update and a
term's update filing none; pruning past `mainstay.revisions`; restore making a draft of the difference, reporting a field
dropped since and a retyped value, keeping live's for a field added since, and published back. A
global drafted before its first write, published into its row, and filed on `saveGlobal`. Drafts
refused for a taxonomy. Restore writing paths back; a taken path suffixing a localized slug in its
locale and a shared one in every locale, and saying so; a slug at its `max` cut short to fit; a route
with no placeholder, one ending in a select and a path another route answers refused; the
restored entry saving unchanged; a draft kept through trash and restore; an image restored.
`destroy` refused outside the trash, and taking the rows, the locales, the draft, the revisions and the
pivot rows both ways, links reading missing after; an image's row gone and its bytes uploaded again.
Every call refused without the override.

## Phase 9 — Identity

Who is signed in to the admin, and what each of them may do. The query layer has asked `Gate` about
Mainstay's user on every call since phase 3; here that user stops being null on the admin's own
requests, and nowhere else. Accounts and roles are written from PHP and the command line, as content
was before the admin, and phase 11 draws their screens.

**Accounts.** `mainstay_users`, a package migration: a name, an email, a hashed password, the role it
holds, its grants and its denials as JSON lists of capabilities, a remember token, and the timestamps.
An email is stored and looked up lowercased, since its unique index folds case on MySQL and SQL Server
and not on the others. `Mainstay\Auth\User` is an Eloquent model, which a Laravel user provider wants;
content goes on being the query builder's. It is not `Authorizable`, whose `can()` would ask the host's
Gate. The service provider adds a `mainstay` user provider, guard and password broker to `auth`,
unless the host has defined one by that name. Every account holds one role, which the foreign key
keeps from being deleted while it is held, and accounts are installation-wide, as roles are.

`php artisan mainstay:user you@example.com --name="..." --role=administrator` creates an account,
asking for the password twice without echoing it and checking it against `Password::defaults()`,
which the host sets. It refuses an email that has an account, a role that does not exist, and no
role. It is the only way an account is made before phase 11: nothing on the web creates one, so a
new server has no page that whoever reaches it first can claim. There is no registration.

**The session.** The admin's routes stop running the host's `web` group, whose session is a visitor's
on a site with members. They run a stack of Mainstay's own -- cookies encrypted and queued, a session
started as `mainstay_session`, errors shared, CSRF checked -- and `mainstay.middleware` becomes what a
host adds after it, empty by default. The driver, the lifetime, the domain and the rest follow the
host's `session` config. Laravel names its one session store once, from `session.cookie`, and the
guard writes to that store, so Mainstay's `StartSession` renames the store for the request and back
after it rather than starting a second one. The CSRF middleware sets no `XSRF-TOKEN` cookie, which
has one name and path for both sessions; the admin's forms carry the token. Signing in to the host's
site signs nobody in to Mainstay, or out of it.

The stack makes Mainstay's guard the request's default, with `Auth::shouldUse()`, so nothing asks the
host's guard during an admin request: not the database session driver recording who a session
belongs to, which would otherwise sign the host's user in from their remember cookie. Mainstay's own
middleware, not `auth`, turns a guest away -- to `/admin/login`, where `auth` would send them to the
host's `login` route or the application-wide `redirectGuestsTo()` -- and puts the signed-in user on
the request, and the layer's user is read from there and nowhere else. Public pages, the API and the
console read as a visitor with an editor signed in. Asking the guard instead would sign the editor in
on a public page from the remember cookie, sent on every path and decrypted by the host's own
middleware. A signed-in editor is served the page a visitor is, internal fields left out, which is
what a static cache needs and what phase 12's page builder has to change.

Laravel's guard dispatches `Login`, `Authenticated` and `Logout` as it does for any guard, carrying
`guard: 'mainstay'`, which a host listener written for its own users reads.

**Signing in.** `/admin/login` is a Blade view drawn with `packages/ui`'s components, loading the
stylesheet and not the admin bundle. An email, a password and "Remember me", which keeps the user
signed in past the idle timeout with Laravel's remember cookie, until they sign out. A wrong password
and an unknown email are one message, so the form cannot tell which accounts exist; five failures for
an email, lowercased, from one address lock it for a minute, and the message says how long. Signing
in regenerates the session and goes where the guest was turned away from; signing out is a form in
the user menu and invalidates Mainstay's session alone. While no account exists, the page says to run
`mainstay:user`. Laravel's `AuthenticateSession` keeps the password's hash in the session and signs a
session out once it no longer matches, so a changed password ends every other session, the remember
cookie's included.

**Forgotten passwords.** "Forgot your password?" asks for an email and answers alike whether or not
it has an account, and whether or not one was mailed a minute ago. One that does is mailed a link
through the host's mailer to `/admin/reset-password/{token}?email=...`, which takes a new password
twice, rotates the remember token, and sends the user to sign in. The broker's config is Mainstay's,
written out, since only Laravel's skeleton has defaults for it: `mainstay_password_reset_tokens`,
links expiring after an hour, one mailed a minute at most. The notification is Mainstay's own, sent
from `User::sendPasswordResetNotification()`: Laravel's builds its link from
`route('password.reset')`, or from `ResetPassword::createUrlUsing()`, a static the host's users share.

**Capabilities.** Never stored: `Mainstay::capabilities()` computes them from the registered types
each time it is asked. Each type has a set of its own, named from its handle, and every capability
has an all-types form covering every type of that shape, including types deployed later. A role names
a few types or all of them.

| | One type | Every type |
| --- | --- | --- |
| An entry type | `edit_articles`, `edit_published_articles`, `edit_others_articles`, `publish_articles`, `delete_articles`, `delete_others_articles` | the same on `entries` |
| A global | `edit_layout`, `publish_layout` | `edit_globals`, `publish_globals` |
| A taxonomy | `manage_tags` | `manage_terms` |
| The media library | | `upload_media`, `edit_others_media` |
| Accounts and roles | | `manage_users`, first asked by phase 11's screens |

An entry type's and a taxonomy's are plural, by the English inflector whatever language the host
gives `Pluralizer`, which would otherwise rename every capability; a global's is its handle, there
being one per site. Registration refuses a type whose set would claim a name another type's, or an
all-types one, already has -- an entry type `Entry` deriving `edit_entries`, `Media` deriving
`edit_others_media`, a taxonomy `User` deriving `manage_users` -- naming both, as two types of one
handle are refused.

A user holds a capability that is not among their denials and is among their grants or their role's,
or that their role's `*` covers. A check for one type's capability passes on either form, so
`edit_others_articles` is held through `edit_others_entries`, and a denial of either form wins over
both: an editor denied `edit_articles` cannot edit articles although the role holds `edit_entries`.
A role naming a capability no type derives any longer holds nothing by it.

**Roles.** `mainstay_roles`: a unique name and a JSON list of capabilities, the part an administrator
edits. A role shipped with the package can only name the all-types forms, so the migration writes two:
`administrator`, holding `*`, and `editor`, holding every all-types capability but `manage_users`. A
narrower role is a site's own, named from its types.

**Policies** map an ability asked about an entry to the capabilities it needs, as WordPress's
`map_meta_cap` does, each in either form; there is no `Gate::before`. A policy is shared by every type
of its shape and checks the capabilities of the type it is asked about, so a question about a type
rather than an entry -- `create`, `viewInternal`, a global's -- is asked with the type twice, `[$type,
$type]`: Gate takes the first to find the policy and drops it. Someone else's is anything whose owner
is not the caller, no owner included, which is the answer that fails closed.

- `EntryPolicy`: `create` needs `edit`. `update` needs `edit`, `edit_published` for an entry that is
  live, and `edit_others` for someone else's. `publish` needs `publish`, and `edit_others` for someone
  else's. `delete` and `forceDelete` need `delete`, and `delete_others` for someone else's; `restore`
  needs those and `publish`, since it puts the entry back at its paths. `viewInternal` needs `edit`.
- An entry not published yet has an owner only in its draft. A new entry's first save asks `create`;
  every later call on that draft -- reading, saving, discarding, publishing -- asks `update` or
  `publish` about the entry as the draft reads, with no id and the draft's owner. So another's
  unpublished draft needs `edit_others`, internal fields and all, and none needs `edit_published`.
- A direct `create`, `update` or `saveGlobal` is a publish with no draft before it, as phase 8 has it,
  so it asks `publish` too. Otherwise a role that may draft and not publish could put content live by
  calling one.
- `GlobalPolicy`: `update` needs `edit`, `publish` needs `publish`, `viewInternal` needs `edit`.
- `TermPolicy`, new, for a taxonomy: every ability, `publish` and `viewInternal` among them, needs
  `manage`. Terms have owners and nothing asks about them.
- `MediaPolicy`: every write needs `upload_media`, and `edit_others_media` for someone else's image.

`viewAny` stays open until phase 13, and the revisions go on asking `update`. Without a user each
policy answers as it does now: reading open, internal fields absent, writes refused naming
`overrideAccess`. A user without a capability is refused naming it: "Publishing this needs
publish_articles."

**Mainstay's Gate.** `Gate::forUser()` copies the host's `before` and `after` callbacks, and Laravel
calls them with any user that is not null, so a host's `Gate::before(fn (User $user) => ...)`, typed
for its own users, would be handed Mainstay's and throw. Mainstay's gate is a subclass of Laravel's,
built from the host's policies and its default denial, which only a subclass can read, without the
callbacks, and without guessing a policy by name as now. The layer and the media library take it from
one place, where each builds its own today.

**Owners.** `owner_id` is filled from here. A create is owned by the user making it, an upload by
the uploader, and a new entry's draft by who started it, as is the entry it publishes into, whoever
publishes it. A global has no owner. Nothing changes an owner yet; that is phase 11's, where
accounts are listed. Every row written before this phase has none, and so has every row code writes
on its own authority with no user signed in. `mainstay_drafts` and `mainstay_media` gain `owner_id`
in their own create migrations, which nothing released has run. Revisions gain no author: who a
history screen names, whoever published a version or whoever replaced it, is phase 10's to decide
beside that screen. `owner_id` takes no foreign key. A content table's would make the users table a
precondition of every sync and a line of every host's migrations, and the id a deleted account
leaves behind belongs to nobody, which reads as someone else's.

`mainstay_users`, `mainstay_roles` and `mainstay_password_reset_tokens` join the names a content type
cannot take.

**The admin.** Every admin path needs a signed-in user. The shell's user menu shows their name and
email and signs them out; "Account settings" waits for phase 11. That is all the admin draws.

**The site.** Its `config/mainstay.php` drops `web` from the admin's middleware, which would now run
a second time after Mainstay's stack and blank every cookie the first decrypted. The seeder makes an
administrator from `MAINSTAY_ADMIN_EMAIL` and `MAINSTAY_ADMIN_PASSWORD` when `.env` sets them, so
`composer content` leaves a way in, and a Writer role holding Article's six capabilities and nothing
else. A phase 9 article, and a "Users and roles" docs page. The site's tests: signing in over HTTP and
reaching the admin; a Writer drafting and publishing an article and refused a page and the layout; a
signed-in editor's public page a visitor's.

The check is `IdentityTest`, on all four drivers, added to both of CI's test lines beside the suites
each driver's job runs. A request reaching the layer as a user goes through a test route on the
admin's stack, and a test making several requests forgets the guards between them, which keep the
user they found otherwise.

- Capabilities: a fixture type's in both forms, a global's and a taxonomy's; registration refusing
  `Entry`, `Media` and a taxonomy `User`.
- Holding: by role, by `*` and by grant, and a denial beating the role, `*` and the other form.
- The mapping against an entry the user owns, one someone else owns and one nobody owns, which fails
  closed. A role of `edit` alone drafting a new entry and saving it again, and refused someone else's
  new draft, reading it included, a change to a live entry, a direct create, a publish and a restore.
  A role of one type's capabilities refused another type, a global of its own allowed and another
  refused, and an article's internal fields shown and a page's not. A term written with `manage` and
  refused without; another's image refused without `edit_others_media`. The editor role doing all of it
  and not holding `manage_users`.
- Owners: a create by a signed-in user, a published draft owned by who started it and not who
  published it, an upload, a seeder's write with none.
- The layer writing as the request's user with no override; a public page read with an editor signed
  in and the remember cookie sent, its internal field absent; a host's `Gate::before` typed for its own
  user never called.
- Signing in: a wrong password and an unknown email answered alike, the sixth attempt locked whatever
  the email's case, the session regenerated as `mainstay_session` with the host's untouched, remember
  me restoring a session with the session cookie dropped, signing out, and a guest sent to Mainstay's
  login on an app with no `login` route.
- Resetting: the link mailed with Mainstay's URL and the email, an unknown email and a throttled one
  answered alike, the password changed through the token and another live session signed out, a used
  or expired token refused.
- `mainstay:user` creating, and refusing a taken email, an unknown role and none; the login page naming
  it while no account exists. The two shipped roles after a fresh migrate.

## Phase 10 — The admin

Everything the phases before this built from code, drawn as screens an editor works in: a sidebar
from the registry, a list of each type, a form of each entry in every language, and buttons over
phase 8's drafts, publishing, trash and revisions. Every screen is a transport over the query layer,
as phase 3 has it: it validates what a seeder's write validates, is refused what Gate refuses, and
adds no rule of its own. Screens are Blade, drawn with `packages/ui`'s components; what they do in
the browser is the behaviour layer's `mount()`.

It is four pull requests, each merged on its own and each leaving an admin that works: 10a the
frame, the lists and the scalar fields; 10b rich text and the raw blocks field; 10c media, relations
and terms; 10d history. Accounts, roles and account settings are phase 11. A page's content is edited
on the site itself by phase 12's page builder, so this phase draws no block list and no preview.

### 10a — The frame, the lists and the scalar fields

**Every entry has a title.** `Entry` declares `#[Text(required: true, localized: true)] public
string $title`, as WordPress gives every post one, so every entry type and every taxonomy's terms
hold it without declaring it, first in the field list, since reflection files a base's fields first.
A type redeclares `$title` to change it -- a longer `max`, one title for every language -- and the
redeclaration keeps the base's position and takes the child's attribute, which reflection already
does. It stays a `string`, since PHP holds a redeclared property to its parent's type, so a title
can be longer or shared but never optional: `Pictured`, `Lost` and `Linked/Review`, which declare
`?string $title`, change. The admin names an entry by its title and nothing else: a list's rows, a
picker's options, the breadcrumb, the browser's tab; an empty one reads "Untitled". Globals and
blocks have none. Fixtures that declare no title get one from the base, and every test writing them
names one.

**Navigation.** `Navigation::sections()` is built from the registry instead of written by hand:
Dashboard; Content, each entry type and then each taxonomy, in registration order; Media, from 10c;
and Globals, each global, a section left out when there are none. A type is named from its class,
headlined and pluralized by the English inflector as its capabilities are -- Pages, Articles, Docs,
Tags -- and a global in the singular. The sidebar offers a type only to someone who may write to it
(`create` for an entry type or a taxonomy, `update` for a global, each asked of Gate with the type,
as the layer asks), and its screens refuse anyone else; reading stays open to the layer, but a list
nobody may change is not one to offer. "Content types", "Users" and "Settings" go; Users returns in
phase 11. The top bar carries the logo, the command centre fed the sidebar's links and a "New ..."
for each type, "Visit website", and the user menu. The disabled Publish button, the status line and
the fetch of the API's root go: nothing is built on an API route being reachable until phase 13. The
shell, top bar and pager exist only as Storybook fixtures under `stories/blade`; they move into
`packages/ui`'s components, so Storybook shows what ships and the screens are built from them. The
list's table stays the screen's own, its columns and row menus being the admin's.

**Addresses.** Every admin path is under the type's handle, as `ContentType::handle()` says it names
one: `/admin/article` lists, `/admin/article/new`, `/admin/article/5`, `/admin/article/drafts/12` for
an entry not yet published, `/admin/article/trash`, and `/admin/layout` for a global. Every route is
named `mainstay.*`, and a path under the prefix that none answers is a 404 drawn in the shell, so the
prefix stays the admin's and `Mainstay::shadow()` goes on refusing an entry a path inside it.
Registration refuses a type whose handle is a segment the admin uses for itself -- `media`, `login`,
`logout`, `forgot-password`, `reset-password` -- naming it, as it refuses two types of one handle.

**Languages.** A list and a form take `?locale=`, the default when absent, with a toggle between the
configured locales above both. This is the switcher phase 14 had; the rest of that phase stays there.
The form in a locale reads that locale's row: translated fields show its text, shared ones the same in
every locale and marked as shared. An entry with no row in the locale opens with its shared fields and
empty translated ones and says it has no version in that language yet; saving adds one, as a draft
saved with a locale does. The list reads the type in every locale and shows each entry once, in the
chosen locale or, marked as missing there, in the first it has.

**The list.** A list per entry type and taxonomy: title, status, author and last change, searched by
title and author, sorted by any column, filtered by status and paged -- the screen
`stories/blade/components/page-list.blade.php` already draws, over `EntryList::shape()`. Its rows
are the type read in every locale at depth 0, and every draft of the type, from a new
`drafts()->all(Article::class, locale:)`, the one listing of drafts there is, holding only those the
caller may `update`, as reading one draft asks: an entry with a draft is "Changed", one without
"Published", a draft with no entry "Draft". A row is keyed by its admin address, which a draft with
no entry has where it has no path, and an entry nobody owns reads its author as a dash, since
`shape()` searches strings. The selection's one bulk action is Move to trash; a row's menu has Edit,
View for the live page, and Move to trash, or Edit and Discard draft for a draft with no entry, each
shown to whoever Gate allows it. The trash, `/admin/article/trash`, lists what `find(..., trashed:
true)` reads -- a new named argument reading the trash alone, the way phase 3 adds them -- with
Restore, saying which suffixed path a restore took, and Delete for good, which asks first.

ponytail: the list reads the whole type into memory to shape it, which holds to a few thousand
entries. Past that it is `paginate()`, and a `like` operator the layer does not have yet.

A question is a `<dialog>`: `packages/ui` gains a dialog component over the native element, opened by
a `[data-dialog-open]` control and closed by its own button and Escape, and the behaviour layer a
module for it. A dialog is drawn outside the entry's form, since a form inside a form is dropped by
the parser and its inputs join the outer one, and what it sends it sends with `fetch`.

**The form.** Fields in declared order, each drawn by the Blade component `$field->component()` names
-- `mainstay::fields.text` -- an anonymous component in `packages/cms/resources/views/components/fields`
handed the field, its value, its input name and its errors. A host's field type is drawn by writing the
file its own `component()` names, the extension point phase 1 promised, and one with no such file is
refused naming the path looked for. 10a draws Text, Textarea, Number, Boolean, Date and Select:

- Text an input held to its `max` by the browser, Textarea a textarea, Number a number input with its `min` and
  `max`, Select a select of its options, Boolean a checkbox posting `1` beside a hidden `0`, as
  `Boolean.php` expects.
- Date a date input. One with `time` is a `datetime-local` the behaviour layer fills from the stored
  UTC instant in the browser's zone and posts with the browser's offset, which `Date::from()` already
  honours, so an editor in Amsterdam types Amsterdam time; without JavaScript it shows and takes UTC,
  and says so.
- The slug -- the field a route's last placeholder names, when it is a Text field -- follows the title
  as it is typed: lowercased, accents dropped, anything else a hyphen, which `Route::SLUG` accepts.
  It stops once it is typed in by hand, and never starts on an entry already published, whose
  address a title change must not move.
- A template select when the type names more than one view.
- `#[Internal]` fields for whoever may see them, which the layer's read already decides.

A field a later milestone draws shows its label and that it is not editable here yet, and posts
nothing, so a save leaves it as it is: a draft holds only the keys it is given. The status and the
buttons sit in the shell's bar, and the form's rail holds the entry's address in each language
linking to the live page, the template, and the author, shown and not changed, since changing it
needs the accounts phase 11 lists.

The contract's interface half settles here. Beside `component()` and `label()`, `fromForm(mixed
$posted): mixed` turns what a field's component posted into what a write takes: the null the host's
`ConvertEmptyStringsToNull` makes of an empty input back into the field's empty value where it has
one, a number's text into a number, JSON into an array for the fields that post it, and a list's
leading empty value dropped. That value is how a list says it is empty: rows post `name[]`, so a list
whose last row was removed would post nothing and be left as it was, and each list field posts an
empty `name[]` before its rows. It is the identity by default, so a host's type overrides it only
where its component posts something else.

**Saving and publishing.** Two buttons. Save draft saves the form as the entry's draft. Publish saves
it and publishes the draft in the same request, and a publish refused -- a required field empty, a
path taken -- leaves the draft saved and shows why. Someone who may not publish sees Save draft
alone. A live entry with a draft says it has unpublished changes and offers Publish and Discard
draft, which asks first. `EntryForm::saveState()` answers from what the server knows -- whether
there is a draft, whether the user may publish -- and `dirty-form.ts` sets `data-dirty` on the form as
it changes, which the buttons' emphasis follows in CSS: Save draft is the primary button while there
are unsaved edits, Publish once there are none. A form changes by script too -- an editor, a reorder,
a pick, a focal point -- so every behaviour module that writes a control dispatches `input` after it,
which `dirty-form.ts` listens for. The client-side Discard, which restored hidden inputs and not tag
chips, goes; leaving with unsaved edits still warns.

A form posts what it drew, and a draft saved with a field equal to live drops that field from the
draft. So a second editor's form, opened before the first saved, would post the old title and undo
the first editor's change. Each drawn field carries a fingerprint of the value it was drawn with, its
`serialize()`d form, and a field whose posted value, through `fromForm()` and `serialize()`, matches it
is left out of the save -- so a date-time posted back with an offset, or a string the host's
middleware trimmed, is unchanged. Two editors changing different fields both keep their change, as
phase 8's merging saves intend, and one field changed by both takes the later.

Publish and Discard draft act on the whole draft, so the form also carries when the draft was last
saved. When another save has moved it since, the form's own changes are saved and merged as always,
but the publish or the discard is refused, and the form is drawn again with the draft as it now is
for the editor to look at first.

**Terms and globals.** A term's form has one button, Save, writing live, as phase 8 writes terms: no
draft, no history, no publish. A global's form is an entry's without a list, a trash or an address,
opened from the sidebar, and is drafted and published as an entry is.

**The front page.** The site records which entry is its front page, as WordPress's "a static page"
does: `front_type` and `front_id` on `sites`, in its create migration, which nothing released has
run. That entry's path is `/` in every locale it has, in place of what its pattern builds, and since
`wanted()` builds every path, a publish, a restore or a translation added keeps it there; a link
through `Mainstay::url()` follows. `Mainstay::frontPage()` reads it as a type and id, or null.
`Mainstay::setFrontPage(Page::class, 5)` sets it, rebuilding both entries' paths in one transaction,
and is refused while something else answers `/` in a locale the entry has; `setFrontPage(null)`
clears it. The old entry takes its pattern's path again, which another entry may have taken while it
was free: there it is suffixed as a restore suffixes, or, where no suffix frees one, the change is
refused naming the path. Only a published entry of a routed type
can be the front page, setting it asks `publish` about the entries it moves, and the front page is
refused the trash until another is chosen. In the admin, "Use as front page" sits in a published
entry's menu, and the list marks the one that is.

**Dashboard.** `/admin` is the shell with nothing in it. Widgets, as WordPress has them, are phase
15's, onboarding's checklist the first of them.

**The site.** Home, Blog and DocsIndex become pages, edited as any page is, with no template to
choose. Page gains Home's blocks field beside its body, and the blog's and the docs' listings become
blocks: an article list, paged, and the docs by section. The three types and their views go, Layout's
menu points at pages, and the seeder writes the home, blog and docs pages and makes the home page the
front page. Every type drops its `title` line for the base's. "An admin to write in", the draft-only
article phase 9 left, is written and published, and a docs page "The admin" starts, growing a section
with each milestone. The site's tests: `/` serves the front page in both languages, and its own slug
does not; the blog page lists the articles in both.

**The check** is `AdminTest`, run by the main PHP job: the layer under it runs on every driver in
its own suites, and what this milestone adds to the layer -- the title, `trashed`, `drafts()->all()`,
the front page's paths -- is checked in `ContentTest`, `DraftTest` and `RenderTest`, which do.

- A round trip: an article created through the admin in English, its Dutch added from the toggle,
  published, and read back through the layer in both, with every scalar field and a date-time posted
  with an offset and stored in UTC.
- Save draft leaving the site as it was, Publish putting it live, a refused publish keeping the draft,
  Discard draft. Two forms opened on one draft changing different fields, both changes kept.
- The list's statuses, search and paging, an untranslated entry marked; trash, a restore reporting its
  suffix, delete for good.
- A Writer offered Articles and not Pages or Layout, refused a page's form, and shown no Publish
  without `publish`.
- A term saved live; a global drafted and published.
- The front page at `/` in both locales, refused while `/` is taken, its predecessor back at its own
  path, suffixed when that was taken meanwhile, and refused the trash.
- A date-time left as it was in one form while another form changes it, the change kept; a publish
  refused after another editor's save, the save kept.
- A handle the admin uses refused at registration.
- A field type whose component file is missing refused naming the path.

Vitest covers the slug following and stopping, the local-time field, the dialog and the form marking
itself dirty. The comments in `src` that name phase 11 for the API and phase 12 for the second site
are renumbered with the plan.

### 10b — Rich text, and the raw blocks field

**The editor without React.** The island becomes `editor(element)`, mounting a ProseMirror view on a
field's element and writing the document's JSON into the field's hidden input on every change, so the
form posts it, `FormData` sees it and the unsaved-changes warning covers it. Until something changes
the input holds what the server drew, so an untouched editor posts its fingerprint back, and an
editor emptied to one blank paragraph posts nothing, which is no document. React rendered one `<div>`
for ProseMirror to own and gave nothing else, so `editors()` mounts one per field from the admin's
script beside the behaviour layer's `mount()`, idempotently as every module is, and `react`,
`react-dom` and the React plugin leave the editor, the admin bundle and the root. The committed
bundle drops from 409 to 229 KB.

- A toolbar above the text: paragraph or heading 2 to 4, bold, italic, code, link, bullet and
  numbered list, quote, code block, rule -- the schema's nodes and marks and no more -- each showing
  when it is active, with the usual keys for the marks, Mod-k for a link and Shift-Enter for a line
  break.
- A link asks for its address in a dialog, checked by the schema's `linkable()` before the mark is
  set; on a link the same dialog edits or removes all of it, and with nothing selected it inserts
  the address as the link's text. The dialog is drawn once, pushed onto a `dialogs` stack the shell
  draws outside the entry's form, so its own form can submit.
- Pasted HTML is parsed through the schema, so what it does not know falls away, as the blocks
  decision foresaw.
- Enter splits a list item, and Mod-] and Mod-[ indent and outdent it, leaving Tab to move focus,
  from `prosemirror-schema-list`, ProseMirror's own package for it and a dependency this milestone
  adds.

The RichText component is the hidden input holding the document, the toolbar and the element the
editor mounts on. `Field::fromForm()` decodes the JSON for every field kept as JSON, a blank one
as null, and refuses JSON that does not parse with the parser's message. Without JavaScript the
document shows rendered and read-only, and posts unchanged.

**The raw blocks field.** A Blocks field is the page builder's, edited on the site from phase 12. In
the admin it is a debug field, for repairing what the page builder writes when the site's editor
breaks: the field's stored JSON, pretty-printed, in a monospace textarea under a "Raw content"
disclosure. `fromForm()` decodes it and the save validates it as any write, so JSON that does not
parse is refused with the parser's message, and a wrong block with the paths phase 5's rules give,
`blocks.1.data.title`, listed above the textarea, which the form now does for any error under a
field's name. Whoever may edit the entry sees it.

**The site.** "Writing in the admin", the draft 10a left, is written and published, the next article
waits as a draft, and "The admin" gains its rich text and raw content section.

**The check.** `AdminTest`: a document posted and read back as the layer stores it, one outside the
schema refused naming the node; blocks posted as JSON and read back, JSON that does not parse
refused, a nested block's error listed at its path. Vitest: the fixture document opened and written
back unchanged, as the schema's test round-trips it; a change written into the input; the toolbar's
commands and a list split on Enter; a link `linkable()` refuses. The bundle is rebuilt and committed,
which CI's "Admin bundle is up to date" holds.

### 10c — Media, relations and terms

**The library.** `/admin/media`: the images in a grid, newest first, paged by a new
`media()->paginate()`, with the trash as `paginate(trashed: true)`. The original is never served,
so the library writes one more copy of every image whatever the site declares, `Library::preview()`,
640 pixels wide and scaled only: what the grid, the picker and an image field show. Uploading is a
dialog of the file and its alt text in every locale, each present as `upload()` requires, an empty
one marking the image decorative -- the null `ConvertEmptyStringsToNull` makes of it turned back into
the empty string the library takes; a file dropped on the grid opens it. An image's page edits its
alt text in each locale and its focal point, set by clicking the image, a behaviour module writing
the two percentages into two number inputs, which can be typed in too; Save, Move to trash, Restore
and Delete for good as on entries, each shown to whoever the library would let use it. Each write asks Gate through the library as now, `upload_media` and
`edit_others_media` for someone else's. The sidebar gains Media for whoever may read the library --
`viewAny`, the question its every read asks, so the sidebar, the screens and phase 13's API agree --
and Upload shows for whoever may upload.

**The image field.** A thumbnail with Choose, Replace and Remove, posting the image's id. Choose opens
the dialog with the library's grid, fetched as HTML from the library's own route with `?pick`,
uploading included, sent with `fetch` since the dialog sits outside the entry's form; picking sets the
id and the thumbnail, and an upload from the picker picks what it uploaded. A missing image shows as
missing, as a read gives it, and is kept until it is replaced or removed.

**Relations.** The chosen entries as a list -- the title, and the type where there are several -- with
remove, drag to reorder, the platform's `draggable` and an `insertBefore`, and move up and down for
the keyboard. Each row carries one hidden input, `related[]`, holding the id, or `type:id` where the
field points at several types, so the posted order is the rows' order and nothing is renumbered.
Adding opens a search: a field fetching rows of matching titles across the target types in the form's
locale, as HTML from `/admin/article/relations/related`, and a pick appends a row, or replaces the one
row of a single relation; Enter in the search picks the first entry found for what is typed, and
only the newest search's answer is shown. `fromForm()` turns `type:id` back into
the `{type, id}` a write takes. The form reads its entry at depth 1, so the rows have their titles.

ponytail: the search reads the target types into memory and matches titles, as the list does.

**Terms.** The tag input `packages/ui` has, suggesting the taxonomy's terms in the form's locale from a
`<datalist>`. A chip whose text matches a term's title, in any case, posts `id:5`; one matching none
posts `new:` and its text, so a tag called "2026" is not read as an id. Saving creates each new one:
a term of that title, written in every locale alike, so a tag created in Dutch exists in English
under the same name until someone translates it, and its slug -- the field the taxonomy's route ends
in, when it has one -- what `Str::slug` writes, a title that gives an empty one refused naming the
tag. A slug a term already has in a locale is that term, and the ids are made unique after, so a tag
both picked and typed is one term rather than a refused repeat. Creating is part of the save's
transaction, so a save refused creates nothing, and it needs `manage`, without which the field is
refused naming the capability.

**The site.** "Images and tags in the admin", the draft 10b left, is written and published, the next
article waits as a draft, and "The admin" gains its media section.

**The check.** `AdminTest`: an image uploaded with its alt text in both languages, picked as a cover
and read back; a decorative one uploaded with an empty alt; one uploaded from the picker; a focal
point saved; the library's trash. Relations posted in an order and read back in it, several types
among them, and the last one removed leaving none. A term picked by id, one created by text and
present in both locales, one both picked and typed saved once, a numeric title, a refused save
creating none, creating refused without `manage`, and the last tag removed. Vitest: a relation
reordered by drag and by the buttons, its inputs in the new order; a typed tag matched to its term;
the focal point; the picker fetched into the dialog.

### 10d — History

Each version records who published it. A revision is filed only when a write replaces something live,
so a create files none, and nor does a translation added: who replaced a revision cannot name an
entry published once. So every main table gains `published_by`, as phase 3 gave them `owner_id`
before anything filled it: the user whose publish or write put the live version there, null for code
writing on its own authority, with no foreign key, as `owner_id` has none, and joining the reserved
names. A write that changes what is live sets it, a translation added included, and a write that
changes nothing leaves it. Filing copies it into the revision beside the snapshot, as `published_by`
on `mainstay_revisions`, in its create migration. So the live version names its publisher from its
row and each revision from its own; rows written before this phase name nobody. `ContentType` carries
it as `$publishedBy`, beside `$ownerId`, and `Revision` as well.

`/admin/article/5/history`, linked from the form's rail, and `/admin/layout/history` for a global: the
live version and then each revision, newest first, each with when it was live -- the live one since
it replaced the version before, or since the entry was first written, a revision until it was
replaced -- and who published it -- an account's name, "a script" for none, "a deleted account" for
an id no account has -- and Restore, which makes it the entry's draft through
`revisions()->restore()` and opens the form saying what it could not bring back. Restore is posted
under the entry it is of, `/admin/article/5/revisions/9`, and any other revision is not found there.
A term keeps no history, so has none. A restore from the trash that moves a slug to a free path is a
write like any other: it names who restored it and files the version it moved.

**The site.** "History in the admin", the draft 10c left, is written and published, the next
article, on phase 11, waits as a draft, and "The admin" gains its last section.

**The check.** `AdminTest`: an entry published once naming its publisher; two publishes by two users
named in order; a translation added by a third naming them on the live version; a seeder's write as
a script; a restore opening the draft and reporting a field dropped since. The column's writes are
checked in `DraftTest`, on every driver.

## Phase 11 — Accounts in the admin

The screens phase 9 left: accounts, created and given a role by someone holding `manage_users`; roles
and the capabilities each holds, from `Mainstay::capabilities()`; and "Account settings", where a user
changes their own name, email and password. An entry's author is changed in its form, from the
accounts that may write its type. One pull request.

**The layer.** Accounts and roles are written through `Mainstay::accounts()`, as images are through
`media()`: `create()`, `update()`, `invite()` and `delete()` for an account, `saveRole()` and
`deleteRole()` for a role, each asking Mainstay's gate first and skipping it for `overrideAccess`. The
screens are a transport over it, as over the query layer. `mainstay:user` stays as it is, the way in
before any account exists.

**Who may.** `AccountPolicy`, for accounts and roles alike. Every ability needs `manage_users` but a
user's own name, email and password, and nobody hands out what they do not hold themselves. A user
covers a name of `Mainstay::capabilities()` when holding it would pass every check it can pass: a
type's own name through `holds(own, every)`, as the policies ask; an all-types name through
`holds(every)` with no type's own form of it denied, since a role giving `edit_entries` edits every
type; a shared one through `holds()`; and `*` only through a role of `*` with no denials, `*` being a
role's and never a grant's. A name no type derives any longer gives nothing and is not counted. So a
role is saved only by someone covering what it held and what it will hold, and deleted only by
someone covering it; an account is created with, or moved to, a role only by someone covering that
role; and an account is changed, invited again or deleted only by someone covering its role and its
grants. An account that holds `manage_users` and not `*` cannot make an administrator, change one's
email and reset its password from the mail, or edit the administrator role. Nobody deletes their own
account. A user changes their own name freely and their email and password with their current
password, whatever they hold, and their own role only as anyone else's, by covering it.

**What the layer keeps.** An email is unique lowercased, as phase 9 stores it, and so is a role's
name, for the same reason: the unique index folds case on MySQL and SQL Server and not on the others.
A role held by an account is not deleted, the message naming how many hold it, rather than the foreign
key's error. A write made by a user, not `overrideAccess`, that takes away the last account able to
manage accounts is refused -- one holding `manage_users` with a password, an invited one counting for
nothing until it signs in -- so the last administrator cannot demote or delete themselves or empty the
role they hold. Every account and role write runs in a transaction that first reads every role and
account with `lockForUpdate()`, asks the policy of the caller as those rows have them, writes, and
checks that last rule against them, so two administrators demoting each other at once cannot both
pass, and a caller's own transaction's older snapshot is never what decides. A server left with
none gets one from `mainstay:user`.

**The first password.** An account made in the admin has none: `password` becomes nullable in the
users table's create migration, which nothing released has run. The provider refuses a null password
before hashing anything, so an invited account signs in as nothing. `create()` makes it and
`invite()` mails it an `Invitation` through the host's mailer, naming who made it, with a token from
the `mainstay` broker's `createToken()` and a link to the reset page, which reads "Choose a password"
for both; the link expires after the broker's hour, and "Forgot your password?" works for an invited
account as for any other. The screen invites after the account is committed, so a mailer that fails
leaves the account made and says so on its page, where "Send the invitation again" waits while it has
no password; that is not throttled, being an administrator's and not a stranger's.

**The accounts.** `/admin/users` lists every account to whoever may see them: name, email, role, and
"Invited" for one without a password, sorted by name and not paged.

ponytail: one page of every account, which holds to a few hundred; past that it pages as the media
grid does.

`/admin/users/new` takes a name, an email and a role, offering the roles the user covers.
`/admin/users/5` is the same form, with the account's grants and denials listed beneath its role,
since changing them stays in code; read only, with no Save, for an account the user does not cover,
as an image is for whoever may not change it. A user's own sends them to Account settings, where
changing the email asks for the password. Delete asks first and erases the account: its sessions
end at their next request, its entries keep an owner no account has, which reads as nobody, as phase
9 has it, and their history names "a deleted account".

**The roles.** `/admin/roles` lists each role and how many accounts hold it. `/admin/roles/new` and
`/admin/roles/2` take a name and the capabilities, as a grid over `Mainstay::capabilities()`:
"Everything" for `*`; a row for every entry type and one per entry type, with a column per verb;
the same for taxonomies and for globals; the media library's two; and `manage_users`. A name the role
holds and no type derives any longer is listed below, checked, so it can be unchecked; one the user
does not hold is drawn disabled. Delete shows only for a role nobody holds.

**Account settings.** `/admin/account`, from the user menu, for everyone signed in: their name, email
and a new password twice, the current password asked for when the email or the password changes,
through `accounts()->update()` as the account itself. A new password rotates the remember token, as a
reset does, so another device's remember cookie signs nobody in, whether through `AuthenticateSession`
or through the login page, which runs outside it and would otherwise take the old cookie; then the
guard's `logoutOtherDevices()` sends this device a cookie with the new token, keeping it signed in.
The password rule is `Password::defaults()`, as everywhere.

**The author.** The form's Author becomes a menu for whoever Gate allows `assign`, which
`EntryPolicy` answers with what `update` needs and `edit_others`: giving an entry away is changing
someone else's. A term's owner is nothing anyone asks about, so its Author stays as it is. The menu
offers the accounts Gate would let `create` the type, as the sidebar asks, and posts a form of its
own, outside the entry's, taking effect at once, as the front page does -- the form's unsaved-changes
guard asks before an edit is left behind: `Mainstay::assign(Article::class, 5, owner: 3)` for an entry
and `drafts()->assign(12, owner: 3)` for one not published yet, each refusing an owner who could not
write the type. It writes `owner_id` alone: no revision, and no `updated_at`, which an open form
compares to tell another's save. A draft not published reads its owner from the draft, which it does
not yet -- it reads the live row there is none of, so the list and the form name nobody for it.

**Navigation.** Users and Roles, a section after Globals, for whoever `AccountPolicy` lets see
accounts, and the command centre with them; "Account settings" in the user menu. Registration refuses
the handles `users`, `roles` and `account`, as it refuses `media`.

**The site.** The phase 11 article, waiting as a draft, is written and published, and the next, on
phase 12, waits as one. "Users and roles" says how accounts are made and changed in the admin, and
"The admin" gains a section. The site's test: an administrator invites a writer, who chooses a
password from the mailed link and signs in.

**The check.** `AccountTest`, on all four drivers, added to both of CI's test lines.

- Creating an account and inviting it: no password, the mail sent with a working link, signing in
  refused until it is chosen, sent again while invited, and "Forgot your password?" working for it.
- Emails and role names unique whatever their case; a held role not deleted, naming the count.
- Covering: a user with `manage_users` and not `*` refused an administrator's account, the
  administrator role, a role naming what they lack, and giving one; allowed one they cover, a type's
  own name through its all-types form included. An editor denied `edit_notes` refused `edit_entries`
  and `edit_notes` both. A name no type derives not counted against them. Nobody deleting themselves.
- The last account able to manage accounts not demoted or deleted, nor its role emptied; an invited
  administrator not counting. Two administrators demoting each other from two connections leaving
  one, and a caller's transaction holding an older snapshot not deciding it.
- Account settings: a writer changing their own name, and their email and password only with the
  current one; a new password signing out another device, its session cookie dropped and its remember
  cookie sent to the login page, and keeping this one and its remember cookie.
- Assigning: an entry and a draft given to another account, the draft's owner read back before and
  after; refused without `edit_others`, to an account that cannot write the type, and to someone
  without `update`; the draft's `updated_at` and the entry's revisions untouched.
- The screens: the sidebar's section only for `manage_users`; the list, the forms read only where not
  covered, the grid's disabled and stale names, the author menu only where `assign` is allowed. Those
  go in `AdminTest`, on SQLite.

## Phase 12 — The page builder

An entry's content, edited in the admin on the page the site draws from it. Every entry has content,
as every WordPress post has `post_content`: a document whose top-level nodes are its blocks,
Mainstay's text blocks and the site's own. The edit screen is two tabs. Details is phase 10's form,
the title first. Content is the page the site's view renders from the form as it stands -- the
layout, the header, the fields drawn around the content -- with the content edited where the view
prints it: text typed in place, a site block drawn by its own view with its text typed in place too
and every field of it in a sidebar. Nothing previews beside it: the Content tab is the preview.

It reverses two things in `decisions.md`: "Notion-style inline block editing is off the table", with
preview beside the editing surface, and the form-based list of blocks. It keeps what the rendering
entry wanted from them: a block is written once, in Blade, and the admin never draws it a second
way; the page is a frame of what the site renders. The stub's third reversal goes. Editing stays in
the admin, so a signed-in editor is still served the public page a visitor is, and nothing on a
public path reads a draft.

It is three pull requests, each merged on its own and each leaving an admin that works: 12a content
on every entry, drawn on the site and kept in Details as raw data; 12b the Content tab, with the text
blocks; 12c the site's blocks in it.

### 12a — Content on every entry

**Every entry has content.** `Entry` declares `#[Content(localized: true)] public ?Document
$content` after the title, so every entry type and every taxonomy's terms hold it without declaring
it, as they hold the title. Whether a view prints it is the site's. A type redeclares it only to keep
one content for every language, as a title can be; a redeclaration as any other field type is refused
at registration, since the edit screen is built on it. Globals and blocks have none. It is a `json`
column on each type's `_locales` table, which sync adds as it adds any field, and an empty content is
null, as an empty rich text is.

**The blocks a type allows.** `#[Blocks([Hero::class, FeatureGrid::class])]` on the type's class
names the site blocks its content may hold; a type naming none holds text alone. It is the attribute a
block's own list is declared with, now allowed on a class, where `of` is all it takes: `required`,
`localized` and the rest are refused there. `of` becomes its first parameter, so the list is written
without its name, where today the array would land on `required`. On a property it is allowed inside a block only, a list of
items as FeatureGrid's Features are. On an entry type or a global it is refused, naming content as
where an entry's blocks go. A type with no `#[Route]` has no page to edit blocks on, so `#[Blocks]` on
one is refused as well.

**The content's shape.** A document: RichText's schema with one node more, and everything at its top
level a block. Mainstay's own blocks are the nodes RichText has -- paragraph, heading, quote, code
block, the two lists and the rule -- while bold, italic, code and a link stay marks inside their text.
A heading takes level 1 to 6, no longer 2 to 4, in content and in every RichText field, the schema
being one: a page whose view leaves out the title can take its h1 from its content. The toolbar offers
the six, a pasted `h1` stays an h1, and the site's CSS styles the levels it does not yet.
A site block is a `block` node, `{type: 'block', attrs: {id, type: 'hero', data: {...}}}`, allowed at
the top level only, never inside a quote or a list item. Its `id`, `type` and `data` are a Blocks
item's, checked as phase 5 checks one: the type among the type's blocks, the id distinct, the data
against the block's own fields, each error keyed by its place, `content.content.3.attrs.data.headline`.
Read, its data becomes the Block, its images and relations loaded to the read's depth as a Blocks
field's are, which the field's `references()` reports, and `$entry->content?->blocks()` lists the site
blocks a content holds. The editor gives a block its id when it is inserted, duplicated or pasted, a
nested item's too, and the layer keeps an id it is given, so a block drawn in the page and the block in
the document are one; `Blocks::from()` minting an id on every read of one without stays the seeder's
case.

**Drawn on the site.** `{{ $entry->content }}` prints the blocks in order with nothing around them:
the text as `Document` renders it now, a site block through its view, `blocks.hero` with the block as
`$block`. A view prints it alone in an element of its own, which is what 12b's editor takes over, as
the site's `<div class="prose">{{ $entry->body }}</div>` already does.

**Drafts, revisions and the form.** Nothing new. Content is a field, so a draft holds it when it
changed, a revision holds it whole, and the form's fingerprint leaves it out when it comes back
unchanged.

**In Details.** The form draws content after the title. A type with a page shows it as raw data: 10b's
"Raw content" disclosure moves from the Blocks field to the Content field, pretty-printed JSON in a
monospace textarea, refused with the parser's message when it does not parse, and every error under
it listed above it. Until 12b, a page's, an article's and a doc's content is edited there and nowhere
else. A type with no page draws its content as the rich text editor, since it holds text alone.

**Rich text in the site's styles.** Every rich text editor in the admin -- a RichText field, and a
page-less type's content -- draws its text as the site draws it. The site names its stylesheets and
the class its text sits in once, in its service provider, `Mainstay::editorStyles(['/css/site.css'],
class: 'prose')`, as a WordPress theme does with `add_editor_style`, so the paths can come from code, a
hashed build file's among them. The editor's
document is mounted in a frame drawn in the admin with those stylesheets, its body holding one element
of that class, so the site's `:root` variables, its fonts and `.prose h2` apply to the text and none of
the site's CSS reaches the admin around it. The toolbar and the link dialog stay in the admin, outside
the frame, and the frame grows and shrinks with its text, sized from the mounted element's box and
margins, not the document's height, which a site's `min-height: 100vh` holds at the frame's own.
Without that call it draws in the admin's own
typography, and without JavaScript the text shows rendered and read-only, both as now.

**The site.** Page loses `body` and `blocks` for content and `#[Blocks]` naming its five; Article and
Doc lose `body`. Their views print `{{ $entry->content }}`, a page's in one element where its text and
its blocks were two, so `site.css` sets the measure per block -- text at the narrow column, a site
block at its own width -- where the view's wrappers set it before -- and its text rules, `.prose ul`
and `.prose pre` among them, apply to the content's own children, so a block's lists and code are not
restyled. `Page::holding()` reads `content?->blocks()` where it read `blocks`. The seeder writes
content, and the site's AppServiceProvider calls `Mainstay::editorStyles(['/css/site.css'], class:
'prose')`. A docs page "Content" says what content is, which blocks a type allows, how a view prints it
and how a site names its editor styles. The site's
tests: the front page, an article and a doc draw as they did.

**The check.** `ContentTest` and `RenderTest`, on every driver:

- Every entry type and a term holding content without declaring it, its column made by sync; one
  content for every language by redeclaring it; a redeclaration as another field type refused.
- A content of text and a site block written, read back with the block's image loaded, and drawn in
  order; a block the type does not allow, a block inside a list item, and a block's bad field each
  refused with its place.
- `#[Blocks]` with `required` on a class refused; a Blocks field on an entry type, on a global, and
  `#[Blocks]` on a type with no route refused, each naming why; a Blocks field inside a block still a
  list.
- A draft and a revision carrying content.
- A heading of level 1 and of level 6 kept, a level 7 refused, in content and in a RichText field.

`AdminTest`: a page's raw content saved, a parse error and a block's error listed above it; a page-less
type's content saved from the rich text editor; the editor's frame naming the stylesheets and
class given. Vitest: the editor mounted in a frame, the toolbar acting on it, the input written from it.
Fixtures that declare a Blocks field on an entry move it to `#[Blocks]` and content, and those with no
route, `Guide`, `Pictured` and `Slid\Article`, gain one. The tests that bind a Blocks field on an entry
fixture, or check one's column on its main table, point at a list inside a block fixture instead.

### 12b — The Content tab

**Two tabs.** The edit screen of an entry whose type has a page has Details and Content, in that order,
Details open when the screen opens and the tab chosen kept across a reload and a save: a save and a
reload leave it in the tab's session storage, beside the copy's key, read once by the page that comes
next, so any other opening is on Details. `packages/ui`
gains a tabs component: a tablist of buttons over panels, arrow keys between them, as the ARIA pattern
has it, and without JavaScript both panels one after the other. Both panels are in the one form, so one
Save draft and one Publish save both, and leaving with unsaved changes in either warns. A page-less
type and a global keep the one form, without tabs.

**The page, from the form.** The Content panel is a frame. Opening the tab, and opening it again after
Details changed, posts the whole form to the canvas route with `fetch`, as an XHR, and writes the page
it answers into the frame's document, its scroll kept, so it draws the entry as the form now holds it,
unsaved. Being no submit, it is not held up by a control the browser finds invalid in the hidden
Details panel, adds nothing to the history, and leaves the dirty guard's snapshot alone. Any answer but
a page shows as a message in the tab: a 422's messages, and for an ended session or a stale token, that
signing in again is needed, the browser's copy keeping the work. The routes are the edit screen's with `/canvas`:
`/admin/article/5/canvas`, `/admin/article/drafts/12/canvas` and `/admin/article/new/canvas`.

The route reads through the layer's `Mainstay::preview(Article::class, 5, $changes, locale: 'en')`:
the entry with its draft over it and the changes `Form::changes()` makes of the post over both, `draft:
12` for one not yet published, neither for a new one, and a term, which has no draft, live with the
changes over it. It checks the changes as a draft save does -- each value its field's kind, nothing
required -- is refused as that save would be, and writes nothing. A tag typed in Details that does not
exist yet is left out, since only a save makes one.

The page is the response the public route gives that entry, `Mainstay::template($entry)` with
`$entry`, but for four things.

- The locale is the form's.
- Nobody is signed in while it draws. Mainstay's Gate reads as a visitor, so everything else on the
  page -- another entry, a global, a list of articles -- is what the public is served, and only this
  entry is the draft. The default guard is one with no user, so a host view's `@auth`, `auth()` and
  `@can` see a guest rather than the Mainstay account the admin's `StartSession` made the default; the
  container's resolved guard is forgotten on the way in and out, so the rest of the request keeps its
  own.
- The entry's own content, and the blocks in it, are drawn for the editor: `{{ $entry->content }}`
  and 12c's `@field` read that from the content they are given, so a rich text field and another
  entry's content on the page draw as on the site. An empty content draws for the editor as an empty
  document.
- No script runs in it. The frame is `sandbox="allow-same-origin"`, without `allow-scripts`, so the
  site's own scripts, and any third party's they load, never run in the admin's origin with the
  editor's session, in the page written there or in one it is navigated to. The editor's script runs
  in the admin page around the frame, which the sandbox does not reach, and cancels a `click` and an
  `auxclick` on a link in the frame, so neither a click nor a middle click opens it; the sandbox
  already blocks a form's submit. Writing the frame's document drops every listener on it and its
  window, so each one the admin puts there -- these, the grip's drag and the scroll that keeps the grip
  lined up -- is attached again after every write. The written page
  resolves its URLs against the admin's own address, so a root-relative stylesheet and its fonts load
  from the host serving the admin, the same application's `public/`.

It runs on the admin's middleware, not the site's, so a view that needs the host's `web` group draws as
it does without it. A draft not yet published has no id and no path, and a view that reads them checks
first, as the site's layout and `Page::isFront()` will. A draft that leaves empty a field its property
cannot hold -- a required date -- is not drawn: the tab names the fields the page needs first, as phase
8 promised. A view that throws shows the error's message in the tab rather than the admin's error page.

ponytail: the whole page is drawn again when Details changed, rather than patched, so a page slow to
draw is slow to come back to; patching the fields a view prints waits for a page where it shows.

**The editor in the page.** For the editor, `{{ $entry->content }}` prints a `<script
type="application/json">` holding the document and each site block's HTML by its id, and nothing else,
encoded with `JSON_HEX_TAG | JSON_HEX_AMP` so a `</script>` typed in a paragraph stays text inside it.
The editor takes the element that script sits in as ProseMirror's own, its `{mount}`, so the text it
draws sits where the view put it, under the view's classes, and `.prose > * + *` spaces it as on the
site. ProseMirror draws the text blocks with the tags `Document` renders, a fixture the PHP renders and
Vitest draws holding the two to the same HTML, and a site block as the HTML its view drew, an island it
does not edit. The rest of the page is not editable. A page that prints the content beside something
else in its element, or not at all, is drawn read-only and the tab says which; the second is a page
that does not use its content, which still shows Details' changes on it.

Every change writes the document into Details' raw content textarea, the field's one input, and
dispatches `input`, so the dirty guard and Save see it. A hand edit there is what the editor loads the
next time the tab opens. A redraw mounts a new view on the new page with the editor's state as it was,
so undo reaches past it.

**Blocks to the editor.** Every top-level node is a block. The frame holds the page and the cursor
and nothing of the admin's: every control is outside it. The rich text toolbar above the frame carries
the marks, the link dialog, undo, redo, Add block -- a menu of Mainstay's text blocks and the type's
own, inserting after the current block -- and the current block's Move up, Move down, Duplicate and
Delete, and for a text block Turn into a paragraph, a heading, a quote, a code block or a list. The
current block is the one holding the cursor or selected, outlined in the frame. `/` at the start of an empty paragraph opens the same
menu there. Enter at the end of a block starts a paragraph, and Backspace in an empty one removes it. A
site block is selected by a click and moved and deleted as any block; its fields wait for 12c, and for
Details' raw data until then. Inserting one draws the page again, so it shows as its view draws it, and
one its view draws as nothing -- a list of articles with none yet -- shows a placeholder naming it, to
be selected.

A block is dragged to its place too, by a grip beside the frame, outside it, lined up with the current
block. The grip is the platform's `draggable`, as a relation row is. While it is over the frame the
admin follows the frame's `dragover`, which it can reach, draws a line over the frame where the block
would land, between two blocks, and on the drop moves it in one step undo takes back. During a grip drag
the admin cancels the frame's `dragover` and `drop`, so the drop lands anywhere on the page and
ProseMirror's own drop handling stays out of it.

**A copy in the browser.** While the form is dirty, what it would post, less its CSRF token, is kept in
the browser's local storage under the account's id, the entry's admin address and the locale, with the
time. Opening the entry again as that account, with a copy that differs from what is drawn, offers it:
"You have unsaved changes from 12:38", with Restore and Dismiss. Restore posts the copy to the form's
own action with `intent=restore`, which draws the form from it unsaved, as a refused save redraws what
was posted, so every field -- rows, chips, the content -- comes back as it was, and the form starts
dirty. The copy carries the `_seen` fingerprints and `_draft_at` it was made with and the redraw keeps
them, so saving it changes only what the copy changed, and a publish over another editor's later save
is refused as from any form opened before it. A save leaves the copy's key in the tab's session
storage. `save()` flashes a marker of its own once the draft save returns -- a draft that came to change
nothing deleted, null, included -- or a term is written -- a publish
refused after it included, which lands a new entry on its draft's address -- and the admin's layout
prints it, so the page a save lands on, whatever its address, drops that copy only when the marker is
there, and every admin page clears the key. A save refused, or one that never ran for an ended
session, keeps the copy. Dismiss and a discarded draft drop it too, and signing out drops every copy, so the next person at that browser finds
none. With the admin on the site's own host, the site's scripts share its origin and could read the
copies -- as they could fetch the admin's pages with the editor's cookie already. An admin on its own
host, through `mainstay.domain`, keeps both from them only when every locale names its host too: a
locale with none is served on every host, the admin's included, so a site page opened there -- a
canvas link through the browser's own menu -- runs its scripts in the admin's origin.

**The check.** `DraftTest`, on every driver: `Mainstay::preview()` reading an entry, a draft not yet
published and a term with changes over them, refusing a value of the wrong kind and someone who may not
save, and writing nothing. `AdminTest`:

- The canvas drawing a page from posted changes, its draft and its entry left as they were; another
  entry's draft and an internal field not on it; a new entry's, with no id; a new tag left out;
  refused to someone who may not update.
- A host view's `@auth` seeing a guest, and the admin's own account signed in again after it; a rich
  text field and another entry's content on the page drawn as on the site.
- A required date left empty named, not drawn; a throwing view shown as its message.
- The content's script printed for the editor only, the public page unchanged, and a paragraph holding
  `</script>` kept inside it.
- Restore drawing the form from a copy, nothing saved; saving it after another editor changed a field
  the copy did not, that change kept.
- The save marker flashed after a draft saved, after one that came to nothing and was deleted, and
  after a publish refused once it was, and absent after a refused save.

Vitest: the tabs; the frame posted on opening the tab only when Details changed; the editor mounted on
the script's element, and the two read-only cases; a link's click and middle click in the frame
cancelled; a non-page answer shown as a message; each block command, Add block and `/`, a block dragged by its grip and the move one undo step, ids given on insert,
duplicate and paste, and undo past a redraw; the copy kept without the token, offered only to its
account, restored dirty, and dropped only where the save marker is. The headless browser screenshots the
Content tab of the front page, an article and a doc, and after a redraw a link's click and middle
click leave the frame on its page -- jsdom keeps the listeners a write drops, so Vitest cannot. The site's test: a new page's canvas drawn.

### 12c — The site's blocks, in place

**`@field`.** A block's view prints a field's text with `@field($block, 'headline')`, or a nested
item's with `@field($feature, 'title')`. On the site it is the escaped value, as `{{ $block->headline
}}` prints it; for the editor, that text in a `<span>` marked with the item's id and the field's name,
which the editor makes typeable as plain text. It takes a Text or a Textarea field, a Textarea's line
breaks kept; any other is refused naming the field, and is edited in the sidebar. An empty one prints
its field's label as a placeholder for the editor and nothing on the site. Typing in it writes the
block's data in the document, as every change does.

**The sidebar.** Selecting a site block opens a sidebar beside the frame with its whole form: every
field, those typed in place too, drawn by the components Details draws them with, images picked from
the library and relations searched. It is a form of its own outside the entry's, as the dialogs are,
since a form inside a form joins its inputs to the outer one and a Feature's `title` would post as the
page's. The edit screen of a type with blocks pushes the picker and link dialogs their fields need. A list field inside the block -- FeatureGrid's Features -- is a list
of its items, each its fields, added, moved up and down and removed: the one place the admin draws a
list of blocks, since an item has no page of its own to be edited on. Typing in place updates the
sidebar, and the sidebar updates the text in place.

The server draws and reads the sidebar, so each field's `fromForm()` stays the one conversion.
`/admin/article/block/form` draws it from the block's data, posted, and a change posts it to
`/admin/article/block`, which converts it, checks it as a draft save would, and answers the block's
data and its HTML drawn for the editor -- as the canvas draws, the Gate a visitor's -- which replaces
the block in the frame. Both refuse a block the type does not allow, and anyone who may not update the
type.

**Errors.** A save refused for a block's field lists the error above the frame naming the block, marks
the block, and shows the message in its sidebar, as Details lists it above the raw data.

**The site.** Hero, FeatureGrid and the lists' headings print their text with `@field`. The phase 12
article, waiting as a draft, is written and published, and the next, on phase 13, waits as one. The docs
page "Content" gains `@field` and the sidebar, and "The admin" a section on the Content tab. The site's
test: the front page's canvas drawn with its hero's headline marked.

**The check.** `RenderTest`: `@field` printing the escaped value on the site, the marked span for the
editor, its placeholder, and refused for an Image field. `AdminTest`: the block form drawn with its
values, a change converted and drawn, a nested item added, a block the type does not allow refused, a
block's error listed and marked; a Feature's `title` changed in the sidebar and the page saved, the
page's title as it was. Vitest: selecting a block opening the sidebar, typing in place and in the
sidebar each updating the other, the block replaced by the HTML answered. The headless browser: the
front page's hero edited in place.

## Phase 13 — The API as a product

The guard goes on. `#[PublicRead]` opts a type into public reads, which never touch the draft table,
at every depth. Tokens are issued by Mainstay and stored hashed beside its own users table.

The JSON Schema derived in phase 1 is served from a discovery endpoint and written out as a `.d.ts`,
from the same source rather than generated twice.

The payload has to satisfy that schema, and for a timestamp field it is the one place where it does
not come from `Field::serialize()`. That method writes what the column takes -- `2026-09-10
06:30:00` -- and the schema says `format: date-time`, which is RFC 3339 and wants the `T` and the
offset. Phase 1 settled that both are UTC, so the conversion is a formatting choice here and not a
zone question, but it is a choice this phase has to make rather than inherit.

## Phase 14 — The second locale and the second site

Nothing new in the schema — phase 2 put the columns there, phase 3 already reads and writes
more than one locale, and phase 10 put the locale switcher in the admin. This is where the rest is
proven by configuring a second of each: per-locale publish state and trash, fallback as an opt-in, and
the request host matched to a site. With one of each configured, none of it renders.

## Phase 15 — Onboarding

`spatie/laravel-onboard`, required by this phase and not before it. Two lists: an installation
checklist on a plain `Installation` class, and a per-user list. Steps are declared in the service
provider and every one is a `completeIf` closure, so nothing is stored and nothing can drift.
`excludeIf` takes the capability check, so a step an editor cannot perform is hidden rather than
shown and refused.

Settings the checklist collects are globals, so it gets no storage of its own.

The checklist is the dashboard's first widget. Phase 10 leaves `/admin` empty; widgets, as WordPress
draws them, arrive here as a list a host adds to in its service provider, each a Blade view.

## Phase 16 — Template parts

What WordPress calls template parts and what it calls options are two things here. A global stays
settings, plain fields, as the site's `Layout` is. A part is a piece of the layout built from blocks --
a navigation, a footer -- declared as `class Footer extends Part`, naming the blocks it allows on the
class with `#[Blocks([...])]` as a type does. It has content built in, as an entry has, and no address:
it is stored once per site, localized, drafted and published as a global is, and a view prints it with
`{{ Mainstay::part(Footer::class) }}`. The admin lists parts under a heading of their own, apart from
the globals. A part's edit screen draws it alone, in a frame with the site's stylesheets as the rich
text editor's is, its blocks drawn by their views and edited in place as 12c does; a style that hangs on
where the layout puts the part can look different there. 12a refuses `#[Blocks]` on a type with no
route because it has no page; a part has no route either and takes it. A Blocks field on a global stays
refused.

The site's `Layout` gives its menu and its footer line to parts: a navigation holding a Navigation block
the site writes, as it writes its own Image block, and a footer.

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
- **Locales arrive in phase 3, not phase 14.** The real site is multilingual from the start, so
  the layer takes `locale` on every call and writes a row and a path per locale from its first save.
  Phase 10 brings the admin's switcher forward, and phase 14 keeps the rest a second locale needs.
- **Preview is the Content tab.** It sat in rendering, showing published rows until drafts arrived,
  and then beside the admin's form. It is the page rendered from the form for a signed-in editor,
  which is what phase 12 edits the content on, so it is built once, there, and is not a second thing.
- **The admin is four pull requests, and accounts are a phase of their own.** Phase 10 was the
  largest phase by a distance. Cut at the milestones its own stub named, each merges on its own and
  leaves an admin that works, and the screens over phase 9's accounts follow as phase 11.
- **A page's content is edited on its page, in the admin.** Phase 12's stub edited it on the public
  site, in an edit mode that would have had a signed-in editor served something other than the
  visitor's page. The edit screen draws the page in a frame instead, and Details keeps the content as
  raw data for when the page breaks.
- **Content is Entry's, one document whose top-level nodes are blocks.** A content field a type
  declares, and a list of blocks with text as one kind of block, were the alternatives. Entry's own,
  as the title is, gives every type one place for it and the edit screen one thing to edit. One
  ProseMirror document lets text be typed, selected, pasted and undone across blocks as in one editor,
  while each top-level node is a block to the toolbar's block commands.
- **Template parts are a kind of their own, not globals with content.** Navigation and a footer are
  arranged by an editor from blocks; settings are fields a developer lays out. Keeping them apart
  keeps globals plain and gives parts the content an entry has.
