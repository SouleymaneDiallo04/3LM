import { api } from '../../lib/api'
import type { CursorPage, Establishment, SearchFilters } from '../../lib/types'

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

/** Fiche complète d'un établissement. */
export async function getEstablishment(id: number | string): Promise<Establishment> {
  const { data } = await api.get(`/companies/${id}`)
  return data.data
}
