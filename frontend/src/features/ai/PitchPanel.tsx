import { useEffect, useRef, useState, type ReactNode } from 'react'
import type { Establishment } from '../../lib/types'
import { aiErrorMessage, generatePitch } from './api'
import RevealText from './RevealText'

type Channel = 'email' | 'call'
type Status = 'idle' | 'loading' | 'error'

/**
 * Panneau « Argumentaire commercial » (EF-08.4). Généré à la demande selon le
 * canal choisi (email / appel), jamais mémorisé. Le résultat se révèle
 * progressivement et se copie en un clic. Refus RGPD (422) et panne (503)
 * ont des états distincts.
 */
export default function PitchPanel({ establishment }: { establishment: Establishment }) {
  const [channel, setChannel] = useState<Channel>('email')
  const [pitch, setPitch] = useState<string | null>(null)
  const [status, setStatus] = useState<Status>('idle')
  const [error, setError] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)
  const copyTimer = useRef<number | undefined>(undefined)

  useEffect(() => () => window.clearTimeout(copyTimer.current), [])

  // Changer de canal invalide l'argumentaire affiché : il est propre à un canal,
  // on ne laisse jamais un texte email étiqueté « appel » (ou l'inverse).
  function selectChannel(next: Channel) {
    if (next === channel) return
    setChannel(next)
    setPitch(null)
    setError(null)
    setStatus('idle')
    setCopied(false)
  }

  async function run() {
    setStatus('loading')
    setError(null)
    setCopied(false)
    try {
      const result = await generatePitch(establishment.id, channel)
      setPitch(result.pitch)
      setStatus('idle')
    } catch (err) {
      setError(aiErrorMessage(err))
      setStatus('error')
    }
  }

  async function copy() {
    if (!pitch) return
    await navigator.clipboard.writeText(pitch)
    setCopied(true)
    window.clearTimeout(copyTimer.current)
    copyTimer.current = window.setTimeout(() => setCopied(false), 1600)
  }

  return (
    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm md:col-span-2">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
          <SparkleIcon className="h-4 w-4 text-sky-600" />
          Argumentaire commercial
          <span className="rounded bg-sky-50 px-1.5 py-0.5 text-[0.65rem] font-semibold tracking-wide text-sky-700">
            IA
          </span>
        </h2>

        <div className="flex items-center gap-2">
          <div
            role="group"
            aria-label="Canal de contact"
            className="inline-flex rounded-md border border-slate-200 bg-slate-50 p-0.5"
          >
            <ChannelButton
              active={channel === 'email'}
              disabled={status === 'loading'}
              onClick={() => selectChannel('email')}
              icon={<MailIcon className="h-3.5 w-3.5" />}
              label="Email"
            />
            <ChannelButton
              active={channel === 'call'}
              disabled={status === 'loading'}
              onClick={() => selectChannel('call')}
              icon={<PhoneIcon className="h-3.5 w-3.5" />}
              label="Appel"
            />
          </div>

          <button
            type="button"
            onClick={run}
            disabled={status === 'loading'}
            className="inline-flex items-center gap-2 rounded-md bg-sky-700 px-3.5 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-sky-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700 disabled:opacity-50"
          >
            <SparkleIcon className="h-4 w-4" />
            {status === 'loading' ? 'Génération…' : pitch ? 'Régénérer' : "Générer l'argumentaire"}
          </button>
        </div>
      </div>

      <div className="mt-3" aria-live="polite" aria-busy={status === 'loading'}>
        {status === 'loading' ? (
          <PitchSkeleton />
        ) : status === 'error' ? (
          <ErrorState message={error ?? ''} onRetry={run} />
        ) : pitch ? (
          <div className="relative rounded-lg border border-slate-200 bg-slate-50 p-4">
            <RevealText
              text={pitch}
              className="max-w-2xl whitespace-pre-line pr-24 text-sm leading-relaxed text-slate-700"
            />
            <button
              type="button"
              onClick={copy}
              aria-label="Copier l'argumentaire"
              className={`absolute right-3 top-3 inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700 ${
                copied
                  ? 'border-green-200 bg-green-50 text-green-700'
                  : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100 hover:text-slate-900'
              }`}
            >
              {copied ? <CheckIcon className="h-3.5 w-3.5" /> : <CopyIcon className="h-3.5 w-3.5" />}
              {copied ? 'Copié' : 'Copier'}
            </button>
          </div>
        ) : (
          <p className="max-w-xl text-sm text-slate-500">
            Rédigez une accroche personnalisée pour cette entreprise selon le canal choisi.
            L'argumentaire est généré à la demande et n'est pas conservé.
          </p>
        )}
      </div>
    </section>
  )
}

function ChannelButton({
  active,
  disabled,
  onClick,
  icon,
  label,
}: {
  active: boolean
  disabled?: boolean
  onClick: () => void
  icon: ReactNode
  label: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-pressed={active}
      className={`inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700 disabled:opacity-50 ${
        active
          ? 'bg-white text-sky-700 shadow-sm'
          : 'text-slate-500 hover:text-slate-800'
      }`}
    >
      {icon}
      {label}
    </button>
  )
}

function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="flex flex-col items-start gap-2.5">
      <p className="max-w-xl text-sm text-slate-600">{message}</p>
      <button
        type="button"
        onClick={onRetry}
        className="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition-colors duration-150 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
      >
        Réessayer
      </button>
    </div>
  )
}

function PitchSkeleton() {
  return (
    <div className="max-w-2xl space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-4" aria-hidden="true">
      <div className="fbde-skeleton h-3.5 w-[85%] rounded" />
      <div className="fbde-skeleton h-3.5 w-full rounded" />
      <div className="fbde-skeleton h-3.5 w-[70%] rounded" />
    </div>
  )
}

function SparkleIcon({ className }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <path d="M12 2.5l1.9 5.1 5.1 1.9-5.1 1.9L12 16.5l-1.9-5.1L5 9.5l5.1-1.9L12 2.5zM19 15l.9 2.4 2.4.9-2.4.9L19 22l-.9-2.4-2.4-.9 2.4-.9L19 15z" />
    </svg>
  )
}

function MailIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="m3 7 9 6 9-6" />
    </svg>
  )
}

function PhoneIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" />
    </svg>
  )
}

function CopyIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <rect x="9" y="9" width="13" height="13" rx="2" />
      <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
    </svg>
  )
}

function CheckIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.5"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M20 6 9 17l-5-5" />
    </svg>
  )
}
