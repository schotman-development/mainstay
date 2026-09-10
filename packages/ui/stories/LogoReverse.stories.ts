import type { Meta, StoryObj } from 'storybook-php'
import LogoReverse from './blade/logo-reverse.blade.php'

const meta: Meta<typeof LogoReverse> = {
  component: LogoReverse,
  id: 'logo',
  title: 'Logo/Reverse',
  parameters: { controls: { disable: true } },
}

export default meta

export const Reverse: StoryObj<typeof LogoReverse> = {}
