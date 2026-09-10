import type { PhpComponent } from 'storybook-php'

/*
 | `id` on a meta, for the typechecker.
 |
 | Storybook reads it to give several CSF files one component id, which is what
 | puts Input, Input/Composite and Input/Every control under a single sidebar
 | entry instead of three. storybook-php 0.3.0 hand-writes its own Meta and
 | leaves the field out, so the runtime honours what the type rejects.
 |
 | Merging requires the type parameter to be repeated exactly as declared.
 */
declare module 'storybook-php' {
  interface Meta<TComponent extends PhpComponent = PhpComponent> {
    id?: string
  }
}
