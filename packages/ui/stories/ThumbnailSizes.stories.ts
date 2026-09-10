import type { Meta, StoryObj } from 'storybook-php'
import ThumbnailSet from './blade/thumbnail-set.blade.php'

const meta: Meta<typeof ThumbnailSet> = {
  component: ThumbnailSet,
  id: 'thumbnail',
  title: 'Thumbnail/Sizes',
  parameters: { controls: { disable: true } },
}

export default meta

export const Sizes: StoryObj<typeof ThumbnailSet> = {}

/*
 | The empty states at the same sizes. The pairing is the point: each box is
 | exactly the size the image would have been, so choosing one shifts nothing
 | below it.
 */
export const Empty: StoryObj<typeof ThumbnailSet> = { args: { empty: true } }
