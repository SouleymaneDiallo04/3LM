import { useCallback, useEffect, useState } from 'react'
import { getNotifications, markAllNotificationsRead } from './api'
import type { AppNotification } from '../../lib/types'

/**
 * Cloche de la barre latérale (EF-10.4) : badge de non-lus rafraîchi
 * toutes les 60 s, panneau listant les 20 dernières notifications.
 */
export default function NotificationBell() {
  const [items, setItems] = useState<AppNotification[]>([])
  const [unread, setUnread] = useState(0)
  const [open, setOpen] = useState(false)

  const refresh = useCallback(async () => {
    try {
      const { items: list, unreadCount } = await getNotifications()
      setItems(list)
      setUnread(unreadCount)
    } catch {
      // silencieux : la cloche ne doit jamais casser la navigation
    }
  }, [])

  useEffect(() => {
    void refresh()
    const timer = setInterval(() => void refresh(), 60_000)
    return () => clearInterval(timer)
  }, [refresh])

  async function markAllRead() {
    await markAllNotificationsRead()
    void refresh()
  }

  return (
    <div className="relative border-t border-slate-200 px-2 py-2">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="flex w-full items-center justify-between rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
      >
        Notifications
        {unread > 0 && (
          <span className="rounded-full bg-blue-600 px-2 py-0.5 text-xs font-semibold text-white">
            {unread}
          </span>
        )}
      </button>

      {open && (
        <div className="fixed bottom-16 left-56 z-50 ml-2 w-80 rounded-xl border border-slate-200 bg-white shadow-lg">
          <div className="flex items-center justify-between border-b border-slate-200 px-3 py-2">
            <p className="text-sm font-semibold text-slate-900">Notifications</p>
            {unread > 0 && (
              <button
                type="button"
                onClick={() => void markAllRead()}
                className="text-xs text-blue-600 hover:underline"
              >
                Tout marquer lu
              </button>
            )}
          </div>
          <ul className="max-h-80 overflow-y-auto">
            {items.map((n) => (
              <li
                key={n.id}
                className={`border-b border-slate-100 px-3 py-2 text-sm ${
                  n.read_at === null ? 'bg-blue-50/50' : ''
                }`}
              >
                <p className="text-slate-800">{label(n)}</p>
                <p className="mt-0.5 text-xs text-slate-500">
                  {new Date(n.created_at).toLocaleString('fr-FR')}
                </p>
              </li>
            ))}
            {items.length === 0 && (
              <li className="px-3 py-6 text-center text-sm text-slate-500">
                Aucune notification pour le moment.
              </li>
            )}
          </ul>
        </div>
      )}
    </div>
  )
}

function label(n: AppNotification): string {
  switch (n.type) {
    case 'ExportReady':
      return `Export ${(n.data.format ?? '').toUpperCase()} prêt — ${
        n.data.rows_count?.toLocaleString('fr-FR') ?? '?'
      } lignes (lien valable 7 jours)`
    case 'SireneImportFinished':
      return `Import SIRENE #${n.data.import_id} terminé`
    default:
      return n.type
  }
}
