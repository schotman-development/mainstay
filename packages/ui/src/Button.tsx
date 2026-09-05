import type { ButtonHTMLAttributes } from 'react'

const variants = {
  primary: 'bg-accent text-accent-ink hover:opacity-90',
  secondary: 'bg-surface text-ink border border-border hover:bg-canvas',
  danger: 'bg-danger text-accent-ink hover:opacity-90',
} as const

export type ButtonVariant = keyof typeof variants

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: ButtonVariant
}

export function Button({ variant = 'primary', className, ...props }: ButtonProps) {
  return (
    <button
      className={[
        'inline-flex items-center justify-center gap-2 rounded-control px-3 py-1.5',
        'text-sm font-medium transition-opacity',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
        'disabled:pointer-events-none disabled:opacity-50',
        variants[variant],
        className,
      ]
        .filter(Boolean)
        .join(' ')}
      {...props}
    />
  )
}
