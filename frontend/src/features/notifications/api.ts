import { api } from '../../lib/api'
import type { AppNotification } from '../../lib/types'

/** Notifications de l'utilisateur connecté (EF-10.4). */
export async function getNotifications(): Promise<{
  items: AppNotification[]
  unreadCount: number
}> {
  const { data } = await api.get('/notifications')
  return { items: data.data, unreadCount: data.meta.unread_count }
}

export async function markAllNotificationsRead(): Promise<void> {
  await api.post('/notifications/read')
}
