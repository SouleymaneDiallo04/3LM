import { defineConfig } from '@playwright/test'

/**
 * Parcours bout en bout (§12.1) : exigent la pile complète démarrée
 * (docker compose up + vite dev). Identifiants via variables d'environnement :
 *   E2E_EMAIL / E2E_PASSWORD — compte administrateur de test.
 * Lancement : npm run e2e
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 180_000, // latence dev Windows (bind mount) : généreux à dessein
  expect: { timeout: 45_000 },
  retries: 0,
  workers: 1, // parcours séquentiels — un seul compte de test
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
    actionTimeout: 45_000,
    screenshot: 'only-on-failure',
  },
})
