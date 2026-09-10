import type { Meta, StoryObj } from 'storybook-php'
import LogoSizes from './blade/logo-sizes.blade.php'

const meta: Meta<typeof LogoSizes> = {
  component: LogoSizes,
  id: 'logo',
  title: 'Logo/Sizes',
  parameters: { controls: { disable: true } },
}

export default meta

export const Sizes: StoryObj<typeof LogoSizes> = {}
