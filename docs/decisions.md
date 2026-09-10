# Decisions

Architectural decisions that the code cannot explain by itself. Newest last.

## Structure is declared in code, content lives in the database

*2026-09-09*

Content types are PHP classes. Their typed properties carry field attributes, and reflection reads
the class to derive everything downstream:

```php
class Page extends Entry
{
    #[Text(required: true)] public string $title;
    #[Slug(from: 'title')]  public string $slug;
    #[RichText]             public ?Document $body;
    #[Blocks(of: [Hero::class, FeatureGrid::class])] public array $blocks;
}
```

One declaration produces four things: the admin form schema, the database column plan, validation
rules, and JSON Schema for consumers.

This is Payload CMS's model, with one difference in Mainstay's favour. Payload has to generate a
`payload-types.ts` file because TypeScript cannot read a runtime config array. PHP's type system is
declaration-first, so the class *is* the type — PHPStan and editor autocomplete work with no
generate step. Code generation is only needed to cross the language boundary to the static front
end, which gets a `.d.ts` written from the same JSON Schema.

Consequences:

- There is no `content_types` table, and no UI for building content types. The admin renders forms
  from declarations; it does not author them.
- Attributes are less expressive than an arbitrary object literal, so nested structures point at
  another class rather than nesting inline. Every block ends up with its own typed class, which is
  the better outcome anyway.
- Adding a field is a commit, not a click. That is the trade Mainstay is choosing.

## Sync the schema in development, hand-write migrations for production

*2026-09-09*

Declared fields have to become real database columns. Three ways to get there:

1. Generate migrations by diffing a committed schema snapshot, the way Payload does with
   drizzle-kit.
2. Sync the schema automatically in development, hand-write the production migration.
3. Skip columns entirely and keep every field in a JSON column.

Mainstay does 2, plus a command that compares the live database to what the classes declare and
fails CI when they differ.

Option 2's only serious danger is development and production schemas drifting apart, because one
was reached by automatic alters and the other by hand-written migrations, and nothing proves they
agree. Detecting a mismatch is cheap; only describing the fix is expensive. So the drift check
removes the danger for a fraction of the cost of option 1.

This is not a detour. The desired-schema model and the comparison logic are two of the pieces
option 1 needs, so generated migrations later become an addition rather than a rewrite. Emitting
Laravel schema builder calls rather than raw SQL also means Laravel absorbs the cross-database
differences that make drizzle-kit large.

Consequences:

- The automatic sync is destructive — renaming a property drops the column and its data. It refuses
  to run in production and sits behind a config flag that is off by default.
- The diff work stays small only while fields are scalar. Repeaters and blocks become child tables
  with ordering columns and foreign keys, and that is where the complexity arrives.
- No diff can distinguish a rename from a drop plus an add, or convert data when a type changes.
  Those stay manual whatever is built later.

## The front end renders in Blade, so nothing needs Node on the server

*2026-09-09*

Mainstay must run on a plain VPS. Node is the main thing that makes Payload unpleasant to host,
and adopting a JavaScript front end would import that problem rather than avoid it.

The distinction matters: Payload needs Node *at runtime*, because it is a Next application — a
process running forever, consuming memory, needing a supervisor. Astro would only need Node *at
build time*, which is much milder. But it still means Node and a few hundred megabytes of
`node_modules` on the box, and a build large enough to exhaust a small server.

The asymmetry that settles it: the admin bundle can be pre-built and committed because Mainstay
authors it. Nobody can pre-build the host's own site. So any JavaScript front end pushes a build
onto the host's machine permanently.

So the first-party path is Blade rendered by the host's Laravel application, dumped to static HTML
by an artisan command. Other generators stay supported, because the API is the contract — they
build in CI and ship static files, so Node never reaches the server either way.

Consequences:

- Live preview is an iframe pointed at a signed preview URL, not a second renderer in React. Blocks
  are written once, in Blade, and the admin renders forms rather than blocks.
- Notion-style inline block editing is off the table. Preview sits beside the editing surface, not
  underneath it.
- Compiled CSS is committed, the same way `packages/cms/dist` is. Stylesheets change when templates
  change, which is design time, not publish time — so the server copies a stylesheet and never
  builds one.

## The design phase is plain HTML and CSS; components arrive only when markup repeats

*2026-09-09*

A designer should be able to open a file and work. No build step, no server, no framework. The
design phase produces the handful of unique layouts, not every page, so duplication stays bounded.

Blade is what makes this work: a `.html` file is already a valid Blade template, so renaming it
renders it byte-identical. Content is then replaced with echoes one line at a time, and each step
works before the next begins. JSX cannot do this — every static page needs `class` rewritten to
`className`, tags self-closed, and styles turned into objects before anything renders at all. That
mandatory rewrite is the jacket needing to be tailored before it can be put on.

The wiring order is: move files into `resources/views` and rename; cut repeated chrome into a
layout; replace literal text with echoes; wrap the repeated card the designer wrote in a
`@foreach`. That last step is where the content model comes from — the loop names the fields, so
the schema is read off the design instead of guessed ahead of it.

On consistency, the two jobs React bundles together are separate here. Consistency comes from the
stylesheet; encapsulation comes from the component. A button does not vary because a component is
missing, it varies because three colour values are loose in the codebase.

- Anything that is one element with classes — buttons, inputs, badges — is CSS only. Double-clicking
  a file still works, and divergence is impossible without writing new CSS.
- Anything with real markup structure — a nav with a dropdown, a card with image and meta and tags —
  is a Blade component, from the start. Not a web component: that would be a second implementation
  thrown away at conversion, which is the tailoring being avoided.
- No component until the same structure appears three times. Before that it is a class.

Consequences:

- Introducing components costs `php artisan serve` instead of opening a file. Acceptable, since PHP
  is present anyway.
- Components are viewed in Storybook, which now renders Blade rather than React. A hand-rolled
  styleguide page was considered and dropped as redundant.
- The site's CSS consumes `packages/ui/src/theme.css`, so the admin and the public site share tokens
  and the CMS does not look like a different product from the site it manages.

Sections that need to be reorderable still have to be cut into one file per block type. That is the
only part of the conversion that is not a rename, and no CMS avoids it.

## StyleX styles the site, but the CMS is unaware of it

*2026-09-09*

Styles on the public site are authored with StyleX. This is a site-level preference and nothing in
Mainstay depends on it — content arrives over the API as data, and how a template is styled is not
the CMS's concern.

Two consequences, both contained:

- StyleX is a compiler, so the design phase gains a watch process and the double-click-a-file
  property is lost once styling starts. The compiled CSS is still committed and the server still
  builds nothing, so no VPS gains a Node dependency.
- StyleX class names are generated hashes returned by `stylex.props()` at runtime in JavaScript,
  which Blade cannot call. So StyleX is the source of the stylesheet and of CSS custom properties
  through `defineVars`; Blade templates reference plain class names and variables. It is the design
  system's authoring format, not a per-element styling mechanism inside templates.

## One query layer, with HTTP as a transport over it

*2026-09-09*

Blade templates in the host application do not reach for Eloquent directly, and they do not make
HTTP requests to their own server either. Both are wrong for the same reason: the first would give
the first-party consumer a different code path from every other consumer, which is how an API
quietly rots, and the second is an absurd round trip at build time.

Instead there is a query layer taking `(type, where, sort, depth)`. `routes/api.php` is a thin
controller over it, Blade templates call it directly, and the export command uses it as well. There
is one place where "what a page looks like as data" is decided.

This is Payload's Local API, which takes the same call shape as its REST endpoint and executes it
against the database with no HTTP hop. REST and GraphQL there are transports, not separate
implementations.

Two details worth copying exactly:

- `depth` — how far relationships are populated — is an argument on both paths, so a local caller
  and an HTTP caller get identically shaped data rather than one nested and one flat.
- Access control is opted *out* of explicitly, the way Payload's `overrideAccess` works. Taking the
  internal route must never silently mean weaker permissions than the public one.

## URLs are declared on the content type, resolved by one catch-all

*2026-09-09*

Something has to connect a request for `/blog/hello` to an entry and a template. Two shapes were
considered: hand-written Laravel routes per content type in the host application, or a single
catch-all owned by Mainstay.

Every CMS that renders its own front end takes the catch-all, and none of them puts the URL pattern
on the entry or makes you write a route per type. It goes on the type, once, with fields
interpolated into it. Statamic declares `route: 'blog/{slug}'` on the collection, where any field
can be a variable. Craft gives each section a URI format of the same shape. WordPress differs only
in style — rewrite rules parse the URL into a query, then a fixed template hierarchy picks the file.
The CMSs that do not render — Payload, Sanity, Contentful — have no routing at all, and `slug` is
just a field the front end queries by.

Mainstay declares structure in PHP classes, so the pattern goes there as an attribute:

```php
#[Route('/blog/{slug}')]
#[Template('article')]
class Article extends Entry { }
```

One catch-all, registered last, matches a path against the patterns declared by every type and falls
through to an ordinary 404 when nothing matches. Hand-written routes in `web.php` still win because
they are registered first, so the escape hatch costs nothing.

Template resolution cascades, following Statamic: a per-entry override first, then the type's
declared template, then convention derived from the type name. Per-entry overrides are worth having
— a landing page that needs its own layout is common enough that the alternative is a content type
existing only to carry a template name.

Consequences:

- Editors can create URLs the developer did not anticipate, which is the point, but it also means a
  typo in a slug is a live 404 rather than a build error.
- The catch-all must be registered after everything else, so ordering becomes a documented
  requirement rather than an implementation detail.
- Patterns interpolate fields, so changing a field that appears in a route changes URLs. Redirects
  are a later problem, noted here so it is not a surprise.

## A table per content type, with one lookup table for routing

*2026-09-09*

Declared fields become real columns, so each content type gets its own table with its own
constraints, the way Payload gives each collection a table.

The one thing that argues against this is the routing catch-all, which has to resolve a single path
without querying every table in turn. That is answered by a small lookup table mapping `uri` to
`(type, id)`, written whenever an entry is saved. One index serves every request, and per-type
tables keep their real columns. Craft does approximately this with its elements table.

There is no bulk export endpoint, and no requirement that one query can read the whole corpus. That
requirement came from an earlier assumption in this brainstorm — that an external static site
generator would pull all content over HTTP to build itself — and Blade rendering through the query
layer removed that consumer. The static dump walks routes and renders one entry at a time. If a
third-party consumer ever needs bulk reads, the API answers it with ordinary per-type queries.

## Blocks are data in a JSON column, never markup

*2026-09-09*

The hard requirement is that Mainstay stores data, not markup. That rules out Gutenberg's approach
of serialising blocks into HTML comments inside the content column, rules out rendered HTML in a
rich-text field, and — read strictly — rules out markdown, which is markup with a friendlier syntax.

It does not by itself decide how blocks are stored, since both a JSON column and relational tables
hold data. Two things already decided do:

Development syncs the schema and production migrations are hand-written. Relational blocks would
make a designer adding a field to the pricing block into a schema change and a migration, which
fights the workflow where the design drives the model. The `@foreach` a designer wrote is meant to
name the fields cheaply and repeatedly during the messy part.

And the survey of other systems shows the constraints being bought go unused. Payload puts each
block type in its own table with `_order` and a cascading `_parent_id`; Drupal's Paragraphs makes
every paragraph type a full entity; Strapi uses component tables behind a `__component`
discriminator. Statamic, Sanity and Prismic all store an ordered array of typed maps and query
nothing across it. Only Craft 5 genuinely queries block content across pages, having deliberately
turned Matrix blocks into entries to allow it. Payload — the most relational of them — ships
`blocksAsJSON` on its Postgres adapter as a performance escape hatch.

So: top-level scalar fields stay real columns, because slug uniqueness and `published_at` indexes
are actually used. Block content is one JSON column holding an ordered array of
`{ id, type, data }`. Rich text inside a block is ProseMirror document JSON, which is a node tree
and therefore data.

**The editor does not decide this; it was decided already.** A form-based block list — Payload,
Statamic's Replicator, Prismic's Slices — implies an array of blocks, each opening a form of fields.
A prose-first editor — Gutenberg, Bard, Notion — implies one document tree with blocks interleaved
as nodes among paragraphs. Choosing iframe preview over shared components already ruled out inline
block editing, which selects the form-based list, which selects the array.

Consequences:

- Something in PHP must walk the ProseMirror node tree and emit HTML at render time. Small while the
  schema stays small, which is what the deliberately minimal schema in `packages/editor` was
  already arranging for.
- A cached rendered-HTML column remains legitimate, because it is derived and regenerable. The
  document stays the source of truth.
- The node schema is closed. Every node type needs a renderer and an unknown node renders as
  nothing — the correct failure mode, but it means the editor cannot accept pasted HTML without
  mapping it into known nodes first.
- Cross-page questions like "which pages use the pricing block" are not answerable in SQL. If that
  becomes necessary, it is a derived index, not a reason to go relational.

## Relations are plain IDs, resolved defensively

*2026-09-09*

Entries reference each other — the `depth` argument on the query layer only means something if they
do. But block content lives in a JSON column, so a reference stored inside a block has no foreign
key behind it. Deleting the target leaves the JSON still pointing at it.

Mainstay accepts that. Relations are stored as plain IDs and the renderer skips what it cannot load,
so a deleted entry degrades to a missing card rather than an error page.

The alternative considered was a derived relations table written on save, mirroring every reference
found in the JSON — the same trick already accepted for URI lookup. It would give real foreign keys,
make "what links here" a query, and let a delete warn or refuse. It stays available later precisely
because it is derived: it can be built by rescanning existing content, with no migration of the
content itself.

Consequences:

- Deleting an entry silently breaks every page referencing it, with no warning at the point of
  deletion and no report afterwards.
- Every renderer that follows a relation must tolerate a missing target. This is a rule about how
  templates are written, not something the storage layer can enforce.
- Referential integrity is not a thing the database provides here. If broken references become a
  real support problem, the derived relations table is the answer, not a change to how blocks are
  stored.

## Image derivatives are generated at upload and can be regenerated later

*2026-09-09*

Derivatives are produced when a file is uploaded, written to disk, and served as plain files. The
alternative — generating sizes on first request and caching them, Glide-style — requires PHP running
on whatever host serves the images, which is precisely what the deployment separation argument wants
to remove. Deciding this at upload keeps the deferred publishing question genuinely open rather than
quietly settling it.

The set of sizes is declared in code alongside the field, not configured once and forgotten, and a
command reprocesses the library when those declarations change or when an editor adjusts a crop.

Consequences:

- Originals are kept permanently. Discarding them after processing would make re-cropping impossible
  and turn a declaration change into data loss.
- Manual crops and focal points are data on the media record, so reprocessing honours them rather
  than overwriting them with a fresh automatic crop.
- Derivative filenames are derived from the original's content hash plus the size and crop
  parameters, so regenerating writes new files and old URLs cannot serve a stale image. Caches and
  CDNs need no invalidation.
- Reprocessing runs on the queue. A large library is not something to do synchronously in a request
  or block a deploy on.
- Adding a size is a declaration plus a backfill, which is cheap. Removing one leaves orphaned files
  until something prunes them.

## Drafts live beside the entry, revisions are snapshots taken on publish

*2026-09-09*

The preview decision assumes unpublished content exists, and nothing had defined what a draft is.

The entry row always holds the currently published content. The per-type table stays exactly as
already decided — real columns plus the JSON block array — so the public site's read is a single-row
select with no version resolution on it at all. Payload keeps live content in its versions table and
resolves on read; Mainstay does not, because the hot path is the site and versioning has no business
being on it.

A draft is at most one row per entry in a parallel table. Editors want the version they are working
on, not a queue of them, so "which version" collapses to a left join that is usually null. Preview
reads the draft when there is one; the site never looks.

Publishing is a single transaction: the draft overwrites the entry row, the outgoing entry content
is appended to revisions, the draft is deleted. No resolution logic anywhere. That transaction is
also the one place where "content changed" is known, which is where the deferred publishing question
will attach — whether it turns out to mean a cache clear or a rebuild.

Revisions bundle two jobs: restore needs full content, audit needs who and when. Snapshotting on
publish serves both. A revision is created when content stops being live, so revision count equals
publish count and every stored revision was genuinely public — which is the only thing anyone asks
to roll back to. Draft edits mutate the draft row and produce nothing.

Restoring writes a draft rather than republishing, so it leaves through the normal publish path and
needs no semantics of its own.

Revisions go in one shared table across all types — entry type, entry id, a JSON snapshot of the
whole entry, author, timestamp, and a hash of the declared field set. Not per-type tables mirroring
columns: history is read whole and never queried by field, so columns buy nothing. Same reasoning as
blocks.

The sharp problem, and a direct consequence of declaring structure in code: a revision is shaped by
the content type as it was declared at the time, and types are PHP classes that change with deploys.
An old snapshot may carry fields that no longer exist and lack fields that are now required, so
restoring it can produce an entry that fails validation against the current class. A CMS with
database-defined types mostly avoids this, because its schema and content move together.

The answer is the stored field-set hash: on restore, drop unknown fields, fill missing ones with
defaults, and tell the editor what changed. Migrating old revisions forward is explicitly not
attempted.

Consequences:

- Storing diffs instead of full snapshots is rejected. Text compresses well, full rows are simple to
  read, and delta chains break in ways that are miserable to debug.
- Revisions prune to the last N per entry on write.
- Media referenced inside a revision follows the defensive-resolution rule already accepted for
  relations — a deleted file degrades, it does not error.
- There is no history of drafts. Everything between two publishes is lost, deliberately.

## Mainstay ships its own authentication

*2026-09-09*

The host Laravel application is the website. It may have no user-facing authentication at all, and
if it does, those users are visitors rather than editors. So Mainstay is fully self-contained: its
own users table, its own guard, its own session cookie, its own login screen.

Most Laravel packages instead defer to the host's guard, and Nova ships its own while defaulting to
the app's. Neither fits here, because the assumption that a host has auth worth reusing does not
hold when the host is a brochure site.

Consequences:

- Two user tables can exist in one application, and an editor who is also a site member has two
  accounts. Accepted deliberately; they are different roles.
- A separate session cookie is a quiet win for static caching. The `.htaccess` rule that skips the
  cache for logged-in users names Mainstay's cookie, so a visitor logged into the host site still
  gets cached pages — only editors bypass them.
- Signed preview URLs are gated by Mainstay's guard, so preview access never depends on how the host
  authenticates anyone.
- The login screen is a Blade view, not part of the admin bundle. There is no reason to ship and
  parse the single-page app to someone who is not authenticated yet.
- There is no registration flow, so the first account comes from an artisan command.
- Password hashing uses Laravel's own hasher. Nothing new is invented here.
- Mainstay's own tables — users, revisions, media, the URI lookup — are ordinary package migrations,
  shipped and versioned normally. The schema-sync decision applies only to tables generated from
  declared content types.
- Pointing Mainstay at the host's guard is not precluded. It would be a config value later, not a
  change of design. Two-factor authentication likewise.

## Globals and taxonomies are both first-class

*2026-09-09*

Three shapes of content exist, not one. Entries have a route and many instances. Globals have one
instance and no route — site settings, a footer, a navigation menu, which otherwise force a content
type to pretend it is a singleton. Taxonomies are terms that group entries, have their own pages, and
list what points at them.

Globals reuse everything: the same field attributes, the same draft row, the same revisions table.
They differ only in having no `#[Route]`, no slug, and a table holding exactly one row. Note that the
attribute cannot be called `Global`, since that is a reserved word in PHP — `Singleton` or similar.

Taxonomies get their routing free, because term pages resolve through the same URI lookup table that
serves entries. What they do not get free is the relation.

**Taxonomies are the exception to the relations decision.** Relations elsewhere are plain IDs
resolved defensively, because nothing needs to query them in reverse. A taxonomy is defined by its
reverse query — a term page exists to list the entries pointing at it — so the pivot has to be real.
A single polymorphic table of `(term_id, entry_type, entry_id)`, indexed both directions, covers every
content type at once and keeps this from becoming a table per pairing.

Consequences:

- A term reference cannot live inside the JSON block column, because the reverse query would not see
  it. Taxonomy fields are entry-level, not block-level.
- Deleting a term is a real delete with a real foreign key, unlike every other relation in the
  system. That inconsistency is deliberate and worth documenting for whoever finds it surprising.
- Terms are flat in the first version. Nested terms mean a parent column, ancestor queries and
  hierarchical URLs, none of which is needed to ship tags and categories.
- Term pages need a template, so terms take `#[Template]` the same way types do.

## Field types are extensible, and the built-ins use the same contract

*2026-09-09*

Whatever field types ship first, the set is open. A website eventually needs something nobody
anticipated, and a closed set turns that into a fork of the package.

Mainstay's own types register through the contract a host would use. That is the only way to know the
contract is adequate — a contract exercised solely by third parties is a contract nobody has tested.

The working set to build against: text, textarea, rich text, number, boolean, date, select, media,
relation, terms, blocks. A repeater is a block with a single type and earns nothing separate.

**Server side.** A field type is a PHP class declaring what column it needs — or that it lives in the
JSON blob — its validation rules, its JSON Schema fragment, and how it casts to and from the
database. A custom field that stores in JSON requires no migration, so adding one is free.

**Admin UI.** A custom field type ships a Blade component, the same way a custom block does on the
site. PHP renders each admin page when it is requested, so it renders whatever files exist at that
moment and a host's field is simply one more of them.

An earlier version of this decision proposed a second layer — custom elements, so a host could inject
a field UI into the pre-built admin bundle. That machinery only existed because a bundle compiled
months ago cannot contain a component written today. Moving the admin to Blade removes the problem
rather than working around it, so the layer is gone. See the admin rendering decision below.

Composition still applies where it helps: a field can declare its interface as an arrangement of
built-in ones — a link field is a url, a text and a select; an SEO field is a text, a textarea and a
media picker — which avoids writing a template at all for the common cases.

Consequences:

- Field types that want real columns still participate in the schema-sync decision, so a custom field
  can require a migration. Only the JSON-stored ones are free.
- A third-party field uses the same Blade components and theme tokens as a built-in one, so it looks
  native by default rather than by effort.
- A field type whose interface is genuinely interactive still needs JavaScript, but it is the host's
  own script on a page they control, not an injection into someone else's bundle.

## The admin is rendered in Blade

*2026-09-09*

The admin was a React single-page application, pre-built into `packages/cms/dist` so a host never runs
a JavaScript build. It becomes Blade instead, with ProseMirror kept as an island for rich text and
Alpine for the parts that hold client state.

The reason React lost on the website — Node on the host's server — never applied here, because the
committed bundle already removed it. What decided it is extensibility. Custom field types need a UI,
and a pre-built bundle can only contain components that existed when it was compiled, so extending it
required custom elements, a mount point, and a permanent public API on the bundle's internals. Blade
renders whatever files exist at request time, so a custom field is just another Blade component and
the entire category of problem disappears.

The counter-argument that carried most weight was that ProseMirror is JavaScript and the editor is
stateful regardless. That turns out not to reach the admin: the editor is an island editing website
content, and the lists, forms, navigation and media browser around it inherit nothing from it.

This is not "Blade is simpler". Blade removes an extensibility problem and adds friction to the
stateful part of the admin — adding, removing and reordering blocks without losing typed-in form
state, the media picker, unsaved-changes handling. Those are easier in React and now need Alpine.

Consequences:

- Roughly twenty presentational components in `packages/ui` — Button, Input, StatusChip, Thumbnail,
  MenuItem, Sidebar — need porting to Blade. They are markup and styling, so the port is mechanical
  and the design is what was valuable. Logic with tests, like `rankCommands`, ports either way.
- Blocks are managed as an Alpine-held JSON array and posted whole on submit, rather than round
  tripping to the server per keystroke.
- There is still a committed bundle, but a much smaller one: the editor island and a stylesheet
  rather than an entire application.
- Two paradigms in one repository is no longer the situation. Blade renders both the site and the
  admin, and a designer touching both needs one skill set.
- The preview decision gets easier, not harder — it was already an iframe rather than a second
  renderer in React.

## packages/ui becomes Blade components, still under Storybook

*2026-09-09*

The design system ports from React to Blade. Storybook stays.

Its components are markup and styling — Button, Input, StatusChip, Thumbnail, MenuItem, Sidebar — so
the port is mechanical and the design is what was worth keeping. Logic with tests behind it, like
`rankCommands`, is framework-agnostic and moves either way.

Storybook renders Blade through `storybook-php`, a framework addon that executes PHP server-side and
renders the resulting HTML, with a `bootstrap` option for the Composer autoloader. Stories stay
TypeScript, importing the component and passing args, so the existing `.stories.tsx` files port
structurally rather than being rewritten.

The risk worth naming: `storybook-php` is a single-maintainer community addon sitting at the centre
of the component workflow. The fallback is Storybook's official HTML renderer with stories fetching
markup from a local route — more wiring, no exotic dependency. Storybook is dev-only either way, so
none of this reaches a host.

## Deferred

Questions raised and deliberately left open. The reasoning is recorded so it does not have to be
rediscovered.

### How rendered pages reach the public — deferred 2026-09-09

Two shapes, neither chosen:

- **Static dump.** An artisan command renders the routes to a folder of HTML that can be served
  anywhere, including a host that runs no application at all. Rebuilds are all-or-nothing and scale
  with the size of the site.
- **Cache on first hit.** Pages render normally and are written to disk, with the web server
  returning that file on later requests so PHP never runs. No build step, new content is live
  immediately, and only pages someone actually visits are ever rendered. Statamic calls these half
  measure and full measure.

Apache does not decide it. Both work there, and the cached approach is in fact easier to deploy on
cheap Apache hosting than on nginx, because the rewrite rules live in `public/.htaccess`, which the
application already owns — no root, no vhost, no restart. The rules need to match GET requests with
no query string, skip anyone holding a session cookie so an editor is not served the stale page they
just published, and otherwise fall through to `index.php`.

What does bear on it is deployment separation. "Headless" names two unrelated things: a JavaScript
front end, and a front end deployed away from the CMS. Only the second is a security property, and
it is indifferent to the template language — a Blade site dumped to a host with no PHP has the same
attack surface as an Astro one, which is none. No PHP execution, no database, no login form, no
admin panel reachable from the internet.

That reduction is binary. Putting the admin on its own hostname with the `domain` option in
`config/mainstay.php` does nothing against it, because the same PHP process still serves both. And
it rules out cached pages, which require the full application to sit on the public host ready to
render on a miss — the cache is a performance layer, not a wall.

The costs of separating are real: forms, search and comments need an endpoint somewhere, so a narrow
route on the CMS box rather than none at all; publishing becomes a deploy with credentials to push
files outward; and preview requires the editor to reach the CMS host directly.

None of this needs settling before content modelling exists. Revisit when there is something to
publish.
