import { api } from '../../lib/api'
import type { ManagedUser } from '../../lib/types'

/** Gestion des utilisateurs (EF-10.2) — permission users.manage. */
export async function listUsers(): Promise<ManagedUser[]> {
  const { data } = await api.get('/users')
  return data.data
}

export async function createUser(payload: {
  name: string
  email: string
  password: string
  role: string
}): Promise<ManagedUser> {
  const { data } = await api.post('/users', payload)
  return data.data
}

export async function updateUser(
  id: number,
  patch: { role?: string; disabled?: boolean },
): Promise<ManagedUser> {
  const { data } = await api.patch(`/users/${id}`, patch)
  return data.data
}
