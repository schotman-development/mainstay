/// <reference types="vite/client" />

/*
 | What a `.blade.php` import is, for the typechecker.
 |
 | storybook-php ships this as `storybook-php/client`, but 0.3.0 leaves the file
 | out of its package exports and points it at a dist path it does not build, so
 | bundler resolution cannot reach it. Four lines here rather than a resolution
 | mode loosened for the whole package. The editor plugin still fills in each
 | component's real args on top of this.
 */
declare module '*.blade.php' {
  const component: import('storybook-php').PhpComponent

  export default component
}
