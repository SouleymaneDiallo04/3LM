import { expect, test, request as playwrightRequest } from '@playwright/test'
import { authenticator } from 'otplib'

const EMAIL = process.env.E2E_EMAIL ?? ''
const PASSWORD = process.env.E2E_PASSWORD ?? ''
// Origine seule : les chemins /api/v1/… restent absolus (Playwright résout
// les chemins absolus contre l'origine, pas contre le préfixe du baseURL).
const API = process.env.E2E_API_URL ?? 'http://localhost:8080'

/**
 * Parcours §12.1 : connexion avec double authentification TOTP (EF-10.1).
 * Prépare via l'API un compte de test frais avec 2FA confirmée (secret
 * TOTP connu), puis déroule le défi dans l'interface.
 */
test('connexion avec défi 2FA', async ({ page }) => {
  test.skip(EMAIL === '' || PASSWORD === '', 'E2E_EMAIL / E2E_PASSWORD requis')

  const api = await playwrightRequest.newContext({
    baseURL: API,
    extraHTTPHeaders: { Accept: 'application/json' },
  })

  // 1. L'administrateur crée un compte de test frais (email unique par run).
  const adminLogin = await api.post('/api/v1/auth/login', {
    data: { email: EMAIL, password: PASSWORD },
  })
  expect(adminLogin.ok(), `login admin: ${adminLogin.status()} ${await adminLogin.text()}`).toBeTruthy()
  const adminToken = (await adminLogin.json()).data.token as string

  const testEmail = `e2e-${Date.now()}@fbde.local`
  const testPassword = 'E2e!Parcours2026'
  const created = await api.post('/api/v1/users', {
    headers: { Authorization: `Bearer ${adminToken}` },
    data: { name: 'Compte E2E', email: testEmail, password: testPassword, role: 'commercial' },
  })
  expect(created.status()).toBe(201)

  // 2. Le compte active sa 2FA : secret TOTP récupéré puis confirmé.
  const userLogin = await api.post('/api/v1/auth/login', {
    data: { email: testEmail, password: testPassword },
  })
  const userToken = (await userLogin.json()).data.token as string

  const enabled = await api.post('/api/v1/auth/2fa/enable', {
    headers: { Authorization: `Bearer ${userToken}` },
  })
  const secret = (await enabled.json()).data.secret as string

  const confirmed = await api.post('/api/v1/auth/2fa/confirm', {
    headers: { Authorization: `Bearer ${userToken}` },
    data: { code: authenticator.generate(secret) },
  })
  expect(confirmed.ok()).toBeTruthy()

  // 3. Parcours interface : login → défi 2FA → tableau de bord.
  await page.goto('/login')
  await page.getByLabel('Adresse email').fill(testEmail)
  await page.getByLabel('Mot de passe').fill(testPassword)
  await page.getByRole('button', { name: 'Se connecter' }).click()

  // L'écran de défi réclame le code TOTP.
  await expect(
    page.getByRole('heading', { name: 'Vérification en deux étapes' }),
  ).toBeVisible()
  await page.getByLabel('Code de vérification').fill(authenticator.generate(secret))
  await page.getByRole('button', { name: 'Vérifier' }).click()

  await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible()

  await api.dispose()
})
