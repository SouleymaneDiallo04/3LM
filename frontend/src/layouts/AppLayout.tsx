import { Navigate, NavLink, Outlet } from 'react-router'
import { useAuth } from '../features/auth/AuthContext'
import NotificationBell from '../features/notifications/NotificationBell'

/**
 * Gabarit des pages protégées : barre latérale de navigation + en-tête.
 * Redirige vers /login si aucun utilisateur n'est authentifié.
 */
export default function AppLayout() {
  const { user, loading, logout } = useAuth()

  if (loading) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-slate-100">
        <p className="text-sm text-slate-500">Chargement…</p>
      </main>
    )
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  return (
    <div className="flex min-h-screen bg-slate-100">
      <aside className="flex w-56 flex-col border-r border-slate-200 bg-white">
        <div className="border-b border-slate-200 px-4 py-4">
          <p className="text-lg font-bold tracking-tight text-slate-900">FBDE</p>
          <p className="text-xs text-slate-500">France Business Data Extractor</p>
        </div>

        <nav className="flex-1 space-y-1 px-2 py-4">
          {(
            [
              ['/', 'Tableau de bord', true],
              ['/recherche', 'Recherche', false],
              ['/carte', 'Carte', false],
            ] as const
          ).map(([to, label, end]) => (
            <NavLink
              key={to}
              to={to}
              end={end}
              className={({ isActive }) =>
                `block rounded-md px-3 py-2 text-sm font-medium ${
                  isActive
                    ? 'bg-blue-50 text-blue-700'
                    : 'text-slate-600 hover:bg-slate-50'
                }`
              }
            >
              {label}
            </NavLink>
          ))}
        </nav>

        <NotificationBell />

        <div className="border-t border-slate-200 px-4 py-3">
          <p className="truncate text-sm font-medium text-slate-900">
            {user.name}
          </p>
          <p className="truncate text-xs text-slate-500">{user.email}</p>
          <button
            type="button"
            onClick={() => void logout()}
            className="mt-2 w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
          >
            Se déconnecter
          </button>
        </div>
      </aside>

      <main className="flex-1 p-8">
        <Outlet />
      </main>
    </div>
  )
}
