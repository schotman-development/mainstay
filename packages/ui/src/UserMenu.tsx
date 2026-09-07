import { Dropdown, DropdownItem } from './Dropdown'

export type UserMenuProps = {
  name: string
  email?: string
  onAccountSettings: () => void
  onSignOut: () => void
  className?: string
}

export function UserMenu({ name, email, onAccountSettings, onSignOut, className }: UserMenuProps) {
  return (
    <Dropdown
      align="end"
      className={className}
      triggerClassName="h-6 px-1.5 text-xs font-medium text-ink hover:bg-canvas"
      label={
        <>
          <span
            aria-hidden="true"
            className="flex size-5 items-center justify-center rounded-full bg-accent text-[0.6875rem] font-semibold text-accent-ink"
          >
            {initials(name)}
          </span>
          <span className="max-w-40 truncate">{name}</span>
        </>
      }
    >
      {/* Widens the panel past its min-w-48 and gives the truncating email
          something to truncate against. */}
      <div className="w-56 border-b border-border px-3 pb-2 pt-1.5">
        <p className="truncate text-sm font-medium text-ink">{name}</p>
        {email && <p className="truncate text-xs text-muted">{email}</p>}
      </div>

      <DropdownItem onClick={onAccountSettings}>Account settings</DropdownItem>
      <DropdownItem onClick={onSignOut} danger>
        Sign out
      </DropdownItem>
    </Dropdown>
  )
}

function initials(name: string): string {
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word[0] ?? '')
    .join('')
    .toUpperCase()
}
