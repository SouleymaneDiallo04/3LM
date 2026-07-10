import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { createUser, listUsers, updateUser } from '../features/admin/api'
import { useAuth } from '../features/auth/AuthContext'
import type { ManagedUser } from '../lib/types'

const ROLES = ['commercial', 'manager', 'administrateur'] as const

/**
 * Gestion des utilisateurs (EF-10.2) : création avec rôle, changement de
 * rôle, désactivation/réactivation — réservée à users.manage.
 */
export default function UsersPage() {
  const { user: me, hasPermission } = useAuth()
  const [users, setUsers] = useState<ManagedUser[]>([])
  const [error, setError] = useState<string | null>(null)
  const [form, setForm] = useState({ name: '', email: '', password: '', role: 'commercial' })
  const [creating, setCreating] = useState(false)

  const refresh = useCallback(async () => {
    try {
      setUsers(await listUsers())
    } catch {
      setError('Impossible de charger les utilisateurs.')
    }
  }, [])

  useEffect(() => {
    if (hasPermission('users.manage')) void refresh()
  }, [refresh, hasPermission])

  if (!hasPermission('users.manage')) {
    return (
      <p className="text-sm text-slate-500">
        Votre rôle ne donne pas accès à la gestion des utilisateurs.
      </p>
    )
  }

  async function handleCreate(event: FormEvent) {
    event.preventDefault()
    setCreating(true)
    setError(null)
    try {
      await createUser(form)
      setForm({ name: '', email: '', password: '', role: 'commercial' })
      void refresh()
    } catch {
      setError('Création refusée — vérifiez l\'email (unique) et le mot de passe (12 caractères min., lettres et chiffres).')
    } finally {
      setCreating(false)
    }
  }

  async function handleRole(user: ManagedUser, role: string) {
    setError(null)
    try {
      await updateUser(user.id, { role })
      void refresh()
    } catch {
      setError('Changement de rôle refusé.')
    }
  }

  async function handleToggle(user: ManagedUser) {
    setError(null)
    try {
      await updateUser(user.id, { disabled: user.disabled_at === null })
      void refresh()
    } catch {
      setError('Action refusée.')
    }
  }

  return (
    <div>
      <h1 className="text-xl font-semibold text-slate-900">Utilisateurs</h1>
      <p className="mt-1 text-sm text-slate-500">
        Comptes de la plateforme — rôles, double authentification, désactivation.
      </p>

      <form
        onSubmit={handleCreate}
        className="mt-4 grid grid-cols-2 items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-5"
      >
        <label className="text-sm">
          <span className="text-slate-600">Nom</span>
          <input
            type="text"
            required
            value={form.name}
            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
          />
        </label>
        <label className="text-sm">
          <span className="text-slate-600">Email</span>
          <input
            type="email"
            required
            value={form.email}
            onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
          />
        </label>
        <label className="text-sm">
          <span className="text-slate-600">Mot de passe initial</span>
          <input
            type="password"
            required
            minLength={12}
            value={form.password}
            onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))}
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
          />
        </label>
        <label className="text-sm">
          <span className="text-slate-600">Rôle</span>
          <select
            value={form.role}
            onChange={(e) => setForm((f) => ({ ...f, role: e.target.value }))}
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
          >
            {ROLES.map((r) => (
              <option key={r} value={r}>{r}</option>
            ))}
          </select>
        </label>
        <button
          type="submit"
          disabled={creating}
          className="rounded-md bg-sky-700 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-800 disabled:opacity-50"
        >
          {creating ? 'Création…' : 'Créer le compte'}
        </button>
      </form>

      {error && (
        <p role="alert" className="mt-3 text-sm text-red-600">{error}</p>
      )}

      <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <th className="px-4 py-2">Nom</th>
              <th className="px-4 py-2">Email</th>
              <th className="px-4 py-2">Rôle</th>
              <th className="px-4 py-2">2FA</th>
              <th className="px-4 py-2">État</th>
              <th className="px-4 py-2" />
            </tr>
          </thead>
          <tbody>
            {users.map((u) => (
              <tr key={u.id} className="border-b border-slate-100">
                <td className="px-4 py-2 font-medium text-slate-900">
                  {u.name}
                  {u.id === me?.id && (
                    <span className="ml-1.5 rounded bg-slate-100 px-1 text-[10px] uppercase text-slate-500">
                      vous
                    </span>
                  )}
                </td>
                <td className="px-4 py-2 text-slate-600">{u.email}</td>
                <td className="px-4 py-2">
                  <select
                    value={u.roles[0] ?? ''}
                    disabled={u.id === me?.id}
                    onChange={(e) => void handleRole(u, e.target.value)}
                    className="rounded-md border border-slate-300 px-2 py-1 disabled:bg-slate-50 disabled:text-slate-400"
                  >
                    {ROLES.map((r) => (
                      <option key={r} value={r}>{r}</option>
                    ))}
                  </select>
                </td>
                <td className="px-4 py-2">{u.two_factor_enabled ? 'Activée' : '—'}</td>
                <td className="px-4 py-2">
                  {u.disabled_at === null ? (
                    <span className="rounded-full bg-green-50 px-2 py-0.5 text-xs text-green-700">
                      Actif
                    </span>
                  ) : (
                    <span className="rounded-full bg-red-50 px-2 py-0.5 text-xs text-red-700">
                      Désactivé
                    </span>
                  )}
                </td>
                <td className="px-4 py-2 text-right">
                  <button
                    type="button"
                    disabled={u.id === me?.id}
                    onClick={() => void handleToggle(u)}
                    className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40"
                  >
                    {u.disabled_at === null ? 'Désactiver' : 'Réactiver'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
