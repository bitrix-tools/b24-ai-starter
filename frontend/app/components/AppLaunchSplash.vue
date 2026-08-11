<script setup lang="ts">
import SplashBrandMark from '~/components/SplashBrandMark.vue'
</script>

<template>
  <div
    class="launch-loader"
    role="status"
    aria-live="polite"
    aria-label="Загрузка приложения"
  >
    <div class="launch-loader__orbit">
      <div class="launch-loader__ring launch-loader__ring--outer" aria-hidden="true" />
      <div class="launch-loader__ring launch-loader__ring--inner" aria-hidden="true" />
      <SplashBrandMark compact class="launch-loader__brand" />
    </div>

    <p class="launch-loader__label">
      Загрузка приложения…
    </p>
  </div>
</template>

<style scoped>
.launch-loader {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1.5rem;
  user-select: none;
}

.launch-loader__orbit {
  position: relative;
  display: flex;
  width: 9rem;
  height: 9rem;
  align-items: center;
  justify-content: center;
}

.launch-loader__ring {
  position: absolute;
  border-style: solid;
  border-color: transparent;
  border-radius: 9999px;
  pointer-events: none;
}

.launch-loader__brand {
  width: 7rem;
  height: 7rem;
}

.launch-loader__ring--outer {
  inset: 0;
  border-width: 3px;
  border-top-color: #7f42e1;
  border-right-color: rgb(127 66 225 / 35%);
  animation: launch-loader-spin 1.15s linear infinite;
}

.launch-loader__ring--inner {
  inset: 0.5rem;
  border-width: 2px;
  border-bottom-color: #ffdd2d;
  border-left-color: rgb(255 221 45 / 45%);
  animation: launch-loader-spin-reverse 1.6s linear infinite;
}

.launch-loader__label {
  margin: 0;
  color: #6b7280;
  font-size: 0.875rem;
  line-height: 1.4;
}

@keyframes launch-loader-spin {
  to {
    transform: rotate(360deg);
  }
}

@keyframes launch-loader-spin-reverse {
  to {
    transform: rotate(-360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .launch-loader__ring {
    animation: none;
  }
}
</style>
