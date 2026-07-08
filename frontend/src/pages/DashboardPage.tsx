import { useAuth } from '../features/auth/AuthContext'

/**
 * Tableau de bord — placeholder de la phase 1.
 * Les KPI et graphiques (EF-06) sont livrés en phase 3.
 */
export default function DashboardPage() {
  const { user } = useAuth()

  return (
    <div>
      <h1 className="text-xl font-semibold text-slate-900">Tableau de bord</h1>
      <p className="mt-1 text-sm text-slate-500">
        Bienvenue, {user?.name}. Rôle : {user?.roles.join(', ')}
      </p>

      <div className="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
        <p className="text-sm text-slate-500">
          Les indicateurs (entreprises importées, taux d'enrichissement,
          répartitions) seront disponibles à partir de la phase 3 — après le
          premier import SIRENE (phase 2).
        </p>
      </div>
    </div>
  )
}
