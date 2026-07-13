import { useEffect, useState } from 'react'
import { Navigate, NavLink, Outlet, useLocation } from 'react-router'
import BrandMark from '../components/BrandMark'
import { useAuth } from '../features/auth/AuthContext'
import NotificationBell from '../features/notifications/NotificationBell'

/**
 * Gabarit des pages protégées : barre latérale navy (statique ≥ md, en tiroir
 * sur mobile avec bouton hamburger). Redirige vers /login si non authentifié.
 */
export default function AppLayout() {
  const { user, loading, logout, hasPermission } = useAuth()
  const [open, setOpen] = useState(false)
  const location = useLocation()

  // Referme le tiroir à chaque changement de page (mobile).
  useEffect(() => {
    setOpen(false)
  }, [location.pathname])

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

  const nav = (
    [
      ['/', 'Tableau de bord', true],
      ['/recherche', 'Recherche', false],
      ['/carte', 'Carte', false],
      ...(hasPermission('duplicates.review')
        ? ([['/doublons', 'Doublons', false]] as const)
        : []),
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
          isActive ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200'
        }`
      }
    >
      {label}
    </NavLink>
  ))

  return (
    <div className="flex min-h-screen bg-slate-50">
      {/* Fond assombri quand le tiroir est ouvert (mobile). */}
      {open && (
        <div
          className="fixed inset-0 z-30 bg-slate-900/40 backdrop-blur-[1px] md:hidden"
          onClick={() => setOpen(false)}
          aria-hidden="true"
        />
      )}

      <aside
        className={`fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-slate-900 transition-transform duration-200 ease-out md:static md:z-auto md:w-56 md:translate-x-0 ${
          open ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <div className="flex items-center gap-2.5 px-4 py-5">
          <BrandMark size={30} />
          <div>
            <p className="text-lg font-bold leading-5 tracking-tight text-white">FBDE</p>
            <p className="text-[11px] text-slate-400">France Business Data Extractor</p>
          </div>
        </div>

        <nav className="flex-1 space-y-0.5 px-2 py-2">{nav}</nav>

        <NotificationBell />

        <div className="border-t border-white/10 px-4 py-3">
          <p className="truncate text-sm font-medium text-white">{user.name}</p>
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

      <div className="flex min-w-0 flex-1 flex-col">
        {/* Barre supérieure mobile : ouvre le tiroir. */}
        <header className="flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-3 md:hidden">
          <button
            type="button"
            onClick={() => setOpen(true)}
            aria-label="Ouvrir le menu"
            className="rounded-md p-1 text-slate-600 transition-colors hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
          >
            <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
              <path d="M3 6h18M3 12h18M3 18h18" />
            </svg>
          </button>
          <BrandMark size={24} />
          <span className="font-bold tracking-tight text-slate-900">FBDE</span>
        </header>

        <main className="min-w-0 flex-1 p-5 md:p-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
