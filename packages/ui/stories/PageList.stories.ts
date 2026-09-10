import type { Meta, StoryObj } from 'storybook-php'
import PageList from './blade/page-list.blade.php'

const meta: Meta<typeof PageList> = {
  component: PageList,
  title: 'Page list',
  parameters: { layout: 'fullscreen', controls: { disable: true } },
}

export default meta

type Story = StoryObj<typeof PageList>

export const Pages: Story = {}

/* The same list, and the reason it takes entries rather than reading a store. */
export const Posts: Story = { args: { entries: 'posts', label: 'Posts', action: 'New post' } }

/*
 | Enough rows that the footer has something to do, and that the rows are the
 | only thing scrolling while the bar and the footer stay put.
 |
 | Sorting and paging are links carrying the view in the query string, which is
 | what replaces React's useState. Storybook has no server route behind the
 | preview, so a story shows a state rather than stepping through them -- the
 | screen itself pages normally once it is mounted in the admin.
 */
export const Paginated: Story = { args: { entries: 'many' } }

/* The second page of that list, which is the state the pager's links produce. */
export const SecondPage: Story = { args: { entries: 'many', view: { page: 2 } } }

/* What a fresh install opens on. */
export const Empty: Story = { args: { entries: 'none' } }
