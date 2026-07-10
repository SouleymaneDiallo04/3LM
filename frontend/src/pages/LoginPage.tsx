import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { isAxiosError } from 'axios'
import BrandMark from '../components/BrandMark'
import { useAuth } from '../features/auth/AuthContext'

export default function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)

    try {
      const result = await login(email, password)
      navigate(result.status === 'two_factor_required' ? '/2fa' : '/', {
        replace: true,
      })
    } catch (err) {
      if (isAxiosError(err) && err.response?.status === 422) {
        setError(
          err.response.data.errors?.email?.[0] ??
            'Identifiants invalides.',
        )
      } else {
        setError('Erreur de connexion au serveur. Réessayez.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="flex min-h-screen bg-slate-50">
      {/* Panneau de marque : visible dès md — le formulaire reste seul sur mobile. */}
      <section className="hidden w-1/2 flex-col justify-between bg-slate-900 p-10 md:flex">
        <div className="flex items-center gap-3">
          <BrandMark size={36} />
          <div>
            <p className="text-xl font-bold leading-6 tracking-tight text-white">FBDE</p>
            <p className="text-sm text-slate-400">France Business Data Extractor</p>
          </div>
        </div>
        <div className="max-w-md">
          <h1 className="text-3xl font-semibold leading-snug text-white [text-wrap:balance]">
            Toute la base SIRENE, prête pour la prospection.
          </h1>
          <dl className="mt-8 grid grid-cols-3 gap-6 border-t border-white/10 pt-6">
            <div>
              <dt className="text-xs text-slate-400">Établissements</dt>
              <dd className="mt-1 font-mono text-lg font-semibold tabular-nums text-white">1,04 M</dd>
            </div>
            <div>
              <dt className="text-xs text-slate-400">Géolocalisés</dt>
              <dd className="mt-1 font-mono text-lg font-semibold tabular-nums text-white">99,2 %</dd>
            </div>
            <div>
              <dt className="text-xs text-slate-400">Recherche</dt>
              <dd className="mt-1 font-mono text-lg font-semibold tabular-nums text-white">&lt; 1 s</dd>
            </div>
          </dl>
        </div>
        <p className="text-xs text-slate-500">
          Données SIRENE/INSEE · cartographie OpenStreetMap
        </p>
      </section>

      <section className="flex flex-1 items-center justify-center px-4 py-12">
        <div className="w-full max-w-sm">
          <div className="mb-8 flex items-center gap-3 md:hidden">
            <BrandMark size={36} />
            <div>
              <h1 className="text-2xl font-bold leading-7 tracking-tight text-slate-900">FBDE</h1>
              <p className="text-sm text-slate-500">France Business Data Extractor</p>
            </div>
          </div>

          <h2 className="text-lg font-semibold text-slate-900">Connexion</h2>
          <p className="mt-1 mb-6 text-sm text-slate-500">
            Accédez à votre espace de prospection.
          </p>

          <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label
              htmlFor="email"
              className="block text-sm font-medium text-slate-700"
            >
              Adresse email
            </label>
            <input
              id="email"
              type="email"
              required
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-xs focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
            />
          </div>

          <div>
            <label
              htmlFor="password"
              className="block text-sm font-medium text-slate-700"
            >
              Mot de passe
            </label>
            <input
              id="password"
              type="password"
              required
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-xs focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
            />
          </div>

          {error && (
            <p role="alert" className="text-sm text-red-600">
              {error}
            </p>
          )}

            <button
              type="submit"
              disabled={submitting}
              className="w-full rounded-md bg-sky-700 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-sky-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700 disabled:opacity-50"
            >
              {submitting ? 'Connexion…' : 'Se connecter'}
            </button>
          </form>
        </div>
      </section>
    </main>
  )
}
