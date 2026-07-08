import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { isAxiosError } from 'axios'
import { useAuth } from '../features/auth/AuthContext'

/** Défi 2FA : code TOTP (6 chiffres) ou code de secours (XXXXX-XXXXX). */
export default function TwoFactorPage() {
  const { verifyTwoFactor } = useAuth()
  const navigate = useNavigate()

  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      await verifyTwoFactor(code.trim())
      navigate('/', { replace: true })
    } catch (err) {
      if (isAxiosError(err) && err.response?.status === 422) {
        setError('Code de vérification invalide.')
      } else if (isAxiosError(err) && err.response?.status === 401) {
        // Token de défi expiré (5 min) : retour à la connexion.
        setError('Session expirée — reconnectez-vous.')
        setTimeout(() => navigate('/login', { replace: true }), 1500)
      } else {
        setError('Erreur de connexion au serveur. Réessayez.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-100 px-4">
      <div className="w-full max-w-sm">
        <form
          onSubmit={handleSubmit}
          className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm"
        >
          <div>
            <h1 className="text-lg font-semibold text-slate-900">
              Vérification en deux étapes
            </h1>
            <p className="mt-1 text-sm text-slate-500">
              Saisissez le code de votre application d'authentification, ou un
              code de secours.
            </p>
          </div>

          <div>
            <label
              htmlFor="code"
              className="block text-sm font-medium text-slate-700"
            >
              Code de vérification
            </label>
            <input
              id="code"
              type="text"
              required
              autoFocus
              autoComplete="one-time-code"
              inputMode="text"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-center font-mono text-lg tracking-widest shadow-xs focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none"
            />
          </div>

          {error && (
            <p role="alert" className="text-sm text-red-600">
              {error}
            </p>
          )}

          <button
            type="submit"
            disabled={submitting || code.trim() === ''}
            className="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {submitting ? 'Vérification…' : 'Vérifier'}
          </button>
        </form>
      </div>
    </main>
  )
}
