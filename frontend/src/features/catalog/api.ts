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

/** Fiche complète d'un établissement. */
export async function getEstablishment(id: number | string): Promise<Establishment> {
  const { data } = await api.get(`/companies/${id}`)
  return data.data
}
