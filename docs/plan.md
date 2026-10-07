# Plan

How `packages/cms` gets built, in the order the decisions in `decisions.md` allow. Each phase ends
at something that works and can be looked at, not at a layer that is finished.

Phases 1 to 8 are done: content types are declared, reflected into a field list, synced into
tables that `mainstay:schema:check` holds CI to, read and written from PHP through one query
layer, and served at their paths in Blade by a host site, `mainstay-site`. Rich text and blocks
are field types like the scalars, each kept in a `json` column of its own, and the site's bodies
and front page are written in them. Images live in an installation-wide library, written at every
declared size in AVIF and WebP when they are uploaded. Entries point at each other through
relations a read follows to a depth, tags are the entries of a taxonomy found again through its
pivot, and a global holds the site's menu and footer. A change waits as a draft of what differs
from live until it is published, every version a write replaces is kept as a revision a draft can be
made from again, and the trash is restored from or emptied. `packages/ui` is ahead of the package —
Blade components, a theme, and a behaviour layer in `src/js` — and `packages/editor` is a built
ProseMirror island declaring the document schema the package renders.

The order puts content working before anyone can log in to it. Up to phase 8 everything is driven
from PHP — the site's own seeders, import commands, tinker — against a real site. Identity and the
admin arrive after the model has been proven, as a surface over a layer that already works.

## What is already settled and does not get re-opened

Structure is PHP classes read by reflection. Content lives in per-type tables with real columns for
scalars and a JSON column for each tree: a document, a list of blocks. There is one query layer and HTTP is a transport over it.
The admin renders in Blade. Client state is vanilla TypeScript. Everything is soft-deleted, scoped
to a site, and localizable per field.

## Corrections to carry into the work

Twenty things in `decisions.md` are stale or contradicted by a later entry. They are recorded here
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
No cap here; phase 11 caps what a request may ask, and a cycle only costs the levels asked for.

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
phase 10's preview says so rather than drawing it. Whether a path is free is publish's question.

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
