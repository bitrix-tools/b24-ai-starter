<script setup lang="ts">
import type { NavigationMenuItem, CommandPaletteGroup, CommandPaletteItem } from '@bitrix24/b24ui-nuxt'
import HomeIcon from '@bitrix24/b24icons-vue/outline/HomeIcon'
import PulseIcon from '@bitrix24/b24icons-vue/outline/PulseIcon'
import HamburgerMenuIcon from '@bitrix24/b24icons-vue/outline/HamburgerMenuIcon'
import InfoCircleIcon from '@bitrix24/b24icons-vue/outline/InfoCircleIcon'
import Bitrix24Icon from '@bitrix24/b24icons-vue/common-service/Bitrix24Icon'
import GitHubIcon from '@bitrix24/b24icons-vue/social/GitHubIcon'

/**
 * Main application shell: collapsible sidebar + search (⌘K) + page panels.
 * Pages render a `B24DashboardPanel` (header: `B24DashboardNavbar`/`B24DashboardToolbar`).
 * Add a section here: one entry in `links[0]` (it also appears in the search).
 */
const { t } = useI18n()
const config = useRuntimeConfig()
const user = useUserStore()

const open = ref(false)
const close = () => { open.value = false }
const isTelemetryEnabled = computed(() => String(config.public.telemetryEnabled) === 'true')

const links = computed<NavigationMenuItem[][]>(() => [
  [
    { label: t('layout.nav.home'), icon: HomeIcon, to: '/', exact: true, onSelect: close },
    ...(isTelemetryEnabled.value
      ? [{ label: t('page.index.action.telemetry_test'), icon: PulseIcon, to: '/telemetry-test', onSelect: close }]
      : [])
  ],
  [
    { label: 'Bitrix24 REST API', icon: Bitrix24Icon, to: 'https://apidocs.bitrix24.com/', target: '_blank' },
    { label: 'Bitrix24 UI Kit', icon: InfoCircleIcon, to: 'https://bitrix24.github.io/b24ui/', target: '_blank' },
    { label: 'GitHub', icon: GitHubIcon, to: 'https://github.com/bitrix-tools/b24-ai-starter', target: '_blank' }
  ]
])

const groups = computed<CommandPaletteGroup[]>(() => [
  {
    id: 'links',
    label: t('layout.search.goTo'),
    items: links.value.flat() as CommandPaletteItem[]
  }
])
</script>

<template>
  <B24DashboardGroup unit="px" storage="local">
    <B24DashboardSidebar
      id="default"
      v-model:open="open"
      mode="slideover"
      collapsible
      resizable
      class="border-e-1"
    >
      <template #header="{ collapsed }">
        <B24DashboardSidebarCollapse :icon="HamburgerMenuIcon" class="size-9 px-2" />
        <span v-if="!collapsed" class="truncate font-(--ui-font-weight-semi-bold)">
          {{ $t('layout.appTitle') }}
        </span>
      </template>

      <template #default="{ collapsed }">
        <B24DashboardSearchButton :collapsed="collapsed" class="opacity-70 hover:opacity-100" />
        <B24NavigationMenu :collapsed="collapsed" :items="links[0]" orientation="vertical" popover />
        <B24NavigationMenu :collapsed="collapsed" :items="links[1]" orientation="vertical" class="mt-auto" />
      </template>

      <template #footer="{ collapsed }">
        <B24User
          v-if="user.fullName"
          :name="collapsed ? undefined : user.fullName"
          :avatar="{ text: user.fullName.slice(0, 1) }"
          size="sm"
          class="mb-2"
        />
      </template>
    </B24DashboardSidebar>

    <B24DashboardSearch :groups="groups" :color-mode="false" />

    <slot />
  </B24DashboardGroup>
</template>
