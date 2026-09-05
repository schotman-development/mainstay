import { useEffect, useState } from 'react'
import { Editor } from '@mainstay/editor'
import { Button } from '@mainstay/ui'
import { api } from './api'

type Status = { name: string; version: string }

export function App() {
  const [status, setStatus] = useState<Status | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api<Status>()
      .then(setStatus)
      .catch((cause: unknown) => setError(cause instanceof Error ? cause.message : String(cause)))
  }, [])

  return (
    <div className="min-h-dvh bg-canvas font-sans text-ink">
      <header className="flex items-center justify-between border-b border-border px-6 py-3">
        <div className="flex items-baseline gap-2">
          <span className="text-sm font-semibold">Mainstay</span>
          <span className="text-xs text-muted">
            {error ?? (status ? `v${status.version}` : 'connecting…')}
          </span>
        </div>
        <Button variant="secondary" disabled>
          Publish
        </Button>
      </header>

      <main className="mx-auto max-w-3xl px-6 py-10">
        <Editor className="min-h-64 rounded-control border border-border bg-surface p-4" />
      </main>
    </div>
  )
}
