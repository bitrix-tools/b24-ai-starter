<script setup lang="ts">
withDefaults(
  defineProps<{
    /** Меньший блок для шагов мастера установки (шаг «Приветствие»). */
    compact?: boolean
  }>(),
  { compact: false },
)
</script>

<template>
  <!-- Полноэкранная загрузка: 14rem; compact — тот же логотип, меньше квадрат -->
  <div
    class="rounded-2xl flex items-center justify-center bg-[#7F42E1] shadow-md box-border"
    :class="
      compact
        ? 'px-3 py-3 min-h-[7rem] min-w-[7rem]'
        : 'px-4 py-3.5 min-h-[14rem] min-w-[14rem]'
    "
    aria-hidden="true"
  >
    <img
      src="/tochka-logo-white.png"
      alt=""
      width="120"
      height="48"
      loading="eager"
      fetchpriority="high"
      :class="
        compact
          ? 'h-10 w-auto max-w-[6.5rem] object-contain object-center select-none pointer-events-none'
          : 'h-[3.655rem] w-auto max-w-[9.35rem] object-contain object-center select-none pointer-events-none'
      "
    />
  </div>
</template>
