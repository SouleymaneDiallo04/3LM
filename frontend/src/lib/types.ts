/** Fiche établissement telle que servie par l'API (§7, ADR 0003). */
export interface Establishment {
  id: number
  siret: string
  name: string | null
  is_headquarters: boolean
  status: 'active' | 'ceased' | 'unknown'
  naf_code: string | null
  employee_range: string | null
  address: {
    line: string | null
    postal_code: string | null
    city: string | null
    city_code: string | null
    department_code: string | null
  }
  coordinates?: { longitude: number; latitude: number }
  geo_source: string | null
  contact: {
    phone: string | null
    website: string | null
    email: string | null
  }
  opening_hours?: { raw?: string; source?: string } | null
  social_links?: Record<string, string> | null
  contact_form_url?: string | null
  description?: string | null
  technologies?: { cms: string | null; libs: string[] } | null
  rating: number | null
  reviews_count: number | null
  rating_source: string | null
  reputation_score: number | null
  commercial_score: number | null
  commercial_tier: 'A' | 'B' | 'C' | 'D' | null
  company?: {
    siren: string
    legal_name: string
    legal_form: string | null
    status: string
    employee_range: string | null
    incorporated_at: string | null
  }
  imported_at: string | null
  enriched_at: string | null
  crawled_at?: string | null
}

/** Réponse paginée par curseur (§9.2). */
export interface CursorPage<T> {
  data: T[]
  links: { next: string | null; prev: string | null }
  meta: { per_page: number }
}

/** Critères de recherche (EF-01, EF-03.1a). */
export interface SearchFilters {
  q?: string
  region?: string
  department?: string
  city?: string
  postal_code?: string
  naf?: string
  status?: 'active' | 'ceased' | 'all'
  has_email?: boolean
  has_phone?: boolean
  has_website?: boolean
  min_rating?: number
  min_reviews?: number
  min_employees?: string
  min_tier?: 'A' | 'B' | 'C' | 'D'
  lat?: number
  lng?: number
  radius_km?: number
  per_page?: number
  sort?: 'name' | 'imported_at' | 'commercial_score' | 'rating'
  direction?: 'asc' | 'desc'
}

/** Point léger servi par la carte (EF-06.3, correctif 16). */
export interface MapPoint {
  id: number
  siret: string
  name: string | null
  longitude: number
  latitude: number
}

/** Agrégat par cellule quand l'emprise dépasse le plafond de points. */
export interface MapCluster {
  longitude: number
  latitude: number
  count: number
}

/** Réponse de GET /companies/map : points ou agrégats, jamais toute la base. */
export type MapData =
  | { mode: 'points'; total: number; points: MapPoint[] }
  | { mode: 'clusters'; total: number; clusters: MapCluster[] }

/** Utilisateur vu par l'administration (EF-10.2). */
export interface ManagedUser {
  id: number
  name: string
  email: string
  roles: string[]
  disabled_at: string | null
  two_factor_enabled: boolean
  created_at: string | null
}

/** Notification interface (EF-10.4). */
export interface AppNotification {
  id: string
  type: 'ExportReady' | 'SireneImportFinished' | string
  data: {
    export_id?: number
    format?: string
    rows_count?: number
    import_id?: number
    source?: string
    stats?: unknown
  }
  read_at: string | null
  created_at: string
}

/** Formats d'export (EF-07.1, EF-07.2). */
export type ExportFormat = 'csv' | 'xlsx' | 'json' | 'xml' | 'sql'

/** Suivi d'un export asynchrone (EF-07). */
export interface ExportStatus {
  id: number
  format: ExportFormat
  status: 'pending' | 'running' | 'completed' | 'failed'
  rows_count: number | null
  expires_at: string | null
  error: string | null
}
