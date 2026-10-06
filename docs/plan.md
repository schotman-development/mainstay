# Plan

How `packages/cms` gets built, in the order the decisions in `decisions.md` allow. Each phase ends
at something that works and can be looked at, not at a layer that is finished.

Phases 1 to 5 are done: content types are declared, reflected into a field list, synced into
tables that `mainstay:schema:check` holds CI to, read and written from PHP through one query
layer, and served at their paths in Blade by a host site, `mainstay-site`. Rich text and blocks
are field types like the scalars, each kept in a `json` column of its own, and the site's bodies
and front page are written in them. `packages/ui` is ahead of the package — Blade components, a
theme, and a behaviour layer in `src/js` — and `packages/editor` is a built ProseMirror island
declaring the document schema the package renders.

The order puts content working before anyone can log in to it. Up to phase 8 everything is driven
from PHP — the site's own seeders, import commands, tinker — against a real site. Identity and the
admin arrive after the model has been proven, as a surface over a layer that already works.

## What is already settled and does not get re-opened

Structure is PHP classes read by reflection. Content lives in per-type tables with real columns for
scalars and a JSON column for each tree: a document, a list of blocks. There is one query layer and HTTP is a transport over it.
The admin renders in Blade. Client state is vanilla TypeScript. Everything is soft-deleted, scoped
to a site, and localizable per field.

## Corrections to carry into the work

Eleven things in `decisions.md` are stale or contradicted by a later entry. They are recorded here
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
  being the default, and there is no fallback flag until phase 12 makes fallback an opt-in.

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
