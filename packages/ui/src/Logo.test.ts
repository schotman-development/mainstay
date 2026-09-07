import { renderToStaticMarkup } from 'react-dom/server'
import { createElement } from 'react'
import { expect, test } from 'vitest'
import { LogoMark } from './Logo'

const render = (size: number) => renderToStaticMarkup(createElement(LogoMark, { size }))

const inLockup = (size: number) => {
  const svg = renderToStaticMarkup(createElement(LogoMark, { size, lockup: true }))

  return {
    paths: [...svg.matchAll(/<path d="([^"]+)"/g)].map((match) => match[1]),
    stroke: Number(/stroke-width="(\d+)"/.exec(svg)?.[1]),
  }
}

const drawn = (size: number) => {
  const svg = render(size)

  return {
    paths: [...svg.matchAll(/<path d="([^"]+)"/g)].map((match) => match[1]),
    stroke: Number(/stroke-width="(\d+)"/.exec(svg)?.[1]),
  }
}

const TRIANGLE = 'M60 24 22 100h76L60 24Z'

/*
 | The four rungs the design specifies, asserted as exact path data rather than
 | path counts: the mast lengths and the 16px triangle's raised apex are the
 | fidelity, and a count-only check would not notice either changing.
 */
test('64px is the full construction', () => {
  expect(drawn(64)).toEqual({ paths: ['M60 24v76', TRIANGLE, 'M36 70h48'], stroke: 10 })
})

test('32px shortens the mast to clear the apex', () => {
  expect(drawn(32)).toEqual({ paths: ['M60 40v60', TRIANGLE, 'M36 70h48'], stroke: 11 })
})

test('24px shortens the mast again and drops the spreader', () => {
  expect(drawn(24)).toEqual({ paths: ['M60 50v50', TRIANGLE], stroke: 11 })
})

/* The size the top bar actually uses; it must not fall to the favicon form. */
test('18px keeps the mast', () => {
  expect(drawn(18)).toEqual({ paths: ['M60 50v50', TRIANGLE], stroke: 11 })
})

test('16px is the triangle alone, apex raised so the counter stays open', () => {
  expect(drawn(16)).toEqual({ paths: ['M60 26 22 100h76L60 26Z'], stroke: 13 })
})

/*
 | Both sides of every breakpoint. Without the lower half, a boundary moved down
 | -- < 32 becoming < 31 -- changes no design-specified size and passes silently.
 */
test('each breakpoint switches between its two neighbours and nowhere else', () => {
  expect(drawn(17)).toEqual(drawn(16))
  expect(drawn(18)).not.toEqual(drawn(17))
  expect(drawn(18)).toEqual(drawn(24))
  expect(drawn(31)).toEqual(drawn(24))
  expect(drawn(32)).not.toEqual(drawn(31))
  expect(drawn(39)).toEqual(drawn(32))
  expect(drawn(40)).not.toEqual(drawn(39))
  expect(drawn(40)).toEqual(drawn(64))
})

test('the mark is decorative unless it is given a label', () => {
  expect(render(24)).toContain('aria-hidden="true"')
  expect(renderToStaticMarkup(createElement(LogoMark, { size: 24, label: 'Mainstay' }))).toContain(
    'role="img"',
  )
})

/*
 | The ladder is for a mark standing on its own. Beside the wordmark the mast has
 | to survive at any size, or the top bar shows a stray arrowhead next to the
 | word Mainstay.
 */
test('a lockup holds the 24px geometry however small it is drawn', () => {
  expect(inLockup(16)).toEqual(drawn(24))
  expect(inLockup(12)).toEqual(drawn(24))
  expect(inLockup(64)).toEqual(drawn(64))
})

/* Standing alone, 16px is still the design's mast-less rung. */
test('the floor applies only inside a lockup', () => {
  expect(drawn(16).paths).toEqual(['M60 26 22 100h76L60 26Z'])
})
