import { Navigate, NavLink, Outlet } from 'react-router'
import BrandMark from '../components/BrandMark'
import { useAuth } from '../features/auth/AuthContext'
import NotificationBell from '../features/notifications/NotificationBell'

/**
 * Gabarit des pages protégées : barre latérale navy + contenu.
 * Redirige vers /login si aucun utilisateur n'est authentifié.
 */
export default function AppLayout() {
  const { user, loading, logout, hasPermission } = useAuth()

  if (loading) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-slate-50">
        <p className="text-sm text-slate-500">Chargement…</p>
      </main>
    )
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  return (
    <div className="flex min-h-screen bg-slate-50">
      <aside className="flex w-56 flex-col bg-slate-900">
        <div className="flex items-center gap-2.5 px-4 py-5">
          <BrandMark size={30} />
          <div>
            <p className="text-lg font-bold leading-5 tracking-tight text-white">FBDE</p>
            <p className="text-[11px] text-slate-400">France Business Data Extractor</p>
          </div>
        </div>

        <nav className="flex-1 space-y-0.5 px-2 py-2">
          {(
            [
              ['/', 'Tableau de bord', true],
              ['/recherche', 'Recherche', false],
              ['/carte', 'Carte', false],
              // Administration : visible seulement avec users.manage (EF-10.2).
              ...(hasPermission('users.manage')
                ? ([['/utilisateurs', 'Utilisateurs', false]] as const)
                : []),
            ] as const
          ).map(([to, label, end]) => (
            <NavLink
              key={to}
              to={to}
              end={end}
              className={({ isActive }) =>
                `block rounded-md px-3 py-2 text-sm font-medium transition-colors duration-150 ${
                  isActive
                    ? 'bg-white/10 text-white'
                    : 'text-slate-400 hover:bg-white/5 hover:text-slate-200'
                }`
              }
            >
              {label}
            </NavLink>
          ))}
        </nav>

        <NotificationBell />

        <div className="border-t border-white/10 px-4 py-3">
          <p className="truncate text-sm font-medium text-white">
            {user.name}
          </p>
          <p className="truncate text-xs text-slate-400">{user.email}</p>
          <button
            type="button"
            onClick={() => void logout()}
            className="mt-2 w-full rounded-md border border-white/15 px-3 py-1.5 text-sm text-slate-300 transition-colors duration-150 hover:bg-white/5 hover:text-white"
          >
            Se déconnecter
          </button>
        </div>
      </aside>

      <main className="min-w-0 flex-1 p-8">
        <Outlet />
      </main>
    </div>
  )
}
