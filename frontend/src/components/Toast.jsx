import React, { useState, useEffect, useCallback } from 'react';
import { AlertCircle, CheckCircle, Info, X } from 'lucide-react';

/**
 * Función auxiliar para disparar un toast elegante desde cualquier parte del código
 * (incluyendo interceptores Axios y llamadas asíncronas).
 * NO utiliza alert() nativo en ningún caso.
 */
export const showFlixHnToast = (message, type = 'error', duration = 4000) => {
  if (typeof window === 'undefined') return;

  // 1. Notificar a componentes React montados
  window.dispatchEvent(
    new CustomEvent('flixhn:toast', {
      detail: {
        id: Date.now() + Math.random().toString(36).substring(2, 6),
        message,
        type,
        duration,
      },
    })
  );

  // 2. Asegurar renderizado visual incluso si ToastContainer no está montado en la vista activa
  try {
    let container = document.getElementById('flixhn-dom-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'flixhn-dom-toast-container';
      container.className = 'fixed top-5 right-5 z-[99999] flex flex-col gap-2 pointer-events-none select-none max-w-sm w-full';
      document.body.appendChild(container);
    }

    const toastEl = document.createElement('div');
    toastEl.className = 'pointer-events-auto flex items-center justify-between gap-3 px-4 py-3 bg-[#181818]/95 backdrop-blur-md text-white rounded-lg shadow-2xl border transition-all duration-300';
    toastEl.style.borderColor = type === 'error' ? 'rgba(229, 9, 20, 0.7)' : 'rgba(34, 197, 94, 0.7)';
    toastEl.style.boxShadow = type === 'error' ? '0 10px 25px -5px rgba(229, 9, 20, 0.4)' : '0 10px 25px -5px rgba(0, 0, 0, 0.5)';

    const iconSvg = type === 'error'
      ? `<svg class="w-5 h-5 text-[#E50914] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"></circle><line x1="12" y1="8" x2="12" y2="12" stroke-width="2"></line><line x1="12" y1="16" x2="12.01" y2="16" stroke-width="2"></line></svg>`
      : `<svg class="w-5 h-5 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>`;

    toastEl.innerHTML = `
      <div class="flex items-center gap-2.5 min-w-0">
        ${iconSvg}
        <p class="text-xs md:text-sm font-semibold text-zinc-100 leading-snug">${message}</p>
      </div>
      <button type="button" class="p-1 text-zinc-400 hover:text-white rounded transition-colors cursor-pointer shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
      </button>
    `;

    const closeBtn = toastEl.querySelector('button');
    const dismiss = () => {
      toastEl.style.opacity = '0';
      toastEl.style.transform = 'translateX(100%)';
      setTimeout(() => {
        if (toastEl.parentElement) toastEl.parentElement.removeChild(toastEl);
      }, 300);
    };

    closeBtn?.addEventListener('click', dismiss);
    container.appendChild(toastEl);

    setTimeout(dismiss, duration);
  } catch (e) {
    // Silencioso
  }
};

export default function ToastContainer() {
  const [toasts, setToasts] = useState([]);

  const removeToast = useCallback((id) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  useEffect(() => {
    const handleToastEvent = (e) => {
      const { id, message, type = 'error', duration = 4000 } = e.detail || {};
      if (!message) return;

      const newToast = { id, message, type, duration };
      setToasts((prev) => [...prev.slice(-4), newToast]);

      setTimeout(() => {
        removeToast(id);
      }, duration);
    };

    window.addEventListener('flixhn:toast', handleToastEvent);
    return () => {
      window.removeEventListener('flixhn:toast', handleToastEvent);
    };
  }, [removeToast]);

  if (toasts.length === 0) return null;

  return (
    <div className="fixed top-5 right-5 z-[9999] flex flex-col gap-2 pointer-events-none select-none max-w-sm w-full">
      {toasts.map((toast) => {
        const isError = toast.type === 'error';
        const isSuccess = toast.type === 'success';

        return (
          <div
            key={toast.id}
            role="alert"
            className="pointer-events-auto flex items-center justify-between gap-3 px-4 py-3 bg-[#181818]/95 backdrop-blur-md text-white rounded-lg shadow-2xl border border-red-600/40 transition-all duration-300"
            style={{
              borderColor: isError ? 'rgba(229, 9, 20, 0.7)' : isSuccess ? 'rgba(34, 197, 94, 0.7)' : 'rgba(161, 161, 170, 0.4)',
              boxShadow: isError ? '0 10px 25px -5px rgba(229, 9, 20, 0.4)' : '0 10px 25px -5px rgba(0, 0, 0, 0.5)',
            }}
          >
            <div className="flex items-center gap-2.5 min-w-0">
              {isError ? (
                <AlertCircle className="w-5 h-5 text-[#E50914] shrink-0 animate-pulse" />
              ) : isSuccess ? (
                <CheckCircle className="w-5 h-5 text-green-500 shrink-0" />
              ) : (
                <Info className="w-5 h-5 text-zinc-400 shrink-0" />
              )}
              <p className="text-xs md:text-sm font-semibold text-zinc-100 leading-snug">
                {toast.message}
              </p>
            </div>

            <button
              type="button"
              onClick={() => removeToast(toast.id)}
              className="p-1 text-zinc-400 hover:text-white rounded transition-colors cursor-pointer shrink-0"
              title="Cerrar notificación"
            >
              <X className="w-4 h-4" />
            </button>
          </div>
        );
      })}
    </div>
  );
}
