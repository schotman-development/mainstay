import { useEffect, useState } from 'react'
import { Editor } from '@mainstay/editor'
import { Button, Logo, Sidebar } from '@mainstay/ui'
import { api } from './api'
import { navigation } from './navigation'

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
    <div className="flex h-dvh flex-col bg-canvas font-sans text-ink">
      <header className="flex shrink-0 items-center justify-between border-b border-border px-6 py-3">
        <div className="flex items-center gap-2">
          <Logo size={16} />
          <span className="text-xs text-muted">
            {error ?? (status ? `v${status.version}` : 'connecting…')}
          </span>
        </div>
        <Button variant="secondary" disabled>
          Publish
        </Button>
      </header>

      <div className="flex min-h-0 flex-1">
        <Sidebar sections={navigation} current={location.pathname} />

        <main className="min-w-0 flex-1 overflow-y-auto px-6 py-10">
          <div className="mx-auto max-w-3xl">
            <Editor className="min-h-64 rounded-control border border-border bg-surface p-4" />
          </div>
        </main>
      </div>
    </div>
  )
}
