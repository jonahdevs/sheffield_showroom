import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

export const buttonVariants = cva(
  "inline-flex cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm leading-normal font-semibold transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-3 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        // The solid variants carry a transparent border so they stand exactly
        // as tall as the outlined ones beside them.
        // Removed variants: `quiet` and `brand` - use `outline` and `default`.
        // Do not add a seventh; one-off variants are how two buttons doing the
        // same job end up looking different.
        default:
          "border border-transparent bg-primary text-primary-foreground hover:bg-primary/90",
        destructive:
          "border border-transparent bg-destructive text-white hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 dark:bg-destructive/60",
        outline:
          "border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50",
        secondary:
          "border border-transparent bg-secondary text-secondary-foreground hover:bg-secondary/80",
        ghost:
          "hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50",
        link: "text-primary underline-offset-4 hover:underline",
      },
      size: {
        "default": "px-[22px] py-3 has-[>svg]:px-4",
        "xs": "gap-1.5 rounded-sm px-3.5 py-1.75 text-xs leading-normal has-[>svg]:px-3 [&_svg:not([class*='size-'])]:size-3.5",
        "sm": "rounded-[9px] gap-1.5 px-3.5 py-[9px] text-xs has-[>svg]:px-3",
        "lg": "h-10 rounded-md px-6 has-[>svg]:px-4",
        "xl": "h-12 rounded-md px-10 has-[>svg]:px-8",
        "icon": "size-9",
        "icon-xs": "size-6 rounded-md [&_svg:not([class*='size-'])]:size-3",
        "icon-sm": "size-8",
        "icon-lg": "size-10",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
