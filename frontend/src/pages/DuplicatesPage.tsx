import { useCallback, useEffect, useState } from 'react'
import {
  getDuplicates,
  markDistinct,
  mergeDuplicate,
  type DuplicatePair,
  type DuplicateSide,
} from '../features/catalog/api'
import { useAuth } from '../features/auth/AuthContext'

/**
 * Revue manuelle des doublons (EF-04.2) : chaque paire probable est
 * présentée côté à côté ; le relecteur choisit le survivant (fusion) ou
 * déclare les deux fiches distinctes. Réservé à duplicates.review.
 */
export default function DuplicatesPage() {
  const { hasPermission } = useAuth()
  const [pairs, setPairs] = useState<DuplicatePair[] | null>(null)
  const [busy, setBusy] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  const refresh = useCallback(async () => {
    try {
      setPairs(await getDuplicates())
    } catch {
      setError('Impossible de charger les doublons.')
    }
  }, [])

  useEffect(() => {
    if (hasPermission('duplicates.review')) void refresh()
  }, [refresh, hasPermission])

  if (!hasPermission('duplicates.review')) {
    return (
      <p className="text-sm text-slate-500">
        Votre rôle ne donne pas accès à la revue des doublons.
      </p>
    )
  }

  async function resolve(action: () => Promise<void>, pairId: number) {
    setBusy(pairId)
    setError(null)
    try {
      await action()
      setPairs((prev) => (prev ?? []).filter((p) => p.id !== pairId))
    } catch {
      setError('Action refusée. La paire a peut-être déjà été traitée.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="mx-auto max-w-6xl">
      <h1 className="text-lg font-semibold tracking-tight text-slate-900">Revue des doublons</h1>
      <p className="mt-1 text-sm text-slate-500">
        Paires probables (même ville, dénominations proches, SIREN différents). Choisissez la
        fiche à conserver — elle récupère les données manquantes — ou déclarez-les distinctes.
      </p>

      {error && <p role="alert" className="mt-3 text-sm text-red-600">{error}</p>}

      {pairs === null && <p className="mt-4 text-sm text-slate-500">Chargement…</p>}
      {pairs?.length === 0 && (
        <div className="mt-4 rounded-xl border border-slate-200 bg-white shadow-sm p-6 text-center text-sm text-slate-500">
          Aucune paire en attente. Lancez la détection avec{' '}
          <code className="font-mono text-slate-700">fbde:duplicates:detect</code>.
        </div>
      )}

      <div className="mt-4 space-y-3">
        {(pairs ?? []).map((pair) => (
          <div key={pair.id} className="rounded-xl border border-slate-200 bg-white shadow-sm p-4">
            <div className="mb-3 flex items-center justify-between">
              <span className="text-xs font-medium uppercase tracking-wide text-slate-500">
                Similarité{' '}
                <span className="font-mono tabular-nums text-slate-700">
                  {Math.round(pair.similarity * 100)} %
                </span>
              </span>
              <button
                type="button"
                disabled={busy === pair.id}
                onClick={() => void resolve(() => markDistinct(pair.id), pair.id)}
                className="rounded-md border border-slate-300 px-3 py-1 text-xs font-medium text-slate-700 transition-colors duration-150 hover:bg-slate-50 disabled:opacity-50"
              >
                Ce ne sont pas des doublons
              </button>
            </div>

            <div className="grid gap-3 md:grid-cols-2">
              <SideCard side={pair.a} pairId={pair.id} busy={busy === pair.id} onKeep={resolve} />
              <SideCard side={pair.b} pairId={pair.id} busy={busy === pair.id} onKeep={resolve} />
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

function SideCard({
  side,
  pairId,
  busy,
  onKeep,
}: {
  side: DuplicateSide
  pairId: number
  busy: boolean
  onKeep: (action: () => Promise<void>, pairId: number) => Promise<void>
}) {
  return (
    <div className="flex flex-col rounded-lg border border-slate-200 bg-slate-50/60 p-4">
      <p className="font-medium text-slate-900">{side.name ?? '—'}</p>
      <p className="font-mono text-xs text-slate-500">SIRET {side.siret}</p>
      <dl className="mt-2 flex-1 space-y-1 text-sm text-slate-600">
        <p>{side.address || '—'}</p>
        <p>NAF : {side.naf_code ?? '—'}</p>
        <p>Tél : {side.phone ?? '—'}</p>
        <p>Email : {side.email ?? '—'}</p>
        <p className="truncate">Site : {side.website ?? '—'}</p>
        <p className="text-xs text-slate-400">{side.filled_fields} champ(s) renseigné(s)</p>
      </dl>
      <button
        type="button"
        disabled={busy}
        onClick={() => void onKeep(() => mergeDuplicate(pairId, side.id), pairId)}
        className="mt-3 rounded-md bg-sky-700 px-3 py-1.5 text-sm font-semibold text-white transition-colors duration-150 hover:bg-sky-800 disabled:opacity-50"
      >
        Conserver celle-ci, fusionner l'autre
      </button>
    </div>
  )
}
