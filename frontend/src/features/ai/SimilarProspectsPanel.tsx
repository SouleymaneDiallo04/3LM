import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import type { Establishment } from '../../lib/types'
import { aiErrorMessage, getSimilar, isNotIndexedError, type SimilarProspect } from './api'

type Status = 'loading' | 'ready' | 'error'
type ErrorKind = 'not_indexed' | 'other'

/**
 * Carte « Prospects similaires » (EF-08.6). Chargée automatiquement au
 * montage (pas d'action utilisateur) : liste des entreprises les plus
 * proches dans l'espace des embeddings. Une fiche non encore indexée (lot
 * `fbde:ai:embed` pas encore passé) affiche un état pédagogique, distinct
 * d'un refus RGPD ou d'une panne du service.
 */
export default function SimilarProspectsPanel({ establishment }: { establishment: Establishment }) {
  const [status, setStatus] = useState<Status>('loading')
  const [prospects, setProspects] = useState<SimilarProspect[]>([])
  const [errorKind, setErrorKind] = useState<ErrorKind>('other')
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    setStatus('loading')
    setError(null)
    getSimilar(establishment.id)
      .then((data) => {
        if (cancelled) return
        setProspects(data)
        setStatus('ready')
      })
      .catch((err) => {
        if (cancelled) return
        if (isNotIndexedError(err)) {
          setErrorKind('not_indexed')
        } else {
          setErrorKind('other')
          setError(aiErrorMessage(err))
        }
        setStatus('error')
      })
    return () => {
      cancelled = true
    }
  }, [establishment.id])

  return (
    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm md:col-span-2">
      <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
        <SparkleIcon className="h-4 w-4 text-sky-600" />
        Prospects similaires
        <span className="rounded bg-sky-50 px-1.5 py-0.5 text-[0.65rem] font-semibold tracking-wide text-sky-700">
          IA
        </span>
      </h2>

      <div className="mt-3" aria-live="polite" aria-busy={status === 'loading'}>
        {status === 'loading' ? (
          <ListSkeleton />
        ) : status === 'error' ? (
          errorKind === 'not_indexed' ? (
            <p className="max-w-xl text-sm text-slate-500">
              Cette fiche n'est pas encore indexée pour la similarité. L'indexation se fait par
              lots.
            </p>
          ) : (
            <p className="max-w-xl text-sm text-slate-600">{error}</p>
          )
        ) : prospects.length === 0 ? (
          <p className="max-w-xl text-sm text-slate-500">Aucun prospect similaire trouvé.</p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {prospects.map((prospect) => (
              <ProspectRow key={prospect.id} prospect={prospect} />
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}

function ProspectRow({ prospect }: { prospect: SimilarProspect }) {
  const subtext = [prospect.city, prospect.naf_code].filter(Boolean).join(' · ')

  return (
    <li className="flex items-center justify-between gap-3 py-2.5">
      <div className="min-w-0">
        <Link
          to={`/entreprises/${prospect.id}`}
          className="block truncate text-sm font-medium text-sky-700 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
        >
          {prospect.name ?? prospect.siret}
        </Link>
        {subtext && <p className="truncate text-xs text-slate-500">{subtext}</p>}
      </div>

      <div className="flex shrink-0 items-center gap-3">
        {prospect.commercial_tier && <TierBadge tier={prospect.commercial_tier} />}
        <span
          className="w-11 text-right font-mono text-xs text-slate-400"
          title="Proximité avec la fiche courante"
        >
          {Math.round(prospect.proximity * 100)} %
        </span>
      </div>
    </li>
  )
}

function TierBadge({ tier }: { tier: 'A' | 'B' | 'C' | 'D' }) {
  return (
    <span
      className={`rounded px-1.5 py-0.5 text-xs font-semibold ${
        tier === 'A'
          ? 'bg-green-100 text-green-800'
          : tier === 'B'
            ? 'bg-sky-100 text-sky-800'
            : tier === 'C'
              ? 'bg-amber-100 text-amber-800'
              : 'bg-slate-100 text-slate-600'
      }`}
    >
      Palier {tier}
    </span>
  )
}

function ListSkeleton() {
  return (
    <div className="space-y-3" aria-hidden="true">
      {[0, 1, 2].map((i) => (
        <div key={i} className="flex items-center justify-between gap-3">
          <div className="fbde-skeleton h-3.5 w-2/5 rounded" />
          <div className="fbde-skeleton h-3.5 w-16 rounded" />
        </div>
      ))}
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
