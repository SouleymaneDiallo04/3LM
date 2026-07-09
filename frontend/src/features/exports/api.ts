import { api } from '../../lib/api'
import type { ExportStatus, SearchFilters } from '../../lib/types'

/** Crée un export asynchrone du résultat filtré courant (EF-07.1/07.4). */
export async function createExport(
  format: 'csv' | 'xlsx',
  filters: SearchFilters,
): Promise<ExportStatus> {
  const cleanFilters = Object.fromEntries(
    Object.entries(filters).filter(([, v]) => v !== undefined && v !== '' && v !== null),
  )
  const { data } = await api.post('/exports', { format, filters: cleanFilters })
  return data.data
}

/** Statut d'un export. */
export async function getExport(id: number): Promise<ExportStatus> {
  const { data } = await api.get(`/exports/${id}`)
  return data.data
}

/**
 * Télécharge le fichier d'un export terminé. Le lien direct ne suffit pas
 * (token Bearer requis) : le blob est récupéré puis servi localement.
 */
export async function downloadExport(exportStatus: ExportStatus): Promise<void> {
  const response = await api.get(`/exports/${exportStatus.id}/download`, {
    responseType: 'blob',
  })

  const url = URL.createObjectURL(response.data)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `fbde_export_${exportStatus.id}.${exportStatus.format}`
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}
