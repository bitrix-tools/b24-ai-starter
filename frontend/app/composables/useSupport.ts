import type { SupportMessage } from '../stores/api'

/**
 * Состояние переписки с поддержкой, общее для окна и пункта меню.
 * Открытый тред запоминается в localStorage — разговор продолжится при
 * повторном открытии. Непрочитанное (useState) зажигает точку у пункта
 * «Поддержка», когда пришёл ответ.
 */

const TICKET_KEY = 'tochka.support.ticket'
const READ_KEY = 'tochka.support.read'

function readLocal(key: string): string {
  try {
    return localStorage.getItem(key) || ''
  } catch {
    return ''
  }
}

function writeLocal(key: string, value: string) {
  try {
    if (value) localStorage.setItem(key, value)
    else localStorage.removeItem(key)
  } catch {
    // приватный режим / заблокированное хранилище — не повод падать
  }
}

function latestAt(messages: SupportMessage[]): number {
  return messages.reduce((top, m) => Math.max(top, Date.parse(m.createdAt) || 0), 0)
}

export function useSupport() {
  const api = useApiStore()
  const hasUnread = useState('support-unread', () => false)

  const ticketId = () => readLocal(TICKET_KEY)
  const rememberTicket = (id: string) => writeLocal(TICKET_KEY, id)
  const forgetTicket = () => writeLocal(TICKET_KEY, '')

  function markRead(messages: SupportMessage[]) {
    const seen = Math.max(latestAt(messages), Date.now())
    writeLocal(READ_KEY, String(seen))
    hasUnread.value = false
  }

  async function refreshUnread() {
    const id = ticketId()
    if (!id) {
      hasUnread.value = false
      return
    }
    try {
      const data = await api.loadSupportThread(id)
      const seen = Number(readLocal(READ_KEY) || 0)
      hasUnread.value = data.messages.some(
        m => m.author === 'support' && (Date.parse(m.createdAt) || 0) > seen
      )
    } catch {
      hasUnread.value = false
    }
  }

  return { hasUnread, ticketId, rememberTicket, forgetTicket, markRead, refreshUnread }
}
