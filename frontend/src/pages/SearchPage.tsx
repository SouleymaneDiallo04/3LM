import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { searchEstablishments } from '../features/catalog/api'
import { createExport, downloadExport, getExport } from '../features/exports/api'
import type { CursorPage, Establishment, ExportStatus, SearchFilters } from '../lib/types'

/**
 * Recherche multicritères (EF-01, EF-03.1a) : filtres combinables,
 * résultats paginés par curseur, export du résultat filtré courant (EF-07.1).
 */
export default function SearchPage() {
  const [filters, setFilters] = useState<SearchFilters>({ status: 'active' })
  const [applied, setApplied] = useState<SearchFilters | null>(null)
  const [page, setPage] = useState<CursorPage<Establishment> | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [exportJob, setExportJob] = useState<ExportStatus | null>(null)
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null)

  const runSearch = useCallback(async (criteria: SearchFilters, cursorUrl?: string | null) => {
    setLoading(true)
    setError(null)
    try {
      setPage(await searchEstablishments(criteria, cursorUrl))
      setApplied(criteria)
    } catch {
      setError('La recherche a échoué. Vérifiez les critères et réessayez.')
    } finally {
      setLoading(false)
    }
  }, [])

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    void runSearch(filters)
  }

  // Suivi de l'export : interrogation du statut jusqu'à complétion (EF-07.4).
  useEffect(() => {
    if (!exportJob || exportJob.status === 'completed' || exportJob.status === 'failed') {
      return
    }
    pollRef.current = setInterval(async () => {
      const refreshed = await getExport(exportJob.id)
      setExportJob(refreshed)
    }, 2000)

    return () => {
      if (pollRef.current) clearInterval(pollRef.current)
    }
  }, [exportJob])

  async function handleExport(format: 'csv' | 'xlsx') {
    if (!applied) return
    setExportJob(await createExport(format, applied))
  }

  const set = (patch: Partial<SearchFilters>) => setFilters((f) => ({ ...f, ...patch }))

  return (
    <div>
      <h1 className="text-xl font-semibold text-slate-900">Recherche d'entreprises</h1>
      <p className="mt-1 text-sm text-slate-500">
        Base SIRENE — critères combinables librement.
      </p>

      <form
        onSubmit={handleSubmit}
        className="mt-4 grid grid-cols-2 gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-4"
      >
        <label className="text-sm">
          <span className="text-slate-600">Mot-clé (nom)</span>
          <input
            type="text"
            value={filters.q ?? ''}
            onChange={(e) => set({ q: e.target.value })}
            placeholder="boulangerie, garage…"
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5"
          />
        </label>
        <label className="text-sm">
          <span className="text-slate-600">Département</span>
          <input
            type="text"
            value={filters.department ?? ''}
            onChange={(e) => set({ department: e.target.value })}
            placeholder="33, 75, 2A…"
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5"
          />
        </label>
        <label className="text-sm">
          <span className="text-slate-600">Ville</span>
          <input
            type="text"
            value={filters.city ?? ''}
            onChange={(e) => set({ city: e.target.value })}
            placeholder="BORDEAUX"
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5"
          />
        </label>
        <label className="text-sm">
          <span className="text-slate-600">Code NAF</span>
          <input
            type="text"
            value={filters.naf ?? ''}
            onChange={(e) => set({ naf: e.target.value })}
            placeholder="10.71C ou 45"
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5"
          />
        </label>

        <div className="col-span-2 flex flex-wrap items-end gap-4 md:col-span-3">
          {(
            [
              ['has_email', 'Email présent'],
              ['has_phone', 'Téléphone présent'],
              ['has_website', 'Site web présent'],
            ] as const
          ).map(([key, label]) => (
            <label key={key} className="flex items-center gap-1.5 text-sm text-slate-600">
              <input
                type="checkbox"
                checked={Boolean(filters[key])}
                onChange={(e) => set({ [key]: e.target.checked || undefined })}
              />
              {label}
            </label>
          ))}
          <label className="flex items-center gap-1.5 text-sm text-slate-600">
            <input
              type="checkbox"
              checked={filters.status === 'all'}
              onChange={(e) => set({ status: e.target.checked ? 'all' : 'active' })}
            />
            Inclure les établissements fermés
          </label>
          <label className="flex items-center gap-1.5 text-sm text-slate-600">
            Tri
            <select
              value={filters.sort ?? ''}
              onChange={(e) =>
                set({
                  sort: (e.target.value || undefined) as typeof filters.sort,
                  direction: e.target.value === 'name' ? 'asc' : 'desc',
                })
              }
              className="rounded-md border border-slate-300 px-2 py-1"
            >
              <option value="">Pertinence</option>
              <option value="name">Nom (A→Z)</option>
              <option value="imported_at">Ajout récent</option>
              <option value="commercial_score">Score commercial</option>
              <option value="rating">Note</option>
            </select>
          </label>
        </div>

        <div className="flex items-end justify-end">
          <button
            type="submit"
            disabled={loading}
            className="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {loading ? 'Recherche…' : 'Rechercher'}
          </button>
        </div>
      </form>

      {error && (
        <p role="alert" className="mt-3 text-sm text-red-600">
          {error}
        </p>
      )}

      {page && (
        <div className="mt-4 rounded-xl border border-slate-200 bg-white">
          <div className="flex items-center justify-between border-b border-slate-200 px-4 py-2">
            <p className="text-sm text-slate-600">
              Résultats — page de {page.data.length}
            </p>
            <div className="flex items-center gap-2">
              {exportJob && (
                <span className="text-xs text-slate-500">
                  Export #{exportJob.id} : {exportJob.status}
                  {exportJob.status === 'completed' && (
                    <button
                      type="button"
                      onClick={() => void downloadExport(exportJob)}
                      className="ml-2 font-semibold text-blue-600 hover:underline"
                    >
                      Télécharger ({exportJob.rows_count} lignes)
                    </button>
                  )}
                </span>
              )}
              <button
                type="button"
                onClick={() => void handleExport('csv')}
                className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
              >
                Export CSV
              </button>
              <button
                type="button"
                onClick={() => void handleExport('xlsx')}
                className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
              >
                Export Excel
              </button>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                  <th className="px-4 py-2">Nom</th>
                  <th className="px-4 py-2">SIRET</th>
                  <th className="px-4 py-2">NAF</th>
                  <th className="px-4 py-2">Ville</th>
                  <th className="px-4 py-2">Contact</th>
                </tr>
              </thead>
              <tbody>
                {page.data.map((e) => (
                  <tr key={e.id} className="border-b border-slate-100 hover:bg-slate-50">
                    <td className="px-4 py-2">
                      <Link
                        to={`/entreprises/${e.id}`}
                        className="font-medium text-blue-700 hover:underline"
                      >
                        {e.name ?? e.company?.legal_name ?? '—'}
                      </Link>
                      {e.is_headquarters && (
                        <span className="ml-1.5 rounded bg-slate-100 px-1 text-[10px] uppercase text-slate-500">
                          siège
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-2 font-mono text-xs">{e.siret}</td>
                    <td className="px-4 py-2">{e.naf_code ?? '—'}</td>
                    <td className="px-4 py-2">
                      {e.address.city ?? '—'}
                      {e.address.postal_code ? ` (${e.address.postal_code})` : ''}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-500">
                      {[
                        e.contact.email && '✉',
                        e.contact.phone && '☎',
                        e.contact.website && '🌐',
                      ]
                        .filter(Boolean)
                        .join(' ') || '—'}
                    </td>
                  </tr>
                ))}
                {page.data.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-4 py-6 text-center text-slate-500">
                      Aucun résultat pour ces critères.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <div className="flex justify-between px-4 py-2">
            <button
              type="button"
              disabled={!page.links.prev || loading}
              onClick={() => applied && void runSearch(applied, page.links.prev)}
              className="text-sm text-blue-600 hover:underline disabled:text-slate-300"
            >
              ← Précédent
            </button>
            <button
              type="button"
              disabled={!page.links.next || loading}
              onClick={() => applied && void runSearch(applied, page.links.next)}
              className="text-sm text-blue-600 hover:underline disabled:text-slate-300"
            >
              Suivant →
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
