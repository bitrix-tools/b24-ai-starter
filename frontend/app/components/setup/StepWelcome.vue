<script setup lang="ts">
import SplashBrandMark from '~/components/SplashBrandMark.vue'

defineEmits<{
  (e: 'next'): void
}>()
</script>

<template>
  <div class="flex flex-col items-center justify-center min-h-[500px] px-8 py-16">
    <!-- Тот же логотип Точка, что на экране загрузки приложения -->
    <div class="mb-5 shrink-0">
      <SplashBrandMark compact />
    </div>

    <!-- Title -->
    <h1 class="text-2xl font-bold text-gray-900 mb-1 text-center whitespace-nowrap">Точка Банк. Интеграция</h1>
    <p class="text-gray-500 text-sm mb-12 text-center whitespace-nowrap">Настроим интеграцию с Точка Банком за несколько минут</p>

    <!-- Features: сетка 3×2 (три колонки, два ряда) на широком экране -->
    <div
      class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-14 w-full max-w-[720px] mx-auto"
    >
      <!-- Card 1 -->
      <div class="min-w-0 w-full flex flex-col items-center py-8 px-5 bg-white border border-gray-100 rounded-2xl">
        <div class="w-14 h-14 rounded-2xl bg-white flex items-center justify-center mb-5 shadow-sm">
          <svg class="w-7 h-7 text-violet-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
        </div>
        <h3 class="text-sm font-semibold text-gray-800 text-center mb-2 min-h-[2.5rem] flex items-center justify-center">Импорт платежей</h3>
        <p class="text-xs text-gray-500 text-center leading-relaxed">Автоматическая загрузка из Точка Банка</p>
      </div>

      <!-- Card 2 -->
      <div class="min-w-0 w-full flex flex-col items-center py-8 px-5 bg-white border border-gray-100 rounded-2xl">
        <div class="w-14 h-14 rounded-2xl bg-white flex items-center justify-center mb-5 shadow-sm">
          <svg class="w-7 h-7 text-violet-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
          </svg>
        </div>
        <h3 class="text-sm font-semibold text-gray-800 text-center mb-2 min-h-[2.5rem] flex items-center justify-center">Привязка к сделкам</h3>
        <p class="text-xs text-gray-500 text-center leading-relaxed">Сопоставление по номеру счёта</p>
      </div>

      <!-- Card 3 -->
      <div class="min-w-0 w-full flex flex-col items-center py-8 px-5 bg-white border border-gray-100 rounded-2xl">
        <div class="w-14 h-14 rounded-2xl bg-white flex items-center justify-center mb-5 shadow-sm">
          <svg class="w-7 h-7 text-violet-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
        </div>
        <h3 class="text-sm font-semibold text-gray-800 text-center mb-2 min-h-[2.5rem] flex items-center justify-center">Контроль оплат</h3>
        <p class="text-xs text-gray-500 text-center leading-relaxed">Статусы в реальном времени</p>
      </div>

      <!-- Card 4: эквайринг -->
      <div class="min-w-0 w-full flex flex-col items-center py-8 px-5 bg-white border border-gray-100 rounded-2xl">
        <div class="w-14 h-14 rounded-2xl bg-white flex items-center justify-center mb-5 shadow-sm">
          <svg class="w-7 h-7 text-violet-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
          </svg>
        </div>
        <h3 class="text-sm font-semibold text-gray-800 text-center mb-2 min-h-[2.5rem] flex items-center justify-center">Оплаты по ссылке</h3>
        <p class="text-xs text-gray-500 text-center leading-relaxed">Карты, СБП — из карточки сделки или лида</p>
      </div>

      <!-- Card 5: уведомления -->
      <div class="min-w-0 w-full flex flex-col items-center py-8 px-5 bg-white border border-gray-100 rounded-2xl">
        <div class="w-14 h-14 rounded-2xl bg-white flex items-center justify-center mb-5 shadow-sm">
          <svg class="w-7 h-7 text-violet-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
          </svg>
        </div>
        <h3 class="text-sm font-semibold text-gray-800 text-center mb-2 min-h-[2.5rem] flex items-center justify-center">Уведомления в CRM</h3>
        <p class="text-xs text-gray-500 text-center leading-relaxed">Ответственному, в чат и в таймлайн</p>
      </div>

      <!-- Card 6: счета и воронка -->
      <div class="min-w-0 w-full flex flex-col items-center py-8 px-5 bg-white border border-gray-100 rounded-2xl">
        <div class="w-14 h-14 rounded-2xl bg-white flex items-center justify-center mb-5 shadow-sm">
          <svg class="w-7 h-7 text-violet-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
          </svg>
        </div>
        <h3 class="text-sm font-semibold text-gray-800 text-center mb-2 min-h-[2.5rem] flex items-center justify-center">Счета CRM и стадии</h3>
        <p class="text-xs text-gray-500 text-center leading-relaxed">Автостатусы счёта и движение по воронке</p>
      </div>
    </div>

    <!-- Actions -->
    <div class="flex flex-col items-center gap-3">
      <button
        class="px-8 py-3 bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium rounded-xl transition-colors whitespace-nowrap"
        @click="$emit('next')"
      >
        Начать настройку →
      </button>
      <span class="text-xs text-gray-400">
        Займёт около 2 минут
      </span>
    </div>
  </div>
</template>
