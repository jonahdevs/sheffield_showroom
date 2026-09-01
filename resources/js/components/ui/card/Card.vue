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
  Unlike upstream, the padding lives here rather than on the header, content
  and footer - which is what lets a card wanting its own bands opt out with
  `p-0` and rule them off itself.
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
