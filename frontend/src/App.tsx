import { BrowserRouter, Route, Routes } from 'react-router'
import { AuthProvider } from './features/auth/AuthContext'
import AppLayout from './layouts/AppLayout'
import DashboardPage from './pages/DashboardPage'
import EstablishmentPage from './pages/EstablishmentPage'
import LoginPage from './pages/LoginPage'
import SearchPage from './pages/SearchPage'
import TwoFactorPage from './pages/TwoFactorPage'

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/2fa" element={<TwoFactorPage />} />
          <Route element={<AppLayout />}>
            <Route index element={<DashboardPage />} />
            <Route path="/recherche" element={<SearchPage />} />
            <Route path="/entreprises/:id" element={<EstablishmentPage />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}
