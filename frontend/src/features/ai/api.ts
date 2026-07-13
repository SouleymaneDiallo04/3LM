import { AxiosError } from 'axios'
import { api } from '../../lib/api'

/** Générations IA (EF-08.2 résumé, EF-08.4 argumentaire). */
export interface SummaryResult {
  summary: string
  version: string
  generated_at: string
}

export interface PitchResult {
  pitch: string
  channel: 'email' | 'call'
  version: string
}

export async function generateSummary(id: number, refresh = false): Promise<SummaryResult> {
  const { data } = await api.post(`/companies/${id}/summary${refresh ? '?refresh=1' : ''}`)
  return data.data
}

export async function generatePitch(id: number, channel: 'email' | 'call'): Promise<PitchResult> {
  const { data } = await api.post(`/companies/${id}/pitch`, { channel })
  return data.data
}

/**
 * Traduit une erreur de génération en message affichable. Le 422 (refus RGPD)
 * porte un message serveur explicite ; le 503 signale une panne transitoire.
 */
export function aiErrorMessage(error: unknown): string {
  if (error instanceof AxiosError) {
    const status = error.response?.status
    if (status === 422) {
      const message = (error.response?.data as { message?: string } | undefined)?.message
      return message ?? 'Génération non autorisée pour cette fiche.'
    }
    if (status === 503) {
      return 'Le service IA est momentanément indisponible. Réessayez dans un instant.'
    }
  }
  return 'Une erreur est survenue lors de la génération. Réessayez.'
}
