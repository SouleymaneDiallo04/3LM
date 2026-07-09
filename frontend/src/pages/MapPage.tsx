import { divIcon, type Map as LeafletMap } from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { CircleMarker, Circle, MapContainer, Marker, Popup, TileLayer, useMapEvents } from 'react-leaflet'
import { fetchMapData } from '../features/catalog/api'
import type { MapData, SearchFilters } from '../lib/types'

/**
 * Carte interactive (EF-06.3, correctif 16) : chargement par emprise visible,
 * plafonné côté serveur — points individuels zoomé, agrégats par cellule
 * dézoomé. Le mode « rayon de prospection » pose un centre au clic (EF-01.3)
 * et renvoie vers la recherche avec le rayon choisi.
 */

/** Emprise Leaflet → « minLon,minLat,maxLon,maxLat » borné aux limites WGS84. */
function toBbox(map: LeafletMap): string {
  const b = map.getBounds()
  const clampLon = (v: number) => Math.max(-180, Math.min(180, v))
  const clampLat = (v: number) => Math.max(-90, Math.min(90, v))
  return [
    clampLon(b.getWest()),
    clampLat(b.getSouth()),
    clampLon(b.getEast()),
    clampLat(b.getNorth()),
  ]
    .map((v) => v.toFixed(5))
    .join(',')
}

/** Icône d'agrégat : disque bleu, compteur en blanc (styles dans index.css). */
function clusterIcon(count: number) {
  const size = count >= 10_000 ? 52 : count >= 1_000 ? 44 : 36
  const label = count >= 10_000 ? `${Math.round(count / 1000)}k`
    : count >= 1_000 ? `${(count / 1000).toFixed(1)}k`
    : String(count)
  return divIcon({
    html: `<div>${label}</div>`,
    className: 'fbde-cluster',
    iconSize: [size, size],
  })
}

/** Remonte l'emprise au chargement et à chaque déplacement ; gère le clic rayon. */
function MapEvents({
  onBounds,
  onPick,
}: {
  onBounds: (bbox: string) => void
  onPick: ((lat: number, lng: number) => void) | null
}) {
  const map = useMapEvents({
    moveend: () => onBounds(toBbox(map)),
    click: (e) => onPick?.(e.latlng.lat, e.latlng.lng),
  })

  useEffect(() => {
    onBounds(toBbox(map))
  }, [map, onBounds])

  return null
}

export default function MapPage() {
  const navigate = useNavigate()
  // Vue initiale surchargée par l'URL (vue partageable, QA reproductible).
  const [searchParams] = useSearchParams()
  const initialCenter: [number, number] = [
    Number(searchParams.get('lat')) || 46.6,
    Number(searchParams.get('lng')) || 2.4,
  ]
  const initialZoom = Number(searchParams.get('zoom')) || 6
  const [filters, setFilters] = useState<SearchFilters>({ status: 'active' })
  const [data, setData] = useState<MapData | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Mode rayon (EF-01.3) : armé → le prochain clic pose le centre.
  const [pickingCenter, setPickingCenter] = useState(false)
  const [center, setCenter] = useState<{ lat: number; lng: number } | null>(null)
  const [radiusKm, setRadiusKm] = useState(10)

  const bboxRef = useRef<string | null>(null)
  // Filtres effectivement appliqués (au submit) : la saisie en cours ne doit
  // pas déclencher de rechargement à chaque déplacement de carte.
  const appliedRef = useRef<SearchFilters>({ status: 'active' })
  const abortRef = useRef<AbortController | null>(null)

  const load = useCallback(async (bbox: string, criteria: SearchFilters) => {
    abortRef.current?.abort()
    const controller = new AbortController()
    abortRef.current = controller

    setLoading(true)
    setError(null)
    try {
      setData(await fetchMapData(bbox, criteria, controller.signal))
      setLoading(false)
    } catch {
      if (!controller.signal.aborted) {
        setError('Le chargement de la carte a échoué. Déplacez la carte pour réessayer.')
        setLoading(false)
      }
    }
  }, [])

  const handleBounds = useCallback(
    (bbox: string) => {
      bboxRef.current = bbox
      void load(bbox, appliedRef.current)
    },
    [load],
  )

  function handleSubmit(event: FormEvent) {
    event.preventDefault()
    appliedRef.current = filters
    if (bboxRef.current) void load(bboxRef.current, filters)
  }

  function handlePick(lat: number, lng: number) {
    setCenter({ lat, lng })
    setPickingCenter(false)
  }

  function searchWithinRadius() {
    if (!center) return
    navigate(
      `/recherche?lat=${center.lat.toFixed(5)}&lng=${center.lng.toFixed(5)}&radius_km=${radiusKm}`,
    )
  }

  const set = (patch: Partial<SearchFilters>) => setFilters((f) => ({ ...f, ...patch }))

  return (
    <div>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Carte des entreprises</h1>
          <p className="mt-1 text-sm text-slate-500">
            La carte charge ce qui est visible — zoomez pour passer des zones aux établissements.
          </p>
        </div>

        <form onSubmit={handleSubmit} className="flex flex-wrap items-end gap-2">
          <label className="text-sm">
            <span className="text-slate-600">Mot-clé</span>
            <input
              type="text"
              value={filters.q ?? ''}
              onChange={(e) => set({ q: e.target.value || undefined })}
              placeholder="boulangerie…"
              className="mt-1 block w-36 rounded-md border border-slate-300 px-2 py-1.5"
            />
          </label>
          <label className="text-sm">
            <span className="text-slate-600">Code NAF</span>
            <input
              type="text"
              value={filters.naf ?? ''}
              onChange={(e) => set({ naf: e.target.value || undefined })}
              placeholder="10.71C ou 45"
              className="mt-1 block w-28 rounded-md border border-slate-300 px-2 py-1.5"
            />
          </label>
          <label className="flex items-center gap-1.5 pb-2 text-sm text-slate-600">
            <input
              type="checkbox"
              checked={Boolean(filters.has_email)}
              onChange={(e) => set({ has_email: e.target.checked || undefined })}
            />
            Email présent
          </label>
          <button
            type="submit"
            disabled={loading}
            className="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
          >
            Filtrer
          </button>
        </form>
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-2">
        <span className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs text-slate-600">
          {loading
            ? 'Chargement…'
            : data === null
              ? 'En attente de la carte'
              : data.mode === 'points'
                ? `${data.total.toLocaleString('fr-FR')} établissement${data.total > 1 ? 's' : ''} dans la vue`
                : `${data.total.toLocaleString('fr-FR')} établissements — zoomez pour afficher les points`}
        </span>

        {center === null ? (
          <button
            type="button"
            onClick={() => setPickingCenter((v) => !v)}
            className={`rounded-full border px-3 py-1 text-xs font-medium ${
              pickingCenter
                ? 'border-blue-600 bg-blue-50 text-blue-700'
                : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
            }`}
          >
            {pickingCenter ? 'Cliquez sur la carte pour poser le centre' : 'Rayon de prospection'}
          </button>
        ) : (
          <span className="flex items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs text-blue-800">
            Rayon
            <input
              type="range"
              min={1}
              max={100}
              value={radiusKm}
              onChange={(e) => setRadiusKm(Number(e.target.value))}
              className="w-28"
            />
            <span className="w-12 font-semibold">{radiusKm} km</span>
            <button
              type="button"
              onClick={searchWithinRadius}
              className="rounded bg-blue-600 px-2 py-0.5 font-semibold text-white hover:bg-blue-700"
            >
              Rechercher dans ce rayon
            </button>
            <button
              type="button"
              onClick={() => setCenter(null)}
              className="text-blue-700 hover:underline"
            >
              Retirer
            </button>
          </span>
        )}

        {error && (
          <span role="alert" className="text-xs text-red-600">
            {error}
          </span>
        )}
      </div>

      <div className="mt-3 h-[calc(100vh-16rem)] min-h-[420px] overflow-hidden rounded-xl border border-slate-200 bg-white">
        <MapContainer
          center={initialCenter}
          zoom={initialZoom}
          preferCanvas
          className="h-full w-full"
        >
          <TileLayer
            attribution='&copy; les contributeurs d&apos;<a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            url="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
          />
          <MapEvents onBounds={handleBounds} onPick={pickingCenter ? handlePick : null} />

          {data?.mode === 'points' &&
            data.points.map((p) => (
              <CircleMarker
                key={p.id}
                center={[p.latitude, p.longitude]}
                radius={6}
                pathOptions={{ color: '#2563eb', weight: 1.5, fillColor: '#3b82f6', fillOpacity: 0.7 }}
              >
                <Popup>
                  <p className="font-semibold">{p.name ?? 'Sans dénomination'}</p>
                  <p className="font-mono text-xs">{p.siret}</p>
                  <Link to={`/entreprises/${p.id}`} className="text-blue-600 hover:underline">
                    Voir la fiche
                  </Link>
                </Popup>
              </CircleMarker>
            ))}

          {data?.mode === 'clusters' &&
            data.clusters.map((c, i) => (
              <Marker
                key={`${c.longitude}-${c.latitude}-${i}`}
                position={[c.latitude, c.longitude]}
                icon={clusterIcon(c.count)}
              />
            ))}

          {center && (
            <Circle
              center={[center.lat, center.lng]}
              radius={radiusKm * 1000}
              pathOptions={{ color: '#2563eb', weight: 2, fillColor: '#3b82f6', fillOpacity: 0.08 }}
            />
          )}
        </MapContainer>
      </div>
    </div>
  )
}
