<script setup lang="ts">
defineProps<{
  icon: string
  title: string
  description: string
}>()
</script>

<template>
  <div class="flex flex-col items-center p-6 bg-white border border-gray-100 rounded-xl hover:shadow-md transition-shadow">
    <div class="w-12 h-12 rounded-full bg-violet-100 flex items-center justify-center mb-4">
      <span class="text-2xl">{{ icon }}</span>
    </div>
    <h3 class="text-sm font-semibold text-gray-800 text-center mb-2">{{ title }}</h3>
    <p class="text-xs text-gray-500 text-center leading-relaxed">{{ description }}</p>
  </div>
</template>
