<script setup lang="ts">
const LOGO = '/images/sheffield-logo.png';
</script>

<!--
  A page escapes the `AppLayout` default in `app.ts` only by naming a layout:
  `layout: null` falls through Inertia's nullish coalesce back to the default,
  so this has to exist. The bar is white in both themes because the wordmark's
  navy half disappears on the near-black dark-mode page.
-->
<template>
    <div class="flex min-h-svh flex-col bg-background">
        <header class="sticky top-0 z-10 border-b border-black/10 bg-white">
            <div class="container mx-auto px-5 py-3">
                <img
                    :src="LOGO"
                    alt="Sheffield Africa"
                    class="h-8 w-auto"
                    width="480"
                    height="161"
                />
            </div>
        </header>

        <main class="flex flex-1 flex-col">
            <!--
              `my-auto` not `justify-center`: a centred flex child that
              overflows is clipped at the top with no way to scroll back up.
              The bottom padding is written out by hand so the safe-area inset
              keeps a phone's home indicator off the last of the content; this
              screen opens from a QR code and is never on a desktop.
            -->
            <div
                class="container mx-auto my-auto w-full px-5 pt-8"
                style="padding-bottom: calc(2rem + env(safe-area-inset-bottom))"
            >
                <slot />
            </div>
        </main>
    </div>
</template>
