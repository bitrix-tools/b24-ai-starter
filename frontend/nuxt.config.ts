import tailwindcss from '@tailwindcss/vite'
import { contentLocales } from './i18n/i18n.map'

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
      proxy: {
        '/api': { target: process.env.SERVER_HOST || 'http://api-need_set:8000', changeOrigin: true }
      }
    }
  },

  nitro: {
    devProxy: {
      '/api': { target: process.env.SERVER_HOST || 'http://api-need_set:8000', changeOrigin: true }
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
