import { expect, test } from '@playwright/test'

const EMAIL = process.env.E2E_EMAIL ?? ''
const PASSWORD = process.env.E2E_PASSWORD ?? ''

/**
 * Parcours §12.1 : connexion simple → recherche multicritères → export CSV
 * suivi jusqu'au statut « completed ».
 */
test('connexion, recherche, export CSV', async ({ page }) => {
  test.skip(EMAIL === '' || PASSWORD === '', 'E2E_EMAIL / E2E_PASSWORD requis')

  await page.goto('/login')
  await page.getByLabel('Adresse email').fill(EMAIL)
  await page.getByLabel('Mot de passe').fill(PASSWORD)
  await page.getByRole('button', { name: 'Se connecter' }).click()

  // Tableau de bord après connexion (compte sans 2FA).
  await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible()

  // Recherche multicritères.
  await page.getByRole('link', { name: 'Recherche' }).click()
  await page.getByPlaceholder('boulangerie, garage…').fill('boulangerie')
  await page.getByPlaceholder('33, 75, 2A…').fill('33')
  await page.getByRole('button', { name: 'Rechercher' }).click()
  await expect(page.getByText(/Résultats — page de \d+/)).toBeVisible()

  // Export CSV : suivi du job jusqu'à complétion (EF-07.4).
  await page.getByRole('button', { name: 'CSV', exact: true }).click()
  await expect(page.getByText(/Export #\d+ : completed/)).toBeVisible({ timeout: 120_000 })
  await expect(page.getByRole('button', { name: /Télécharger/ })).toBeVisible()
})
