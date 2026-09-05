import type { Meta, StoryObj } from '@storybook/react-vite'
import themeCss from './theme.css?raw'

type Token = {
  name: string
  namespace: string
  value: string
}

/*
 | The tokens are read out of theme.css rather than listed here, so this page
 | cannot drift from the source of truth. Reading them back off the document
 | instead would be incomplete: Tailwind only emits the theme variables a
 | build actually uses, so an unreferenced token would silently vanish.
 */
const tokens: Token[] = [...themeCss.matchAll(/^\s*--(([a-z]+)[a-z0-9-]*):\s*([^;]+);/gim)].map(
  (match) => ({
    name: `--${match[1]}`,
    namespace: match[2]!,
    value: match[3]!.trim(),
  }),
)

const namespaces = [...new Set(tokens.map((token) => token.namespace))]

function Swatch({ token }: { token: Token }) {
  const swatch = `var(${token.name})`

  switch (token.namespace) {
    case 'color':
      return (
        <div
          className="size-12 shrink-0 rounded-control border border-border"
          style={{ background: swatch }}
        />
      )
    case 'radius':
      return (
        <div
          className="size-12 shrink-0 border-2 border-accent"
          style={{ borderRadius: swatch }}
        />
      )
    case 'font':
      return (
        <div
          className="flex size-12 shrink-0 items-center justify-center text-lg"
          style={{ fontFamily: swatch }}
        >
          Ag
        </div>
      )
    default:
      return <div className="size-12 shrink-0" />
  }
}

function TokenTable() {
  return (
    <div className="flex flex-col gap-8">
      {namespaces.map((namespace) => (
        <section key={namespace} className="flex flex-col gap-2">
          <h2 className="text-xs font-semibold uppercase tracking-wide text-muted">{namespace}</h2>

          <div className="divide-y divide-border overflow-hidden rounded-control border border-border bg-surface">
            {tokens
              .filter((token) => token.namespace === namespace)
              .map((token) => (
                <div key={token.name} className="flex items-center gap-4 p-3">
                  <Swatch token={token} />

                  <div className="min-w-0">
                    <code className="font-mono text-sm text-ink">{token.name}</code>
                    <p className="truncate font-mono text-xs text-muted">{token.value}</p>
                  </div>
                </div>
              ))}
          </div>
        </section>
      ))}
    </div>
  )
}

const meta = {
  render: () => <TokenTable />,
  parameters: { controls: { disable: true } },
} satisfies Meta

export default meta

export const Tokens: StoryObj<typeof meta> = {}
