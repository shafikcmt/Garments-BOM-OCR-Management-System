import * as React from 'react'
import { CheckCircle2, AlertTriangle, Info, X } from 'lucide-react'
import { cn } from '@/lib/utils'

type ToastVariant = 'success' | 'error' | 'info'
interface ToastItem {
  id: number
  title: string
  description?: string
  variant: ToastVariant
}

interface ToastContextValue {
  toast: (t: Omit<ToastItem, 'id'>) => void
}

const ToastContext = React.createContext<ToastContextValue | null>(null)

// Minimal, dependency-free toaster (sonner-style). Enough for a prototype.
export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [items, setItems] = React.useState<ToastItem[]>([])

  const toast = React.useCallback((t: Omit<ToastItem, 'id'>) => {
    const id = Date.now() + Math.random()
    setItems((prev) => [...prev, { ...t, id }])
    window.setTimeout(() => {
      setItems((prev) => prev.filter((i) => i.id !== id))
    }, 4000)
  }, [])

  const dismiss = (id: number) =>
    setItems((prev) => prev.filter((i) => i.id !== id))

  return (
    <ToastContext.Provider value={{ toast }}>
      {children}
      <div className="pointer-events-none fixed bottom-4 right-4 z-[100] flex w-full max-w-sm flex-col gap-2">
        {items.map((i) => (
          <div
            key={i.id}
            role="status"
            className={cn(
              'pointer-events-auto flex items-start gap-3 rounded-lg border bg-card p-4 shadow-lg animate-in slide-in-from-bottom-2',
            )}
          >
            <span className="mt-0.5">
              {i.variant === 'success' && (
                <CheckCircle2 className="h-5 w-5 text-emerald-500" />
              )}
              {i.variant === 'error' && (
                <AlertTriangle className="h-5 w-5 text-rose-500" />
              )}
              {i.variant === 'info' && <Info className="h-5 w-5 text-blue-500" />}
            </span>
            <div className="flex-1">
              <div className="text-sm font-semibold">{i.title}</div>
              {i.description && (
                <div className="mt-0.5 text-sm text-muted-foreground">
                  {i.description}
                </div>
              )}
            </div>
            <button
              onClick={() => dismiss(i.id)}
              className="text-muted-foreground hover:text-foreground"
              aria-label="Dismiss"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast() {
  const ctx = React.useContext(ToastContext)
  if (!ctx) throw new Error('useToast must be used within <ToastProvider>')
  return ctx
}
