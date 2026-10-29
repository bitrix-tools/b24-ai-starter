<script setup lang="ts">
/**
 * Центр помощи «Точка Банк. Интеграция»: руководство статьями прямо в приложении.
 * По схеме других приложений 1daa.ru (см. навык b24-app-ui, §12), тема —
 * фиолетовая под бренд Точки. Поддержка живёт отдельно: отсюда только кнопка.
 */

const emit = defineEmits<{
  support: []
  navigate: [tab: 'payments' | 'accounts' | 'settings']
}>()

interface Article {
  title: string
  body: string[]
}

const REVIEW_URL = 'https://1daa.ru/prilozheniya/'
const CATALOG_URL = 'https://1daa.ru/prilozheniya/'

const articles: Article[] = [
  {
    title: 'Что делает приложение',
    body: [
      'Приложение подтягивает выписку из Точка Банка в Битрикс24 и само разносит поступления: находит по платежу нужную сделку или счёт, отмечает оплату и двигает сделку по воронке. Платежи, которые разнести не удалось, остаются в списке со статусом «Ожидает», чтобы их привязать вручную.',
      'Параллельно о каждом платеже приходит карточка в групповой чат портала — от бота «Точка Банк». Разнесён платёж или нет, переплата это или частичная оплата — видно сразу, не открывая CRM.',
      'Данные идут только из банка в портал: приложение не списывает деньги и не создаёт платежи в банке, а лишь читает выписку и сопоставляет её с CRM.'
    ]
  },
  {
    title: 'С чего начать: подключение Точки',
    body: [
      'На первом экране нажмите «Подключить Точка Банк» и авторизуйтесь — откроется окно банка, где нужно разрешить доступ к выписке. После этого приложение увидит ваши счета.',
      'Выберите счета, по которым нужно получать и разносить платежи. Остальные можно не подключать — лишние поступления не будут попадать в CRM.',
      'Дальше приложение само создаст нужные пользовательские поля в сделках и предложит настроить сопоставление. После этого можно загрузить первую выписку.'
    ]
  },
  {
    title: 'Как приложение находит сделку или счёт',
    body: [
      'Сначала приложение ищет точное совпадение: номер счёта из назначения платежа, затем ИНН контрагента. Если по ИНН в CRM несколько компаний-дубликатов, приложение предупредит об этом в карточке.',
      'Какие поля CRM использовать для поиска (ИНН, название, номер счёта, телефон), задаётся в разделе «Настройки». Чем точнее заполнены реквизиты в сделках, тем больше платежей разносится автоматически.',
      'Назначения вида «перевод собственных средств» и зачисления по QR без привязки к счёту приложение не разносит — это нормально, по ним просто приходит карточка «Не удалось разнести».'
    ]
  },
  {
    title: 'Загрузка выписки и синхронизация',
    body: [
      'Кнопка «Загрузить выписку» подтягивает поступления за выбранный период вручную. Кнопка «Синхронизировать» обновляет статусы и подтягивает свежие операции.',
      'Фоновая синхронизация работает сама: приложение периодически опрашивает банк и разносит новые платежи без вашего участия. Интервал задаётся в настройках.',
      'Если за период в банке не было новых операций — это не ошибка: приложение просто ничего не добавит и уведомления не пришлёт.'
    ]
  },
  {
    title: 'Уведомления в чат от бота',
    body: [
      'По каждому новому платежу бот «Точка Банк» присылает карточку в групповой чат: разнесён платёж, не разнесён, частичная оплата или переплата. В карточке — сумма, дата, плательщик, ИНН, счёт и сделка со ссылками.',
      'Чат для уведомлений выбирается в настройках. Если уведомления перестали приходить — проверьте, что чат указан и что бот состоит в этом чате.',
      'Уведомления приходят и по платежам, которые разнесла фоновая синхронизация, а не только по ручной. Повторно по одному платежу карточка не присылается.'
    ]
  },
  {
    title: 'Переплата и частичная оплата',
    body: [
      'Если по сделке пришло больше, чем ожидалось, приложение отметит переплату и покажет в карточке «получено X, ожидалось Y». Сделку оно при этом разносит, чтобы оплата не потерялась.',
      'Если пришла часть суммы — платёж привязывается к сделке как частичная оплата, с указанием, сколько уже оплачено из общей суммы.',
      'Сумму сделки для проверки приложение берёт из CRM, поэтому важно, чтобы в сделке или счёте стояла корректная сумма.'
    ]
  },
  {
    title: 'Платёж не разнёсся — что делать',
    body: [
      'Откройте платёж в списке и нажмите «Привязать»: можно привязать к сделке или к счёту вручную. После привязки приложение отметит оплату так же, как при автоматическом разнесении.',
      'Частые причины, по которым платёж не разнёсся сам: в назначении нет номера счёта, у контрагента в CRM не заполнен ИНН, либо это внутренний перевод, которому не соответствует ни одна сделка.',
      'Чтобы впредь такие платежи разносились автоматически, дозаполните реквизиты в сделках и проверьте поля сопоставления в настройках.'
    ]
  },
  {
    title: 'Настройки сопоставления и воронки',
    body: [
      'В разделе «Настройки» выбираются поля CRM, по которым идёт поиск (ИНН, название, номер счёта, телефон), и стадия, на которую двигать сделку после оплаты.',
      'Там же указывается чат для уведомлений и интервал фоновой синхронизации. Частые поля лучше заполнять в сделках, тогда автоматическое разнесение работает точнее.',
      'Изменения вступают в силу для новых платежей; уже разнесённые задним числом не пересчитываются.'
    ]
  },
  {
    title: 'Права и что видно приложению',
    body: [
      'Приложение работает от имени того, кто его установил, и видит в CRM ровно то, что видит этот сотрудник. Если часть сделок ему недоступна, по ним платежи не разнесутся.',
      'Поэтому устанавливать приложение и настраивать разнесение лучше администратору портала — так приложению доступны все сделки и счета.',
      'Доступ к банковской выписке приложение получает только после вашей авторизации в Точке и в объёме, который вы разрешили.'
    ]
  },
  {
    title: 'Доработки под ваши задачи',
    body: [
      'Если нужного в приложении нет — сделаем за отдельную плату. Чаще просят свои правила разнесения, дополнительные поля в карточке уведомления, выгрузку платежей в отчёт, интеграцию с бухгалтерией.',
      'Оценка работ бесплатная: опишите задачу в поддержке, назовём стоимость и срок. Работы начинаются после согласования.',
      'Напишите в поддержку прямо отсюда или на info@1daa.ru — разберём задачу и подскажем, что решается настройкой, а что доработкой.'
    ]
  }
]

const search = ref('')
const openedIndex = ref<number | null>(null)

const visible = computed(() => {
  const needle = search.value.trim().toLowerCase()
  if (!needle) return articles.map((article, index) => ({ article, index }))
  return articles
    .map((article, index) => ({ article, index }))
    .filter(({ article }) =>
      (article.title + ' ' + article.body.join(' ')).toLowerCase().includes(needle)
    )
})

const opened = computed(() => (openedIndex.value === null ? null : articles[openedIndex.value]))

function preview(article: Article): string {
  return article.body[0] || ''
}

const firstSteps = [
  {
    title: 'Подключите Точка Банк',
    text: 'Авторизуйтесь в банке и выберите счета — приложение увидит выписку.',
    linkLabel: 'С чего начать',
    article: 'С чего начать: подключение Точки'
  },
  {
    title: 'Загрузите выписку',
    text: 'Подтяните поступления за период — приложение само разнесёт их по сделкам.',
    linkLabel: 'К платежам',
    tab: 'payments' as const
  },
  {
    title: 'Настройте сопоставление',
    text: 'Укажите поля для поиска и стадию после оплаты — автоматика станет точнее.',
    linkLabel: 'К настройкам',
    tab: 'settings' as const
  },
  {
    title: 'Включите уведомления в чат',
    text: 'Выберите чат — бот «Точка Банк» будет присылать карточку по каждому платежу.',
    linkLabel: 'Уведомления в чат от бота',
    article: 'Уведомления в чат от бота'
  }
]

function followStep(step: (typeof firstSteps)[number]) {
  if ('tab' in step && step.tab) {
    emit('navigate', step.tab)
    return
  }
  if ('article' in step && step.article) {
    const index = articles.findIndex(item => item.title === step.article)
    if (index >= 0) openedIndex.value = index
  }
}
</script>

<template>
  <div class="space-y-5">
    <!-- Поиск по руководству -->
    <div class="flex items-center gap-6 rounded-2xl bg-gray-50 px-6 py-6">
      <div class="min-w-0 flex-1">
        <div class="text-xs font-semibold text-violet-600">
          Точка Банк. Интеграция · центр помощи
        </div>
        <h3 class="mt-2 text-2xl font-semibold">
          Чем помочь?
        </h3>
        <p class="mt-1 text-sm text-gray-500">
          Найдите ответ в руководстве или напишите нам — разберёмся вместе.
        </p>
        <input
          v-model="search"
          class="mt-4 w-full max-w-[32rem] rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm"
          placeholder="Найти ответ в руководстве"
        >
      </div>

      <div class="relative hidden size-28 shrink-0 md:block" aria-hidden="true">
        <div class="absolute inset-0 rotate-6 rounded-2xl bg-white shadow-sm" />
        <div class="absolute inset-0 flex items-center justify-center rounded-2xl bg-white shadow-sm">
          <svg viewBox="0 0 24 24" class="size-12 text-violet-500" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" />
            <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" />
          </svg>
        </div>
        <div class="absolute -bottom-2 -right-2 flex size-10 items-center justify-center rounded-full bg-violet-500 text-white shadow-md">
          <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z" />
            <path d="M12 17h.01" />
            <path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3" />
          </svg>
        </div>
      </div>
    </div>

    <!-- Отзыв и каталог приложений -->
    <div class="grid gap-4 md:grid-cols-2">
      <a
        :href="REVIEW_URL"
        target="_blank"
        rel="noopener"
        class="group rounded-2xl bg-gray-50 px-6 py-5 transition-colors hover:bg-gray-100"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="flex size-10 items-center justify-center rounded-xl bg-white text-violet-500">
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z" />
            </svg>
          </div>
          <svg viewBox="0 0 24 24" class="size-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M7 17 17 7" />
            <path d="M7 7h10v10" />
          </svg>
        </div>
        <div class="mt-3 text-xs text-gray-500">
          Ваш опыт важен
        </div>
        <div class="text-lg font-semibold">
          Оставить отзыв
        </div>
        <p class="mt-1 text-sm text-gray-500">
          Расскажите, как приложение помогает в работе.
        </p>
      </a>

      <a
        :href="CATALOG_URL"
        target="_blank"
        rel="noopener"
        class="rounded-2xl bg-gray-900 px-6 py-5 text-white transition-opacity hover:opacity-95"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="flex size-10 items-center justify-center rounded-xl bg-white/10">
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="m12 2 9 5-9 5-9-5z" />
              <path d="m3 12 9 5 9-5" />
              <path d="m3 17 9 5 9-5" />
            </svg>
          </div>
          <svg viewBox="0 0 24 24" class="size-4 text-white/60" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M7 17 17 7" />
            <path d="M7 7h10v10" />
          </svg>
        </div>
        <div class="mt-3 text-xs text-white/60">
          Следующая полезная находка
        </div>
        <div class="text-lg font-semibold">
          Приложения 1daa.ru
        </div>
        <p class="mt-1 text-sm text-white/70">
          Каталог наших решений для Битрикс24: банк, импорт, экспорт, бухгалтерия, телефония.
        </p>
        <span class="mt-3 inline-block text-sm font-semibold">Открыть каталог →</span>
      </a>
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
      <!-- Руководство -->
      <div>
        <div class="mb-3 flex items-baseline justify-between gap-3">
          <h3 class="text-base font-semibold">
            Руководство
          </h3>
          <span class="text-xs text-gray-400">Разделов: {{ visible.length }}</span>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-100">
          <button
            v-for="({ article, index }, position) in visible"
            :key="article.title"
            type="button"
            class="flex w-full items-start gap-4 px-5 py-4 text-left transition-colors hover:bg-gray-50"
            :class="position ? 'border-t border-gray-100' : ''"
            @click="openedIndex = index"
          >
            <span class="mt-0.5 w-6 shrink-0 text-xs font-semibold text-gray-400">
              {{ String(index + 1).padStart(2, '0') }}
            </span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold">{{ article.title }}</span>
              <span class="mt-0.5 block truncate text-xs text-gray-500">
                {{ preview(article) }}
              </span>
            </span>
            <svg viewBox="0 0 24 24" class="mt-1 size-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M7 17 17 7" />
              <path d="M7 7h10v10" />
            </svg>
          </button>

          <div v-if="!visible.length" class="px-5 py-6 text-sm text-gray-500">
            Ничего не нашлось. Напишите в поддержку — ответим и добавим раздел.
          </div>
        </div>
      </div>

      <!-- На связи -->
      <aside class="h-fit rounded-2xl bg-gray-900 px-5 py-5 text-white">
        <div class="mb-3 flex size-9 items-center justify-center rounded-xl bg-white/10">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
          </svg>
        </div>
        <div class="text-base font-semibold">
          На связи разработчик
        </div>
        <p class="mt-1 text-sm text-white/70">
          Поможем с настройкой, разберём ошибку, обсудим вашу задачу.
        </p>
        <button
          type="button"
          class="mt-4 w-full rounded-xl bg-violet-500 px-5 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90"
          @click="emit('support')"
        >
          Написать в поддержку
        </button>
      </aside>
    </div>

    <!-- Первые шаги -->
    <div class="rounded-2xl border border-gray-100 px-8 py-7">
      <h3 class="text-base font-semibold">
        Первые шаги
      </h3>
      <p class="mt-1.5 text-sm text-gray-500">
        В своём темпе. К этим шагам можно вернуться из помощи.
      </p>
      <div class="mt-6 grid gap-x-10 gap-y-7 sm:grid-cols-2">
        <div v-for="(step, index) in firstSteps" :key="step.title" class="flex gap-4">
          <span class="text-xs font-semibold text-gray-400">
            {{ String(index + 1).padStart(2, '0') }}
          </span>
          <div class="min-w-0">
            <div class="text-sm font-semibold">
              {{ step.title }}
            </div>
            <p class="mt-1 text-xs leading-relaxed text-gray-500">
              {{ step.text }}
            </p>
            <button
              type="button"
              class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-violet-600 hover:underline"
              @click="followStep(step)"
            >
              {{ step.linkLabel }}
              <svg viewBox="0 0 24 24" class="size-3" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M7 17 17 7" />
                <path d="M7 7h10v10" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Статья -->
    <div
      v-if="opened"
      class="fixed inset-0 z-40 flex justify-end bg-gray-900/30 p-4"
      @click.self="openedIndex = null"
    >
      <div class="flex h-full w-full max-w-[36rem] flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <div class="flex shrink-0 items-start justify-between gap-4 border-b border-gray-100 px-7 pb-5 pt-6">
          <div>
            <h3 class="text-xl font-semibold">
              {{ opened.title }}
            </h3>
            <p class="mt-1 text-sm text-gray-500">
              Руководство приложения
            </p>
          </div>
          <button
            type="button"
            class="flex size-9 shrink-0 items-center justify-center rounded-full text-gray-500 transition-colors hover:bg-gray-100"
            aria-label="Закрыть"
            @click="openedIndex = null"
          >
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M18 6 6 18" />
              <path d="m6 6 12 12" />
            </svg>
          </button>
        </div>

        <div class="flex-1 space-y-4 overflow-y-auto px-7 py-6 text-[15px] leading-relaxed text-gray-900/85">
          <p v-for="(paragraph, index) in opened.body" :key="index">
            {{ paragraph }}
          </p>
        </div>

        <div class="flex shrink-0 justify-end border-t border-gray-100 px-7 py-4">
          <button
            type="button"
            class="rounded-xl border border-gray-200 px-6 py-3 text-sm font-semibold transition-colors hover:bg-gray-50"
            @click="openedIndex = null"
          >
            К разделам руководства
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
