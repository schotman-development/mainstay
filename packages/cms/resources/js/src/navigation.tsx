import { Icon, PathIcon } from '@mainstay/ui'
import type { SidebarSection } from '@mainstay/ui'

/*
 | Where the panel is mounted. The path is configurable (mainstay.path), so the
 | links cannot assume /admin any more than api.ts can assume /api/mainstay --
 | Blade hands us the resolved route the same way it hands us the API base.
 */
const base = (() => {
  const meta = document.querySelector<HTMLMetaElement>('meta[name="mainstay-admin"]')?.content
  const path = meta ? new URL(meta, location.origin).pathname : '/admin'

  return path.replace(/\/+$/, '')
})()

export const navigation: SidebarSection[] = [
  {
    /* No group name: the root of the panel is not a category of anything. */
    items: [
      {
        href: base || '/',
        label: 'Dashboard',
        icon: <PathIcon d="M3.25 1.75h9.5a1.5 1.5 0 0 1 1.5 1.5v9.5a1.5 1.5 0 0 1-1.5 1.5h-9.5a1.5 1.5 0 0 1-1.5-1.5v-9.5a1.5 1.5 0 0 1 1.5-1.5zM5.25 10.75V7.5M8 10.75V5.25M10.75 10.75V8.75" />,
      },
    ],
  },
  {
    label: 'Content',
    items: [
      {
        href: `${base}/pages`,
        label: 'Pages',
        icon: (
          <Icon>
            <path d="M9.5 1.75H4.5a1 1 0 0 0-1 1v10.5a1 1 0 0 0 1 1h7a1 1 0 0 0 1-1V4.75z" />
            <path d="M9.5 1.75V4.75h3" />
          </Icon>
        ),
      },
      {
        href: `${base}/collections`,
        label: 'Collections',
        icon: (
          <Icon>
            <path d="M8 1.75 1.75 5 8 8.25 14.25 5 8 1.75Z" />
            <path d="M1.75 10.75 8 14 14.25 10.75" />
          </Icon>
        ),
      },
      {
        href: `${base}/media`,
        label: 'Media',
        icon: (
          <Icon>
            <rect x="1.75" y="2.75" width="12.5" height="10.5" rx="1.5" />
            <path d="M1.75 11 5.5 7.25l2.75 2.75 2-2 4 4" />
            <circle cx="10.75" cy="5.75" r=".9" />
          </Icon>
        ),
      },
    ],
  },
  {
    label: 'Structure',
    items: [
      {
        href: `${base}/types`,
        label: 'Content types',
        icon: (
          <Icon>
            <rect x="1.75" y="1.75" width="5.5" height="5.5" rx="1" />
            <rect x="8.75" y="1.75" width="5.5" height="5.5" rx="1" />
            <rect x="1.75" y="8.75" width="5.5" height="5.5" rx="1" />
            <rect x="8.75" y="8.75" width="5.5" height="5.5" rx="1" />
          </Icon>
        ),
      },
      {
        href: `${base}/taxonomies`,
        label: 'Taxonomies',
        icon: (
          <Icon>
            <path d="M8.2 1.75H2.5a.75.75 0 0 0-.75.75v5.7c0 .2.08.39.22.53l5.55 5.55a.75.75 0 0 0 1.06 0l5.2-5.2a.75.75 0 0 0 0-1.06L8.73 1.97a.75.75 0 0 0-.53-.22Z" />
            <circle cx="5.1" cy="5.1" r=".9" />
          </Icon>
        ),
      },
    ],
  },
  {
    label: 'System',
    items: [
      {
        href: `${base}/users`,
        label: 'Users',
        icon: (
          <Icon>
            <circle cx="8" cy="5.25" r="2.75" />
            <path d="M2.75 14.25a5.25 5.25 0 0 1 10.5 0" />
          </Icon>
        ),
      },
      {
        href: `${base}/settings`,
        label: 'Settings',
        icon: (
          <Icon>
            <path d="M1.75 4.75h12.5M1.75 11.25h12.5" />
            <circle cx="6" cy="4.75" r="1.75" />
            <circle cx="10" cy="11.25" r="1.75" />
          </Icon>
        ),
      },
    ],
  },
]
