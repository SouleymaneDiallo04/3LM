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

/** Prospect voisin dans l'espace des embeddings (EF-08.6). */
export interface SimilarProspect {
  id: number
  siret: string
  name: string | null
  city: string | null
  naf_code: string | null
  commercial_tier: 'A' | 'B' | 'C' | 'D' | null
  proximity: number
}

export async function generateSummary(id: number, refresh = false): Promise<SummaryResult> {
  const { data } = await api.post(`/companies/${id}/summary${refresh ? '?refresh=1' : ''}`)
  return data.data
}

export async function generatePitch(id: number, channel: 'email' | 'call'): Promise<PitchResult> {
  const { data } = await api.post(`/companies/${id}/pitch`, { channel })
  return data.data
}

export async function getSimilar(id: number): Promise<SimilarProspect[]> {
  const { data } = await api.get(`/companies/${id}/similar`)
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

/**
 * Un 422 « non indexé » (embedding pas encore calculé, EF-08.6) est un état
 * pédagogique transitoire — à distinguer d'un refus RGPD, qui porte le même
 * code HTTP mais aucun indicateur `not_indexed`.
 */
export function isNotIndexedError(error: unknown): boolean {
  return (
    error instanceof AxiosError &&
    error.response?.status === 422 &&
    (error.response?.data as { not_indexed?: boolean } | undefined)?.not_indexed === true
  )
}
