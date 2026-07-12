/**
 * Révélation progressive d'un texte généré : chaque mot apparaît en fondu,
 * décalé dans le temps (~18 ms/mot, plafonné pour rester vif sur les longs
 * résumés). Le texte complet est présent dans le DOM dès le rendu — la
 * révélation est purement visuelle, donc lisible par les lecteurs d'écran.
 * `prefers-reduced-motion` neutralise l'animation (voir index.css).
 */
const STEP_MS = 18
const MAX_DELAY_MS = 1400

export default function RevealText({ text, className }: { text: string; className?: string }) {
  // Conserve les espaces pour préserver la ponctuation et les sauts de ligne.
  const tokens = text.split(/(\s+)/)

  return (
    <p className={className}>
      {tokens.map((token, index) =>
        token.trim() === '' ? (
          token
        ) : (
          <span
            key={index}
            className="fbde-word"
            style={{ animationDelay: `${Math.min(index * STEP_MS, MAX_DELAY_MS)}ms` }}
          >
            {token}
          </span>
        ),
      )}
    </p>
  )
}
