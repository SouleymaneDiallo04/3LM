import { useEffect, useRef } from 'react'

/**
 * Toile animée du panneau de connexion : un champ de points qui dérivent et
 * se relient quand ils s'approchent — évoque les établissements géolocalisés
 * et le maillage de la base. Purement décoratif (aria-hidden), en canvas pour
 * rester fluide, et figé si l'utilisateur a demandé à réduire les animations.
 */
export default function LoginBackdrop() {
  const ref = useRef<HTMLCanvasElement>(null)

  useEffect(() => {
    const canvas = ref.current
    if (!canvas) return
    const ctx = canvas.getContext('2d')
    if (!ctx) return

    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    const dpr = Math.min(window.devicePixelRatio || 1, 2)
    const COUNT = 52
    const LINK = 140

    type Node = { x: number; y: number; vx: number; vy: number; r: number }
    let nodes: Node[] = []
    let w = 0
    let h = 0
    let raf = 0

    const seed = () => {
      nodes = Array.from({ length: COUNT }, () => ({
        x: Math.random() * w,
        y: Math.random() * h,
        vx: (Math.random() - 0.5) * 0.16,
        vy: (Math.random() - 0.5) * 0.16,
        r: Math.random() < 0.15 ? 2.4 : 1.5, // quelques points « pôles » un peu plus gros
      }))
    }

    const resize = () => {
      const rect = canvas.getBoundingClientRect()
      w = rect.width
      h = rect.height
      canvas.width = w * dpr
      canvas.height = h * dpr
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
      seed()
      if (reduce) draw()
    }

    const draw = () => {
      ctx.clearRect(0, 0, w, h)
      for (let i = 0; i < nodes.length; i++) {
        for (let j = i + 1; j < nodes.length; j++) {
          const dx = nodes[i].x - nodes[j].x
          const dy = nodes[i].y - nodes[j].y
          const dist = Math.hypot(dx, dy)
          if (dist < LINK) {
            ctx.strokeStyle = `rgba(56,189,248,${0.12 * (1 - dist / LINK)})`
            ctx.lineWidth = 1
            ctx.beginPath()
            ctx.moveTo(nodes[i].x, nodes[i].y)
            ctx.lineTo(nodes[j].x, nodes[j].y)
            ctx.stroke()
          }
        }
      }
      for (const n of nodes) {
        ctx.fillStyle = n.r > 2 ? 'rgba(125,211,252,0.85)' : 'rgba(148,197,236,0.5)'
        ctx.beginPath()
        ctx.arc(n.x, n.y, n.r, 0, Math.PI * 2)
        ctx.fill()
      }
    }

    const tick = () => {
      for (const n of nodes) {
        n.x += n.vx
        n.y += n.vy
        if (n.x <= 0 || n.x >= w) n.vx *= -1
        if (n.y <= 0 || n.y >= h) n.vy *= -1
      }
      draw()
      raf = requestAnimationFrame(tick)
    }

    resize()
    const ro = new ResizeObserver(resize)
    ro.observe(canvas)
    if (!reduce) raf = requestAnimationFrame(tick)

    return () => {
      cancelAnimationFrame(raf)
      ro.disconnect()
    }
  }, [])

  return <canvas ref={ref} aria-hidden="true" className="absolute inset-0 z-0 h-full w-full" />
}
