import { api } from '../../lib/api'
import type { CursorPage, Establishment, MapData, SearchFilters } from '../../lib/types'

/** Recherche multicritères synchrone (§7, correctif 3). */
export async function searchEstablishments(
  filters: SearchFilters,
  cursorUrl?: string | null,
): Promise<CursorPage<Establishment>> {
  if (cursorUrl) {
    // Lien next/prev déjà complet renvoyé par l'API.
    const { data } = await api.get(cursorUrl)
    return data
  }

  const params = Object.fromEntries(
    Object.entries(filters).filter(([, v]) => v !== undefined && v !== '' && v !== null),
  )
  const { data } = await api.get('/companies', { params })
  return data
}

/** Contenu de la carte pour l'emprise visible (EF-06.3, correctif 16). */
export async function fetchMapData(
  bbox: string,
  filters: SearchFilters,
  signal?: AbortSignal,
): Promise<MapData> {
  const params = Object.fromEntries(
    Object.entries({ ...filters, bbox }).filter(([, v]) => v !== undefined && v !== '' && v !== null),
  )
  const { data } = await api.get('/companies/map', { params, signal })
  return data.data
}

/** Facettes du résultat courant (EF-03.3) : compteurs département + NAF. */
export async function getFacets(filters: SearchFilters): Promise<{
  departments: { code: string; count: number }[]
  naf_divisions: { code: string; count: number }[]
}> {
  const params = Object.fromEntries(
    Object.entries(filters).filter(([, v]) => v !== undefined && v !== '' && v !== null),
  )
  const { data } = await api.get('/companies/facets', { params })
  return data.data
}

/** Référentiel régions/départements (EF-01.2), donnée froide. */
export interface RegionRef {
  code: string
  name: string
  departments: { code: string; name: string }[]
}

export async function getReferentials(): Promise<RegionRef[]> {
  const { data } = await api.get('/referentiels')
  return data.data.regions
}

/** Historique des recherches (EF-01.6) : mot-clé, ville, département, rayon, date. */
export interface SearchHistoryEntry {
  id: number
  keyword: string | null
  city: string | null
  department_code: string | null
  radius_km: number | null
  results_count: number | null
  filters: SearchFilters | null
  created_at: string
}

export async function getSearchHistory(): Promise<SearchHistoryEntry[]> {
  const { data } = await api.get('/searches')
  return data.data
}

/** Filtres sauvegardés et partagés (EF-03.4). */
export interface SavedFilter {
  id: number
  name: string
  criteria: SearchFilters
  is_shared: boolean
  is_owner: boolean
  owner: string
}

export async function listSavedFilters(): Promise<SavedFilter[]> {
  const { data } = await api.get('/saved-filters')
  return data.data
}

export async function saveFilter(
  name: string,
  criteria: SearchFilters,
  isShared: boolean,
): Promise<void> {
  await api.post('/saved-filters', { name, criteria, is_shared: isShared })
}

export async function deleteSavedFilter(id: number): Promise<void> {
  await api.delete(`/saved-filters/${id}`)
}

/** Revue des doublons (EF-04.2). */
export interface DuplicateSide {
  id: number
  siret: string
  name: string | null
  address: string
  naf_code: string | null
  phone: string | null
  email: string | null
  website: string | null
  filled_fields: number
}
export interface DuplicatePair {
  id: number
  similarity: number
  a: DuplicateSide
  b: DuplicateSide
}

export async function getDuplicates(): Promise<DuplicatePair[]> {
  const { data } = await api.get('/duplicates')
  return data.data
}
export async function mergeDuplicate(pairId: number, keepId: number): Promise<void> {
  await api.post(`/duplicates/${pairId}/merge`, { keep_id: keepId })
}
export async function markDistinct(pairId: number): Promise<void> {
  await api.post(`/duplicates/${pairId}/distinct`)
}

/** Fiche complète d'un établissement. */
export async function getEstablishment(id: number | string): Promise<Establishment> {
  const { data } = await api.get(`/companies/${id}`)
  return data.data
}
