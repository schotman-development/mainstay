# Mainstay

A headless CMS for Laravel. Content is modelled in PHP, edited in a React admin panel that the
package mounts into your app, and served over HTTP to whatever builds your front end — Astro,
Hugo, Eleventy, Next, or a Blade app that just wants content.

Mainstay owns its own tables inside your Laravel application. "Headless" describes the consumer,
not the storage: your static site is the external thing, and the content API is the only contract
between the two.

> **Status: skeleton.** The packages build, link, and boot inside a real Laravel app. Content
> modelling, the field system, and the block editor are not built yet.

## Repository layout

| Path | Package | What it is |
| --- | --- | --- |
| `packages/cms` | `mainstay/cms` | The Laravel package: service provider, routes, content API, and the built admin bundle |
| `packages/cms/resources/js` | `@mainstay/admin` | The admin single-page app. Private — built into `packages/cms/dist`, never published |
| `packages/ui` | `@mainstay/ui` | Design system: theme tokens and React components |
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
pnpm build            # ui + editor, then the admin bundle into packages/cms/dist
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
pnpm --filter @mainstay/ui build-storybook    # static build, to packages/ui/storybook-static
```

Stories are colocated with their components as `src/*.stories.tsx`. Telemetry is
disabled in `.storybook/main.ts`.

`pnpm dev` runs every package in watch mode, so a change in `@mainstay/ui` or `@mainstay/editor`
rebuilds the admin bundle that `composer serve` is already serving.

## Licence

MIT.
