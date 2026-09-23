# Mainstay

A headless CMS for Laravel. Content is modelled in PHP, edited in a Blade admin panel that the
package mounts into your app, and served over HTTP to whatever builds your front end — Astro,
Hugo, Eleventy, Next, or a Blade app that just wants content.

Mainstay owns its own tables inside your Laravel application. "Headless" describes the consumer,
not the storage: your static site is the external thing, and the content API is the only contract
between the two.

> **Status: early.** Content types are declared in PHP, `mainstay:sync` alters the database to match
> them, and content is read and written from PHP through `Mainstay::find()`, `create()`, `update()`
> and `delete()`. Nothing renders it yet: there is no public routing, no authentication, no admin
> form, and no block editor. `docs/plan.md` has the order the rest arrives in.

## Repository layout

| Path | Package | What it is |
| --- | --- | --- |
| `packages/cms` | `mainstay/cms` | The Laravel package: service provider, routes, content API, and the built admin bundle |
| `packages/cms/resources/js` | `@mainstay/admin` | The admin's client bundle: the editor island, plus the design system's behaviour. Private — built into `packages/cms/dist`, never published |
| `packages/ui` | `mainstay/ui`, `@mainstay/ui` | Design system: Blade components as a Composer package; theme tokens and the components' own behaviour as an npm one |
| `packages/editor` | `@mainstay/editor` | The content editor, built directly on ProseMirror |

`packages/cms/dist` is committed on purpose. A host installs Mainstay with Composer and must never
be asked to run a JavaScript build to get an admin panel.

## Installing into an app

```bash
composer require mainstay/cms
php artisan vendor:publish --tag=mainstay-assets
```

The admin is then at `/admin` and the content API at `/api/mainstay`. Both paths, their middleware,
and an optional dedicated domain are configurable:

```bash
php artisan vendor:publish --tag=mainstay-config
```

Re-run the asset publish with `--force` after upgrading the package.

## Working on Mainstay

```bash
composer install
pnpm install
pnpm build            # editor, then the admin bundle into packages/cms/dist
composer serve        # a real Laravel app at http://127.0.0.1:8000/admin
```

The dev app comes from [Testbench](https://packages.tools/testbench) — `testbench.yaml` describes
it and `workbench/` is generated, so there is no checked-in playground application to drift.

```bash
composer test         # PHPUnit, via Testbench
composer lint         # Pint
pnpm typecheck
pnpm test             # Vitest
```

The design system has a Storybook. Components are developed there rather than by
round-tripping through the admin panel:

```bash
pnpm --filter @mainstay/ui storybook          # :6106, bound to 0.0.0.0 for LAN access
pnpm --filter @mainstay/ui build-storybook    # compiles every story and the theme; CI runs this
```

The static build is a check, not a site: rendering a story needs the PHP process the dev server
runs beside it, so `storybook-static` will not draw components on its own.

Stories stay TypeScript and import the Blade file they render: `storybook-php` runs PHP
server-side and hands Storybook the markup back. `.storybook/bootstrap.php` boots Testbench so
the components compile through the same Blade the admin uses. Stories live in `packages/ui/stories`
rather than beside the components, which is what keeps the workshop's own utilities out of a
consumer's stylesheet. Telemetry is disabled in `.storybook/main.ts`.

The behaviour the platform gives no markup for — dropdown dismissal, the sidebar's fold, the tag
input, the command centre, list selection, unsaved-changes — lives in `packages/ui/src/js` beside
the components it belongs to, and both the admin bundle and Storybook mount the same `mount()`. A
component whose behaviour only existed in the app would be a component the workshop could not
actually exercise. `mount()` is safe to call again, which is what lets Storybook re-run it after
every story render.

`pnpm dev` runs every package in watch mode, so a change in `@mainstay/editor` or in the design
system's behaviour rebuilds the admin bundle that `composer serve` is already serving. The Blade
components themselves need no build at all.

## Licence

MIT.
