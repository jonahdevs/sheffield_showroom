<script setup lang="ts">
import type { PrimitiveProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { Primitive } from "reka-ui"
import { cn } from "@/lib/utils"

interface Props extends PrimitiveProps {
  class?: HTMLAttributes["class"]
}

const props = withDefaults(defineProps<Props>(), {
  as: "div",
})
</script>

<!--
  A card is a bordered surface, not a floating one: the 1px edge does the
  separating and the drop shadow is gone, so a page of cards reads flat rather
  than as a pile of tiles.

  The padding lives here rather than on the header, content and footer, which
  is what lets a card that wants its own bands opt out with `p-0` and rule
  them off itself. `as` is forwarded so a card can be the <section> it
  semantically is.
-->
<template>
  <Primitive
    data-slot="card"
    :as="as"
    :as-child="asChild"
    :class="
      cn(
        'bg-card text-card-foreground flex flex-col gap-0 rounded-xl border border-border p-5',
        props.class,
      )
    "
  >
    <slot />
  </Primitive>
</template>
