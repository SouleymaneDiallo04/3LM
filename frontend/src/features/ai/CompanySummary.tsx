import { useRef, useState } from 'react'
import type { Establishment } from '../../lib/types'
import { aiErrorMessage, generateSummary } from './api'
import RevealText from './RevealText'

type Status = 'idle' | 'loading' | 'error'

/**
 * Carte « Résumé commercial » (EF-08.2). Affiche le résumé mémorisé s'il
 * existe (avec indicateur de péremption + régénération), sinon propose de le
 * générer. La génération déclenche une révélation progressive du texte ;
 * le refus RGPD (422) et la panne du service (503) ont des états distincts.
 */
export default function CompanySummary({ establishment }: { establishment: Establishment }) {
  const [summary, setSummary] = useState<string | null>(establishment.ai_summary ?? null)
  const [generatedAt, setGeneratedAt] = useState<string | null>(establishment.ai_summary_at ?? null)
  const [stale, setStale] = useState<boolean>(establishment.ai_summary_stale ?? false)
  const [status, setStatus] = useState<Status>('idle')
  const [error, setError] = useState<string | null>(null)
  // Révélation seulement après une génération de cette session (pas au chargement).
  const [revealed, setRevealed] = useState(false)
  // Mémorise l'intention (refresh ou non) pour que « Réessayer » rejoue le même appel.
  const lastRefresh = useRef(false)

  async function run(refresh: boolean) {
    lastRefresh.current = refresh
    setStatus('loading')
    setError(null)
    try {
      const result = await generateSummary(establishment.id, refresh)
      setSummary(result.summary)
      setGeneratedAt(result.generated_at)
      setStale(false)
      setRevealed(true)
      setStatus('idle')
    } catch (err) {
      setError(aiErrorMessage(err))
      setStatus('error')
    }
  }

  return (
    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm md:col-span-2">
      <div className="flex items-center justify-between gap-3">
        <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
          <SparkleIcon className="h-4 w-4 text-sky-600" />
          Résumé commercial
          <span className="rounded bg-sky-50 px-1.5 py-0.5 text-[0.65rem] font-semibold tracking-wide text-sky-700">
            IA
          </span>
        </h2>

        {summary && status === 'idle' && (
          <div className="flex items-center gap-2">
            {stale && (
              <span
                className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700"
                title="La fiche a été enrichie depuis la génération de ce résumé."
              >
                À actualiser
              </span>
            )}
            <button
              type="button"
              onClick={() => run(true)}
              className="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-slate-600 transition-colors duration-150 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
            >
              <RefreshIcon className="h-3.5 w-3.5" />
              Régénérer
            </button>
          </div>
        )}
      </div>

      <div className="mt-3" aria-live="polite" aria-busy={status === 'loading'}>
        {status === 'loading' ? (
          <SummarySkeleton />
        ) : status === 'error' ? (
          // L'erreur prime sur le résumé mémorisé : un refus RGPD (422) postérieur
          // ne doit plus laisser voir le résumé caché.
          <ErrorState message={error ?? ''} onRetry={() => run(lastRefresh.current)} />
        ) : summary ? (
          <>
            {revealed ? (
              <RevealText
                text={summary}
                className="max-w-2xl text-sm leading-relaxed text-slate-700"
              />
            ) : (
              <p className="max-w-2xl text-sm leading-relaxed text-slate-700">{summary}</p>
            )}
            {generatedAt && (
              <p className="mt-2.5 text-xs text-slate-500">Généré le {formatDate(generatedAt)}</p>
            )}
          </>
        ) : (
          <EmptyState onGenerate={() => run(false)} />
        )}
      </div>
    </section>
  )
}

function EmptyState({ onGenerate }: { onGenerate: () => void }) {
  return (
    <div className="flex flex-col items-start gap-3">
      <p className="max-w-xl text-sm text-slate-500">
        Générez un résumé commercial synthétique à partir des données publiques de la fiche —
        activité, implantation, présence en ligne — pour préparer votre approche en un coup d'œil.
      </p>
      <button
        type="button"
        onClick={onGenerate}
        className="inline-flex items-center gap-2 rounded-md bg-sky-700 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-sky-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
      >
        <SparkleIcon className="h-4 w-4" />
        Générer le résumé
      </button>
    </div>
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
        <RefreshIcon className="h-3.5 w-3.5" />
        Réessayer
      </button>
    </div>
  )
}

function SummarySkeleton() {
  return (
    <div className="max-w-2xl space-y-2" aria-hidden="true">
      <div className="fbde-skeleton h-3.5 w-full rounded" />
      <div className="fbde-skeleton h-3.5 w-[92%] rounded" />
      <div className="fbde-skeleton h-3.5 w-[78%] rounded" />
    </div>
  )
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('fr-FR', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function SparkleIcon({ className }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <path d="M12 2.5l1.9 5.1 5.1 1.9-5.1 1.9L12 16.5l-1.9-5.1L5 9.5l5.1-1.9L12 2.5zM19 15l.9 2.4 2.4.9-2.4.9L19 22l-.9-2.4-2.4-.9 2.4-.9L19 15z" />
    </svg>
  )
}

function RefreshIcon({ className }: { className?: string }) {
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
      <path d="M21 12a9 9 0 1 1-2.64-6.36" />
      <path d="M21 3v6h-6" />
    </svg>
  )
}
