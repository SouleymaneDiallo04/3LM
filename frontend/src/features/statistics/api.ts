import { api } from '../../lib/api'

/** Agrégats du tableau de bord (§7 : GET /api/v1/statistics). */
export interface DashboardStatistics {
  kpis: {
    total_establishments: number
    active_establishments: number
    new_last_7_days: number
    new_last_30_days: number
    new_contacts_30_days: number
    enriched: number
    email_rate: number
    phone_rate: number
    website_rate: number
    geocoding_rate: number
  }
  by_department: { code: string; count: number }[]
  top_cities: { city: string; count: number }[]
  by_naf_division: { code: string; count: number }[]
  recent_imports: {
    id: number
    source: string
    status: string
    stats: Record<string, unknown> | null
    started_at: string | null
    finished_at: string | null
  }[]
  generated_at: string
}

export async function getStatistics(): Promise<DashboardStatistics> {
  const { data } = await api.get('/statistics')
  return data.data
}
