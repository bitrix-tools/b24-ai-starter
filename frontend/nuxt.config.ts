import tailwindcss from '@tailwindcss/vite'
import { contentLocales } from './i18n/i18n.map'

/**
 * Hosts the Vite dev server accepts besides localhost: the tunnel host from
 * NUXT_PUBLIC_APP_URL (VIRTUAL_HOST) plus any extra ones from NUXT_ALLOWED_HOSTS
 * (comma-separated). Without it Vite answers "Blocked request. This host is not allowed".
 */
function devAllowedHosts(): string[] {
  const hosts = (process.env.NUXT_ALLOWED_HOSTS ?? '').split(',').map(s => s.trim()).filter(Boolean)
  try {
    hosts.push(new URL(process.env.NUXT_PUBLIC_APP_URL ?? '').hostname)
  } catch {
    // no or invalid app URL: localhost only
  }
  return [...new Set(hosts)]
}

export default defineNuxtConfig({
  modules: [
    '@bitrix24/b24ui-nuxt',
    '@bitrix24/b24jssdk-nuxt',
    '@nuxt/eslint',
    '@nuxtjs/i18n',
    '@pinia/nuxt'
  ],

  ssr: false,

  devtools: { enabled: false },

  runtimeConfig: {
    /**
     * @memo this will be overwritten from .env or Docker_*
     * @see https://nuxt.com/docs/guide/going-further/runtime-config#example
     */
    public: {
      appUrl: '',
      apiUrl: '',
      telemetryEnabled: ''
    }
  },

  compatibilityDate: '2025-07-16',

  app: {
    head: {
      title: 'Starter',
      link: [
        { rel: 'icon', type: 'image/x-icon', href: '/favicon.ico' }
      ],
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' }
      ],
      htmlAttrs: { class: 'light' }
    }
  },

  css: ['~/assets/css/main.css'],

  vite: {
    plugins: [
      tailwindcss()
    ],
    server: {
      allowedHosts: devAllowedHosts(),
      proxy: {
        '/api': { target: process.env.SERVER_HOST || 'http://api:8000', changeOrigin: true }
      }
    }
  },

  nitro: {
    devProxy: {
      '/api': { target: process.env.SERVER_HOST || 'http://api:8000', changeOrigin: true }
    },
  },

  i18n: {
    detectBrowserLanguage: false,
    strategy: 'no_prefix',
    langDir: 'locales',
    locales: contentLocales,
    defaultLocale: 'en'
  }
})
