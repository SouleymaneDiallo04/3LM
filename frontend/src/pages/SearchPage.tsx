import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import {
  deleteSavedFilter,
  getFacets,
  getReferentials,
  getSearchHistory,
  listSavedFilters,
  saveFilter,
  searchEstablishments,
  type RegionRef,
  type SavedFilter,
  type SearchHistoryEntry,
} from '../features/catalog/api'
import { createExport, downloadExport, getExport } from '../features/exports/api'
import type { CursorPage, Establishment, ExportFormat, ExportStatus, SearchFilters } from '../lib/types'

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
  const [facets, setFacets] = useState<{
    departments: { code: string; count: number }[]
    naf_divisions: { code: string; count: number }[]
  } | null>(null)
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null)

  // Référentiel régions (EF-01.2) pour le filtre géographique.
  const [regions, setRegions] = useState<RegionRef[]>([])
  useEffect(() => {
    getReferentials().then(setRegions).catch(() => setRegions([]))
  }, [])

  // Filtres sauvegardés et partagés (EF-03.4).
  const [savedFilters, setSavedFilters] = useState<SavedFilter[]>([])
  const [selectedFilterId, setSelectedFilterId] = useState<number | ''>('')

  const refreshSavedFilters = useCallback(async () => {
    try {
      setSavedFilters(await listSavedFilters())
    } catch {
      // non bloquant : la recherche fonctionne sans les filtres sauvegardés
    }
  }, [])

  useEffect(() => {
    void refreshSavedFilters()
  }, [refreshSavedFilters])

  const runSearch = useCallback(async (criteria: SearchFilters, cursorUrl?: string | null) => {
    setLoading(true)
    setError(null)
    try {
      setPage(await searchEstablishments(criteria, cursorUrl))
      setApplied(criteria)
      // Facettes (EF-03.3) : compteurs du résultat courant — pas re-calculées
      // au fil des pages, uniquement sur la requête initiale.
      if (!cursorUrl) {
        getFacets(criteria).then(setFacets).catch(() => setFacets(null))
      }
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

  async function handleExport(format: ExportFormat) {
    if (!applied) return
    setExportJob(await createExport(format, applied))
  }

  const set = (patch: Partial<SearchFilters>) => setFilters((f) => ({ ...f, ...patch }))

  function applySavedFilter(id: number | '') {
    setSelectedFilterId(id)
    const saved = savedFilters.find((f) => f.id === id)
    if (!saved) return
    const next: SearchFilters = { status: 'active', ...saved.criteria }
    setFilters(next)
    void runSearch(next)
  }

  async function handleSaveFilter() {
    const name = window.prompt('Nom du filtre sauvegardé :')
    if (!name) return
    const shared = window.confirm('Partager ce filtre avec toute l\'équipe ?\nOK = partagé, Annuler = privé.')
    await saveFilter(name, applied ?? filters, shared)
    void refreshSavedFilters()
  }

  async function handleDeleteFilter() {
    const saved = savedFilters.find((f) => f.id === selectedFilterId)
    if (!saved || !saved.is_owner) return
    await deleteSavedFilter(saved.id)
    setSelectedFilterId('')
    void refreshSavedFilters()
  }

  // Historique des recherches (EF-01.6) : chargé à l'ouverture du volet.
  const [history, setHistory] = useState<SearchHistoryEntry[] | null>(null)

  async function openHistory(open: boolean) {
    if (!open) return
    try {
      setHistory(await getSearchHistory())
    } catch {
      setHistory([])
    }
  }

  function replaySearch(entry: SearchHistoryEntry) {
    const next: SearchFilters = { status: 'active', ...(entry.filters ?? {}) }
    setFilters(next)
    void runSearch(next)
  }

  // Rayon posé depuis la carte (EF-01.3) : lat/lng/radius_km dans l'URL
  // pré-remplissent les critères et lancent la recherche à l'arrivée.
  const [searchParams] = useSearchParams()
  useEffect(() => {
    const lat = Number(searchParams.get('lat'))
    const lng = Number(searchParams.get('lng'))
    const radius = Number(searchParams.get('radius_km'))
    if (lat && lng && radius) {
      const initial: SearchFilters = { status: 'active', lat, lng, radius_km: radius }
      setFilters(initial)
      void runSearch(initial)
    }
    // Uniquement à l'arrivée sur la page : la suite se joue dans le formulaire.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function clearRadius() {
    const next = { ...filters, lat: undefined, lng: undefined, radius_km: undefined }
    setFilters(next)
    void runSearch(next)
  }

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
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
          />
        </label>
        <label className="text-sm">
          <span className="text-slate-600">Région</span>
          <select
            value={filters.region ?? ''}
            onChange={(e) => set({ region: e.target.value || undefined })}
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
          >
            <option value="">Toutes</option>
            {regions.map((r) => (
              <option key={r.code} value={r.code}>{r.name}</option>
            ))}
          </select>
        </label>
        <label className="text-sm">
          <span className="text-slate-600">Département</span>
          <input
            type="text"
            value={filters.department ?? ''}
            onChange={(e) => set({ department: e.target.value })}
            placeholder="33, 75, 2A…"
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
          />
        </label>
        <label className="text-sm">
          <span className="text-slate-600">Code postal</span>
          <input
            type="text"
            value={filters.postal_code ?? ''}
            onChange={(e) => set({ postal_code: e.target.value || undefined })}
            placeholder="33000"
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
          />
        </label>
        <label className="text-sm">
          <span className="text-slate-600">Ville</span>
          <input
            type="text"
            value={filters.city ?? ''}
            onChange={(e) => set({ city: e.target.value })}
            placeholder="BORDEAUX"
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
          />
        </label>
        <label className="text-sm">
          <span className="text-slate-600">Code NAF</span>
          <input
            type="text"
            value={filters.naf ?? ''}
            onChange={(e) => set({ naf: e.target.value })}
            placeholder="10.71C ou 45"
            className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
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
              className="rounded-md border border-slate-300 px-2 py-1 focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
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
            className="rounded-md bg-sky-700 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-800 disabled:opacity-50"
          >
            {loading ? 'Recherche…' : 'Rechercher'}
          </button>
        </div>

        <div className="col-span-2 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 md:col-span-4">
          <label className="flex items-center gap-1.5 text-sm text-slate-600">
            Filtres sauvegardés
            <select
              value={selectedFilterId}
              onChange={(e) => applySavedFilter(e.target.value === '' ? '' : Number(e.target.value))}
              className="rounded-md border border-slate-300 px-2 py-1 focus:border-sky-600 focus:ring-2 focus:ring-sky-100 focus:outline-none"
            >
              <option value="">—</option>
              {savedFilters.map((f) => (
                <option key={f.id} value={f.id}>
                  {f.name}
                  {f.is_shared ? (f.is_owner ? ' (partagé)' : ` (par ${f.owner})`) : ''}
                </option>
              ))}
            </select>
          </label>
          <button
            type="button"
            onClick={() => void handleSaveFilter()}
            className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
          >
            Sauvegarder ces critères
          </button>
          {savedFilters.find((f) => f.id === selectedFilterId)?.is_owner && (
            <button
              type="button"
              onClick={() => void handleDeleteFilter()}
              className="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50"
            >
              Supprimer ce filtre
            </button>
          )}
        </div>
      </form>

      {error && (
        <p role="alert" className="mt-3 text-sm text-red-600">
          {error}
        </p>
      )}

      <details
        className="mt-3 rounded-xl border border-slate-200 bg-white"
        onToggle={(e) => void openHistory((e.target as HTMLDetailsElement).open)}
      >
        <summary className="cursor-pointer px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
          Historique des recherches
        </summary>
        <div className="border-t border-slate-200">
          {history === null && (
            <p className="px-4 py-3 text-sm text-slate-500">Chargement…</p>
          )}
          {history?.length === 0 && (
            <p className="px-4 py-3 text-sm text-slate-500">
              Aucune recherche enregistrée pour le moment.
            </p>
          )}
          {(history ?? []).map((entry) => (
            <button
              key={entry.id}
              type="button"
              onClick={() => replaySearch(entry)}
              className="flex w-full items-center gap-4 border-b border-slate-100 px-4 py-2 text-left text-sm transition-colors duration-150 hover:bg-sky-50/40"
            >
              <span className="w-32 shrink-0 font-mono text-xs tabular-nums text-slate-500">
                {new Date(entry.created_at).toLocaleString('fr-FR', {
                  day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
                })}
              </span>
              <span className="min-w-0 flex-1 truncate text-slate-800">
                {[
                  entry.keyword,
                  entry.city,
                  entry.department_code && `dépt ${entry.department_code}`,
                  entry.radius_km && `rayon ${entry.radius_km} km`,
                ]
                  .filter(Boolean)
                  .join(' · ') || 'Tous les établissements'}
              </span>
              {entry.results_count !== null && (
                <span className="shrink-0 font-mono text-xs tabular-nums text-slate-500">
                  {entry.results_count.toLocaleString('fr-FR')} rés.
                </span>
              )}
            </button>
          ))}
        </div>
      </details>

      {/* La recherche peut prendre quelques secondes : l'attente est montrée,
          jamais silencieuse (« rien ne s'affiche » = bug). */}
      {loading && (
        <div
          role="status"
          className="mt-4 rounded-xl border border-slate-200 bg-white p-4"
        >
          <p className="text-sm font-medium text-slate-700">Recherche en cours…</p>
          <div className="mt-3 animate-pulse space-y-2">
            <div className="h-3 w-2/3 rounded bg-slate-100" />
            <div className="h-3 w-1/2 rounded bg-slate-100" />
            <div className="h-3 w-3/5 rounded bg-slate-100" />
          </div>
        </div>
      )}

      {filters.radius_km !== undefined && (
        <p className="mt-3 flex items-center gap-2 text-sm text-slate-600">
          <span className="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs text-sky-900">
            Rayon de {filters.radius_km} km autour du point choisi sur la carte
          </span>
          <button
            type="button"
            onClick={clearRadius}
            className="text-xs text-sky-700 hover:underline"
          >
            Retirer le rayon
          </button>
        </p>
      )}

      {page && facets && (facets.departments.length > 0 || facets.naf_divisions.length > 0) && (
        <div className="mt-4 space-y-2 rounded-xl border border-slate-200 bg-white p-3">
          {(
            [
              ['Départements', 'department', facets.departments],
              ['Divisions NAF', 'naf', facets.naf_divisions],
            ] as const
          ).map(([title, key, values]) => (
            values.length > 0 && (
              <div key={key} className="flex flex-wrap items-center gap-1.5">
                <span className="mr-1 text-xs font-semibold uppercase text-slate-500">
                  {title}
                </span>
                {values.map(({ code, count }) => {
                  const active = applied?.[key] === code
                  return (
                    <button
                      key={code}
                      type="button"
                      onClick={() => {
                        const next = { ...(applied ?? filters), [key]: active ? undefined : code }
                        setFilters(next)
                        void runSearch(next)
                      }}
                      className={`rounded-full border px-2.5 py-0.5 text-xs ${
                        active
                          ? 'border-sky-700 bg-sky-50 font-semibold text-sky-800'
                          : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                      }`}
                    >
                      {code} · {count.toLocaleString('fr-FR')}
                    </button>
                  )
                })}
              </div>
            )
          ))}
        </div>
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
                      className="ml-2 font-semibold text-sky-700 hover:underline"
                    >
                      Télécharger ({exportJob.rows_count} lignes)
                    </button>
                  )}
                </span>
              )}
              {(
                [
                  ['csv', 'CSV'],
                  ['xlsx', 'Excel'],
                  ['json', 'JSON'],
                  ['xml', 'XML'],
                  ['sql', 'SQL'],
                ] as const
              ).map(([format, label]) => (
                <button
                  key={format}
                  type="button"
                  onClick={() => void handleExport(format)}
                  className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                >
                  {label}
                </button>
              ))}
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-2">Nom</th>
                  <th className="px-4 py-2">SIRET</th>
                  <th className="px-4 py-2">NAF</th>
                  <th className="px-4 py-2">Ville</th>
                  <th className="px-4 py-2">Contact</th>
                </tr>
              </thead>
              <tbody>
                {page.data.map((e) => (
                  <tr key={e.id} className="border-b border-slate-100 transition-colors duration-150 hover:bg-sky-50/40">
                    <td className="px-4 py-2">
                      <Link
                        to={`/entreprises/${e.id}`}
                        className="font-medium text-sky-800 hover:underline"
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
              className="text-sm text-sky-700 hover:underline disabled:text-slate-300"
            >
              ← Précédent
            </button>
            <button
              type="button"
              disabled={!page.links.next || loading}
              onClick={() => applied && void runSearch(applied, page.links.next)}
              className="text-sm text-sky-700 hover:underline disabled:text-slate-300"
            >
              Suivant →
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
