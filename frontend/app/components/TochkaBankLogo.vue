<script setup lang="ts">
/** Стилизованное написание «точка банк»: фиолетовый + жёлтая «о» в духе бренда Точка. */
const purple = '#5B2D82'
const yellow = '#FFDD2D'
</script>

<template>
  <svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 260 64"
    class="max-w-full h-auto"
    role="img"
    aria-label="Точка Банк"
  >
    <title>Точка Банк</title>
    <text
      x="0"
      y="38"
      :fill="purple"
      font-family="system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"
      font-size="36"
      font-weight="700"
    >
      т
    </text>
    <!-- буква «о» — жёлтый круг (фирменный акцент) -->
    <circle cx="52" cy="24" r="17" :fill="yellow" />
    <text
      x="74"
      y="38"
      :fill="purple"
      font-family="system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"
      font-size="36"
      font-weight="700"
    >
      чка
    </text>
    <text
      x="0"
      y="58"
      :fill="purple"
      font-family="system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"
      font-size="13"
      font-weight="600"
      opacity="0.92"
    >
      банк
    </text>
  </svg>
</template>
