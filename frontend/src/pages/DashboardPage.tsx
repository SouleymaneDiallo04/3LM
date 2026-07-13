import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import {
  Bar,
  BarChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { useAuth } from '../features/auth/AuthContext'
import { getStatistics, type DashboardStatistics } from '../features/statistics/api'

/** Libellés humains des divisions NAF (codes à 2 chiffres) — l'utilisateur
 *  lit un métier, pas un code machine. Repli « NAF NN » si absent. */
const NAF_LABELS: Record<string, string> = {
  '01': 'Agriculture, élevage', '10': 'Industries alimentaires',
  '41': 'Construction de bâtiments', '43': 'Travaux du bâtiment',
  '45': 'Commerce et réparation auto', '46': 'Commerce de gros',
  '47': 'Commerce de détail', '49': 'Transports terrestres',
  '55': 'Hébergement', '56': 'Restauration', '62': 'Informatique et logiciel',
  '64': 'Activités financières', '68': 'Immobilier',
  '69': 'Juridique et comptable', '70': 'Conseil de gestion',
  '71': 'Architecture et ingénierie', '73': 'Publicité et études',
  '74': 'Activités spécialisées', '77': 'Location', '78': 'Emploi',
  '81': 'Services aux bâtiments', '82': 'Services administratifs',
  '85': 'Enseignement', '86': 'Santé humaine', '87': 'Hébergement médico-social',
  '88': 'Action sociale', '90': 'Arts et spectacle', '93': 'Sport et loisirs',
  '94': 'Vie associative', '95': 'Réparation', '96': 'Services personnels',
}

const SOURCE_LABELS: Record<string, string> = {
  sirene: 'Import SIRENE',
  ban: 'Géocodage BAN',
  osm: 'Enrichissement OSM',
  crawl: 'Crawl des sites',
  scoring: 'Recalcul des scores',
}

/**
 * Tableau de bord (EF-06.1, EF-06.2, EF-06.4) : volume, complétude et
 * répartitions des établissements actifs, avec hiérarchie de lecture.
 */
export default function DashboardPage() {
  const { user, hasPermission } = useAuth()
  const [stats, setStats] = useState<DashboardStatistics | null>(null)
  const [error, setError] = useState<string | null>(null)

  const canView = hasPermission('statistics.view')

  useEffect(() => {
    if (!canView) return
    getStatistics()
      .then(setStats)
      .catch(() => setError('Impossible de charger les statistiques.'))
  }, [canView])

  if (!canView) {
    return (
      <p className="text-sm text-slate-500">
        Bienvenue, {user?.name}. Votre rôle ne donne pas accès aux statistiques.
      </p>
    )
  }

  if (error) return <p className="text-sm text-red-600">{error}</p>
  if (!stats) return <DashboardSkeleton />

  const { kpis } = stats
  const fr = (n: number) => n.toLocaleString('fr-FR')
  const updatedAt = new Date(stats.generated_at).toLocaleTimeString('fr-FR', {
    hour: '2-digit',
    minute: '2-digit',
  })

  return (
    <div className="mx-auto max-w-6xl">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold tracking-tight text-slate-900">Tableau de bord</h1>
          <p className="mt-1 text-sm text-slate-500">
            Vue d'ensemble des établissements de la base.
          </p>
        </div>
        <span className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-500">
          <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
          Actualisé à {updatedAt}
        </span>
      </div>

      {/* Bandeau de synthèse : un chiffre héros + secondaires alignés, puis la
          complétude en jauges (bien plus lisible qu'un pourcentage nu). */}
      <section className="mt-6 grid gap-4 lg:grid-cols-3">
        <div className="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
          <div>
            <p className="text-sm text-slate-500">Établissements actifs</p>
            <p className="mt-1 font-mono text-4xl font-semibold tabular-nums tracking-tight text-slate-900">
              {fr(kpis.active_establishments)}
            </p>
            <p className="mt-1.5 text-xs text-slate-500">
              sur {fr(kpis.total_establishments)} au total · {kpis.geocoding_rate}% géolocalisés
            </p>
          </div>
          <dl className="mt-6 grid grid-cols-2 gap-4 border-t border-slate-100 pt-4 sm:grid-cols-3">
            <InlineStat label="Total de la base" value={fr(kpis.total_establishments)} />
            <InlineStat label="Nouveaux contacts (30 j)" value={fr(kpis.new_contacts_30_days)} />
            <InlineStat label="Fiches enrichies" value={fr(kpis.enriched)} />
          </dl>
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 className="text-sm font-semibold text-slate-900">Complétude des données</h2>
          <div className="mt-3.5 space-y-3">
            <Meter label="Géolocalisation" pct={kpis.geocoding_rate} />
            <Meter label="Site web" pct={kpis.website_rate} />
            <Meter label="Téléphone" pct={kpis.phone_rate} />
            <Meter label="E-mail" pct={kpis.email_rate} />
          </div>
          <p className="mt-3.5 text-xs text-slate-400">Enrichissement OSM / web en cours.</p>
        </div>
      </section>

      <section className="mt-4 grid gap-4 lg:grid-cols-2">
        <ChartCard title="Communes les plus représentées">
          <HorizontalBars
            data={stats.top_cities.slice(0, 8).map((c) => ({ label: titleCase(c.city), value: c.count }))}
          />
        </ChartCard>
        <ChartCard title="Secteurs d'activité (NAF)">
          <HorizontalBars
            data={stats.by_naf_division
              .slice(0, 8)
              .map((d) => ({ label: NAF_LABELS[d.code] ?? `NAF ${d.code}`, value: d.count }))}
          />
        </ChartCard>
      </section>

      <section className="mt-4 grid gap-4 lg:grid-cols-3">
        <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
          <h2 className="text-sm font-semibold text-slate-900">Activité récente</h2>
          <ul className="mt-3 divide-y divide-slate-100 text-sm">
            {stats.recent_imports.slice(0, 6).map((run) => (
              <li key={run.id} className="flex items-center gap-3 py-2">
                <StatusDot status={run.status} />
                <span className="min-w-0 flex-1 truncate text-slate-700">
                  {SOURCE_LABELS[run.source] ?? run.source}
                </span>
                <span className="shrink-0 font-mono text-xs tabular-nums text-slate-400">
                  {run.started_at
                    ? new Date(run.started_at).toLocaleDateString('fr-FR', {
                        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
                      })
                    : '—'}
                </span>
              </li>
            ))}
            {stats.recent_imports.length === 0 && (
              <li className="py-3 text-slate-500">Aucun import pour le moment.</li>
            )}
          </ul>
        </section>

        <section className="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <div>
            <h2 className="text-sm font-semibold text-slate-900">Explorer la base</h2>
            <p className="mt-1.5 text-sm text-slate-500">
              Visualisez les prospects sur la carte ou lancez une recherche ciblée.
            </p>
          </div>
          <div className="mt-4 space-y-2">
            <QuickLink to="/carte" label="Cartographie interactive" />
            <QuickLink to="/recherche" label="Recherche multicritères" />
          </div>
        </section>
      </section>
    </div>
  )
}

function InlineStat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className="mt-1 font-mono text-xl font-semibold tabular-nums text-slate-900">{value}</dd>
    </div>
  )
}

function Meter({ label, pct }: { label: string; pct: number }) {
  return (
    <div>
      <div className="flex items-center justify-between text-xs">
        <span className="text-slate-600">{label}</span>
        <span className="font-mono tabular-nums text-slate-900">{pct}%</span>
      </div>
      <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
        <div
          className="h-full rounded-full bg-sky-600 transition-[width] duration-500 ease-out"
          style={{ width: `${Math.min(Math.max(pct, 0), 100)}%` }}
        />
      </div>
    </div>
  )
}

function StatusDot({ status }: { status: string }) {
  const color =
    status === 'completed' ? 'bg-emerald-500' : status === 'failed' ? 'bg-red-500' : 'bg-amber-500'
  const title = status === 'completed' ? 'terminé' : status === 'failed' ? 'échec' : status
  return <span className={`h-2 w-2 shrink-0 rounded-full ${color}`} title={title} />
}

function QuickLink({ to, label }: { to: string; label: string }) {
  return (
    <Link
      to={to}
      className="group flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-medium text-slate-700 transition-colors duration-150 hover:border-sky-200 hover:bg-sky-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
    >
      {label}
      <svg
        className="h-4 w-4 text-slate-400 transition-transform duration-150 group-hover:translate-x-0.5 group-hover:text-sky-700"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
      >
        <path d="M5 12h14M13 6l6 6-6 6" />
      </svg>
    </Link>
  )
}

function ChartCard({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
      <div className="mt-3 h-64">{children}</div>
    </section>
  )
}

function HorizontalBars({ data }: { data: { label: string; value: number }[] }) {
  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={data} layout="vertical" margin={{ left: 8, right: 44 }}>
        {/* Axe masqué mais domaine explicite : sinon Recharts n'a pas d'échelle
            et les barres ne se dessinent pas. Les valeurs sont sur les barres. */}
        <XAxis type="number" hide domain={[0, 'dataMax']} />
        <YAxis
          type="category"
          dataKey="label"
          width={150}
          tick={{ fontSize: 12, fill: '#475569' }}
          axisLine={false}
          tickLine={false}
        />
        <Tooltip
          cursor={{ fill: '#f8fafc' }}
          contentStyle={{
            borderRadius: 8,
            border: '1px solid #e2e8f0',
            fontSize: 12,
            boxShadow: '0 4px 12px rgb(15 23 42 / 0.08)',
          }}
          formatter={(value) => [Number(value).toLocaleString('fr-FR'), 'Établissements']}
        />
        <Bar
          dataKey="value"
          fill="#0284c7"
          radius={[3, 3, 3, 3]}
          barSize={13}
          isAnimationActive={false}
          label={{
            position: 'right',
            fontSize: 11,
            fill: '#94a3b8',
            formatter: (v: unknown) => Number(v).toLocaleString('fr-FR'),
          }}
        />
      </BarChart>
    </ResponsiveContainer>
  )
}

function titleCase(s: string): string {
  return s
    .toLocaleLowerCase('fr-FR')
    .replace(/(^|[\s-])([a-zàâäéèêëîïôöùûüç])/g, (_, sep, c) => sep + c.toLocaleUpperCase('fr-FR'))
}

function DashboardSkeleton() {
  return (
    <div className="mx-auto max-w-6xl">
      <div className="h-6 w-40 rounded fbde-skeleton" />
      <div className="mt-6 grid gap-4 lg:grid-cols-3">
        <div className="h-32 rounded-xl fbde-skeleton lg:col-span-2" />
        <div className="h-32 rounded-xl fbde-skeleton" />
      </div>
      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <div className="h-72 rounded-xl fbde-skeleton" />
        <div className="h-72 rounded-xl fbde-skeleton" />
      </div>
    </div>
  )
}
