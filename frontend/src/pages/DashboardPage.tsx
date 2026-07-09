import { useEffect, useState } from 'react'
import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { useAuth } from '../features/auth/AuthContext'
import { getStatistics, type DashboardStatistics } from '../features/statistics/api'

/** Teinte unique validée (série unique — pas de légende nécessaire). */
const BAR_COLOR = '#2563eb'

/**
 * Tableau de bord (EF-06.1, EF-06.2, EF-06.4) : KPI, complétude,
 * répartitions sur les établissements actifs.
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
  if (!stats) return <p className="text-sm text-slate-500">Chargement…</p>

  const { kpis } = stats

  const tiles: [string, string][] = [
    ['Établissements actifs', kpis.active_establishments.toLocaleString('fr-FR')],
    ['Total (actifs + fermés)', kpis.total_establishments.toLocaleString('fr-FR')],
    ['Nouveaux (30 jours)', kpis.new_last_30_days.toLocaleString('fr-FR')],
    ['Géolocalisés', `${kpis.geocoding_rate} %`],
    ['Avec email', `${kpis.email_rate} %`],
    ['Avec téléphone', `${kpis.phone_rate} %`],
    ['Avec site web', `${kpis.website_rate} %`],
    ['Fiches enrichies', kpis.enriched.toLocaleString('fr-FR')],
  ]

  return (
    <div>
      <h1 className="text-xl font-semibold text-slate-900">Tableau de bord</h1>
      <p className="mt-1 text-sm text-slate-500">
        Établissements actifs de la base — actualisé toutes les 5 minutes.
      </p>

      <div className="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        {tiles.map(([label, value]) => (
          <div key={label} className="rounded-xl border border-slate-200 bg-white p-4">
            <p className="text-xs text-slate-500">{label}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{value}</p>
          </div>
        ))}
      </div>

      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <ChartCard title="Top 10 des villes (actifs)">
          <HorizontalBars
            data={stats.top_cities.slice(0, 10).map((c) => ({ label: c.city, value: c.count }))}
          />
        </ChartCard>
        <ChartCard title="Répartition par division NAF (top 10)">
          <HorizontalBars
            data={stats.by_naf_division
              .slice(0, 10)
              .map((d) => ({ label: `NAF ${d.code}`, value: d.count }))}
          />
        </ChartCard>
      </div>
    </div>
  )
}

function ChartCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4">
      <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
      <div className="mt-2 h-72">{children}</div>
    </section>
  )
}

function HorizontalBars({ data }: { data: { label: string; value: number }[] }) {
  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={data} layout="vertical" margin={{ left: 8, right: 40 }}>
        <CartesianGrid horizontal={false} stroke="#e2e8f0" />
        <XAxis type="number" tick={{ fontSize: 11, fill: '#64748b' }} axisLine={false} tickLine={false} />
        <YAxis
          type="category"
          dataKey="label"
          width={130}
          tick={{ fontSize: 11, fill: '#334155' }}
          axisLine={false}
          tickLine={false}
        />
        <Tooltip
          cursor={{ fill: '#f1f5f9' }}
          formatter={(value) => [Number(value).toLocaleString('fr-FR'), 'Établissements']}
        />
        <Bar
          dataKey="value"
          fill={BAR_COLOR}
          radius={[0, 4, 4, 0]}
          barSize={14}
          label={{
            position: 'right',
            fontSize: 11,
            fill: '#64748b',
            formatter: (v: unknown) => Number(v).toLocaleString('fr-FR'),
          }}
        />
      </BarChart>
    </ResponsiveContainer>
  )
}
