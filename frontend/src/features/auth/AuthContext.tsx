import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { api, getToken, setToken } from '../../lib/api'

/** Utilisateur authentifié tel que renvoyé par l'API (§7). */
export interface AuthUser {
  id: number
  name: string
  email: string
  roles: string[]
  permissions: string[]
}

/** Résultat d'une tentative de connexion. */
export type LoginResult =
  | { status: 'authenticated' }
  | { status: 'two_factor_required' }

interface AuthContextValue {
  user: AuthUser | null
  /** Chargement initial (restauration de session depuis le token stocké). */
  loading: boolean
  login: (email: string, password: string) => Promise<LoginResult>
  /** Échange le token de défi + code TOTP/secours contre un token complet. */
  verifyTwoFactor: (code: string) => Promise<void>
  logout: () => Promise<void>
  hasPermission: (permission: string) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [loading, setLoading] = useState(true)
  // Token de défi 2FA, conservé en mémoire uniquement (jamais persisté).
  const [challengeToken, setChallengeToken] = useState<string | null>(null)

  // Restaure la session au chargement si un token est présent.
  useEffect(() => {
    if (!getToken()) {
      setLoading(false)
      return
    }
    api
      .get('/auth/me')
      .then(({ data }) => setUser(data.data.user))
      .catch(() => setToken(null))
      .finally(() => setLoading(false))
  }, [])

  const login = useCallback(
    async (email: string, password: string): Promise<LoginResult> => {
      const { data } = await api.post('/auth/login', { email, password })

      if (data.data.two_factor_required) {
        setChallengeToken(data.data.challenge_token)
        return { status: 'two_factor_required' }
      }

      setToken(data.data.token)
      setUser(data.data.user)
      return { status: 'authenticated' }
    },
    [],
  )

  const verifyTwoFactor = useCallback(
    async (code: string): Promise<void> => {
      const { data } = await api.post(
        '/auth/2fa',
        { code },
        { headers: { Authorization: `Bearer ${challengeToken}` } },
      )
      setChallengeToken(null)
      setToken(data.data.token)
      setUser(data.data.user)
    },
    [challengeToken],
  )

  const logout = useCallback(async (): Promise<void> => {
    try {
      await api.post('/auth/logout')
    } finally {
      setToken(null)
      setUser(null)
    }
  }, [])

  const hasPermission = useCallback(
    (permission: string) => user?.permissions.includes(permission) ?? false,
    [user],
  )

  const value = useMemo(
    () => ({ user, loading, login, verifyTwoFactor, logout, hasPermission }),
    [user, loading, login, verifyTwoFactor, logout, hasPermission],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth doit être utilisé sous <AuthProvider>')
  }
  return context
}
