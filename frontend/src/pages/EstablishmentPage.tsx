import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import { getEstablishment } from '../features/catalog/api'
import type { Establishment } from '../lib/types'

/** Fiche entreprise (EF-02) — établissement + unité légale (ADR 0003). */
export default function EstablishmentPage() {
  const { id } = useParams()
  const [establishment, setEstablishment] = useState<Establishment | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return
    getEstablishment(id)
      .then(setEstablishment)
      .catch(() => setError('Fiche introuvable.'))
  }, [id])

  if (error) {
    return <p className="text-sm text-red-600">{error}</p>
  }

  if (!establishment) {
    return <p className="text-sm text-slate-500">Chargement…</p>
  }

  const e = establishment

  return (
    <div>
      <Link to="/recherche" className="text-sm text-sky-700 hover:underline">
        ← Retour à la recherche
      </Link>

      <div className="mt-3 flex items-start justify-between">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">
            {e.name ?? e.company?.legal_name ?? 'Établissement'}
          </h1>
          <p className="mt-0.5 font-mono text-sm text-slate-500">SIRET {e.siret}</p>
          {e.description && (
            <p className="mt-2 max-w-2xl text-sm text-slate-600">{e.description}</p>
          )}
        </div>
        <span
          className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
            e.status === 'active'
              ? 'bg-green-100 text-green-800'
              : 'bg-slate-200 text-slate-600'
          }`}
        >
          {e.status === 'active' ? 'En activité' : e.status === 'ceased' ? 'Fermé' : 'Inconnu'}
        </span>
      </div>

      <div className="mt-4 grid gap-4 md:grid-cols-2">
        <section className="rounded-xl border border-slate-200 bg-white p-4">
          <h2 className="text-sm font-semibold text-slate-900">Établissement</h2>
          <dl className="mt-2 space-y-1.5 text-sm">
            <Row label="Activité (NAF)" value={e.naf_code} />
            <Row label="Effectifs (tranche)" value={e.employee_range} />
            <Row label="Siège social" value={e.is_headquarters ? 'Oui' : 'Non'} />
            <Row
              label="Adresse"
              value={[e.address.line, e.address.postal_code, e.address.city]
                .filter(Boolean)
                .join(', ') || null}
            />
            <Row label="Département" value={e.address.department_code} />
            <Row
              label="Géolocalisation"
              value={
                e.coordinates
                  ? `${e.coordinates.latitude.toFixed(5)}, ${e.coordinates.longitude.toFixed(5)} (${e.geo_source})`
                  : 'Non géolocalisé'
              }
            />
          </dl>
        </section>

        <section className="rounded-xl border border-slate-200 bg-white p-4">
          <h2 className="text-sm font-semibold text-slate-900">Unité légale</h2>
          <dl className="mt-2 space-y-1.5 text-sm">
            <Row label="Dénomination" value={e.company?.legal_name} />
            <Row label="SIREN" value={e.company?.siren} mono />
            <Row label="Catégorie juridique" value={e.company?.legal_form} />
            <Row label="Créée le" value={e.company?.incorporated_at} />
          </dl>
        </section>

        <section className="rounded-xl border border-slate-200 bg-white p-4">
          <h2 className="text-sm font-semibold text-slate-900">Contact</h2>
          <dl className="mt-2 space-y-1.5 text-sm">
            <Row label="Téléphone" value={e.contact.phone} />
            <Row
              label="Site web"
              value={e.contact.website}
              href={e.contact.website ?? undefined}
            />
            <Row
              label="Email"
              value={e.contact.email}
              href={e.contact.email ? `mailto:${e.contact.email}` : undefined}
            />
            <Row label="Horaires" value={e.opening_hours?.raw ?? null} />
            <Row
              label="Formulaire de contact"
              value={e.contact_form_url ?? null}
              href={e.contact_form_url ?? undefined}
            />
            <Row
              label="CMS / technologies"
              value={
                e.technologies
                  ? [e.technologies.cms, ...(e.technologies.libs ?? [])]
                      .filter(Boolean)
                      .join(', ') || null
                  : null
              }
            />
            {Object.entries(e.social_links ?? {}).map(([network, url]) => (
              <Row
                key={network}
                label={network.charAt(0).toUpperCase() + network.slice(1)}
                value={url}
                href={url}
              />
            ))}
          </dl>
        </section>

        <section className="rounded-xl border border-slate-200 bg-white p-4">
          <h2 className="text-sm font-semibold text-slate-900">Scores</h2>
          <dl className="mt-2 space-y-1.5 text-sm">
            <Row
              label="Score commercial"
              value={e.commercial_score !== null ? `${e.commercial_score}/100` : 'À venir (phase 5)'}
            />
            <Row
              label="Indice de réputation"
              value={e.reputation_score !== null ? `${e.reputation_score}/100` : 'À venir (phase 4)'}
            />
            <Row
              label="Note"
              value={e.rating !== null ? `${e.rating}/5 (${e.reviews_count} avis, ${e.rating_source})` : 'Non renseignée'}
            />
          </dl>
        </section>

        <section className="rounded-xl border border-slate-200 bg-white p-4 md:col-span-2">
          <h2 className="text-sm font-semibold text-slate-900">Collecte des données</h2>
          <dl className="mt-2 space-y-1.5 text-sm">
            <Row
              label="Importé (SIRENE)"
              value={e.imported_at ? new Date(e.imported_at).toLocaleDateString('fr-FR') : null}
            />
            <Row
              label="Enrichi (OSM)"
              value={e.enriched_at ? new Date(e.enriched_at).toLocaleDateString('fr-FR') : null}
            />
            <Row
              label="Site crawlé"
              value={e.crawled_at ? new Date(e.crawled_at).toLocaleDateString('fr-FR') : null}
            />
          </dl>
          {e.coordinates && (
            <p className="mt-3 text-sm">
              <Link
                to={`/carte?lat=${e.coordinates.latitude}&lng=${e.coordinates.longitude}&zoom=18`}
                className="font-medium text-sky-700 hover:underline"
              >
                Voir sur la carte
              </Link>
              <a
                href={`https://www.openstreetmap.org/?mlat=${e.coordinates.latitude}&mlon=${e.coordinates.longitude}#map=19/${e.coordinates.latitude}/${e.coordinates.longitude}`}
                target="_blank"
                rel="noreferrer"
                className="ml-4 text-sky-700 hover:underline"
              >
                Ouvrir dans OpenStreetMap
              </a>
            </p>
          )}
        </section>
      </div>
    </div>
  )
}

function Row({
  label,
  value,
  href,
  mono,
}: {
  label: string
  value: string | null | undefined
  href?: string
  mono?: boolean
}) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-slate-500">{label}</dt>
      <dd className={`text-right ${mono ? 'font-mono' : ''}`}>
        {value ? (
          href ? (
            <a
              href={href}
              target="_blank"
              rel="noreferrer"
              className="text-sky-700 hover:underline"
            >
              {value}
            </a>
          ) : (
            value
          )
        ) : (
          <span className="text-slate-300">—</span>
        )}
      </dd>
    </div>
  )
}
