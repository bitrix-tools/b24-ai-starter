<script setup lang="ts">
const props = defineProps<{
  currentStep: number
  steps: Array<{ id: number; label: string }>
}>()
</script>

<template>
  <div class="flex items-center justify-center py-6 px-4">
    <div class="flex items-center">
      <template v-for="(step, index) in steps" :key="step.id">
        <!-- Step -->
        <div class="flex flex-col items-center">
          <!-- Circle -->
          <div
            class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold transition-all border-2"
            :class="{
              'bg-green-500 border-green-500 text-white': currentStep > step.id,
              'bg-violet-600 border-violet-600 text-white': currentStep === step.id,
              'bg-white border-gray-300 text-gray-400': currentStep < step.id,
            }"
          >
            <template v-if="currentStep > step.id">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
              </svg>
            </template>
            <template v-else>
              {{ step.id }}
            </template>
          </div>
          <!-- Label -->
          <span
            class="text-[10px] mt-1 whitespace-nowrap"
            :class="{
              'text-green-600': currentStep > step.id,
              'text-violet-600 font-medium': currentStep === step.id,
              'text-gray-400': currentStep < step.id,
            }"
          >
            {{ step.label }}
          </span>
        </div>

        <!-- Connector line -->
        <div
          v-if="index < steps.length - 1"
          class="w-12 h-[2px] mx-1 mb-4"
          :class="{
            'bg-green-500': currentStep > step.id,
            'bg-gray-200': currentStep <= step.id,
          }"
        />
      </template>
    </div>
  </div>
</template>
