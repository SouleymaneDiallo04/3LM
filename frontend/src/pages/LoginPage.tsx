import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { isAxiosError } from 'axios'
import BrandMark from '../components/BrandMark'
import LoginBackdrop from './LoginBackdrop'
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
    <main className="flex min-h-screen bg-white">
      {/* Panneau de marque (≥ md) : navy + trame de points évoquant les
          établissements géolocalisés. Le formulaire reste seul sur mobile. */}
      <section className="relative hidden w-3/5 flex-col justify-between overflow-hidden bg-slate-900 p-12 md:flex">
        {/* Maillage animé des points géolocalisés (décoratif). */}
        <LoginBackdrop />

        {/* Halo d'accent discret, ancré en bas à gauche. */}
        <div
          aria-hidden="true"
          className="pointer-events-none absolute -bottom-32 -left-24 z-0 h-96 w-96 rounded-full"
          style={{ background: 'radial-gradient(circle, rgb(2 132 199 / 0.2), transparent 70%)' }}
        />

        <div className="relative flex items-center gap-3">
          <BrandMark size={36} />
          <div>
            <p className="text-xl font-bold leading-6 tracking-tight text-white">FBDE</p>
            <p className="text-sm text-slate-400">France Business Data Extractor</p>
          </div>
        </div>

        <div className="relative max-w-lg">
          <h1 className="text-4xl font-semibold leading-tight tracking-tight text-white [text-wrap:balance]">
            Toute la base SIRENE, prête pour la prospection.
          </h1>
          <p className="mt-4 max-w-md text-sm leading-relaxed text-slate-400">
            Recherchez, cartographiez et qualifiez vos prochains clients sur l'ensemble
            des entreprises françaises — en quelques secondes.
          </p>
          <dl className="mt-10 grid grid-cols-3 gap-6 border-t border-white/10 pt-6">
            <Stat label="Établissements" value="1,04 M" />
            <Stat label="Géolocalisés" value="99,2 %" />
            <Stat label="Recherche" value="< 1 s" />
          </dl>
        </div>

        <p className="relative text-xs text-slate-500">
          Données SIRENE/INSEE · cartographie OpenStreetMap
        </p>
      </section>

      <section className="flex flex-1 items-center justify-center px-6 py-12">
        <div className="w-full max-w-sm">
          <div className="mb-8 flex items-center gap-3 md:hidden">
            <BrandMark size={36} />
            <div>
              <h1 className="text-2xl font-bold leading-7 tracking-tight text-slate-900">FBDE</h1>
              <p className="text-sm text-slate-500">France Business Data Extractor</p>
            </div>
          </div>

          <h2 className="text-2xl font-semibold tracking-tight text-slate-900">Connexion</h2>
          <p className="mt-1.5 mb-8 text-sm text-slate-500">
            Accédez à votre espace de prospection.
          </p>

          <form onSubmit={handleSubmit} className="space-y-5">
            <Field
              id="email"
              label="Adresse email"
              type="email"
              autoComplete="email"
              value={email}
              onChange={setEmail}
            />
            <Field
              id="password"
              label="Mot de passe"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={setPassword}
            />

            {error && (
              <p role="alert" className="text-sm text-red-600">
                {error}
              </p>
            )}

            <button
              type="submit"
              disabled={submitting}
              className="w-full rounded-lg bg-sky-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors duration-150 hover:bg-sky-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700 disabled:opacity-50"
            >
              {submitting ? 'Connexion…' : 'Se connecter'}
            </button>
          </form>

          <p className="mt-8 border-t border-slate-100 pt-6 text-xs text-slate-400">
            Accès réservé aux comptes autorisés. Connexion protégée par
            authentification à deux facteurs.
          </p>
        </div>
      </section>
    </main>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-slate-400">{label}</dt>
      <dd className="mt-1 font-mono text-lg font-semibold tabular-nums text-white">{value}</dd>
    </div>
  )
}

function Field({
  id,
  label,
  type,
  autoComplete,
  value,
  onChange,
}: {
  id: string
  label: string
  type: string
  autoComplete: string
  value: string
  onChange: (v: string) => void
}) {
  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-slate-700">
        {label}
      </label>
      <input
        id={id}
        type={type}
        required
        autoComplete={autoComplete}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="mt-1.5 w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm text-slate-900 shadow-xs transition-colors duration-150 placeholder:text-slate-400 focus:border-sky-500 focus:ring-2 focus:ring-sky-100 focus:outline-none"
      />
    </div>
  )
}
