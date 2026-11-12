// Polyfill para crypto.randomUUID en entornos HTTP no seguros (RFC 4122 v4)
if (typeof window !== 'undefined' && (!window.crypto || !window.crypto.randomUUID)) {
  if (!window.crypto) window.crypto = {};
  window.crypto.randomUUID = function () {
    return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
      (+c ^ (Math.random() * 16 >> (+c / 4))).toString(16)
    );
  };
}

import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import { AuthProvider } from './context/AuthContext.jsx';
import { ThemeProvider } from './context/ThemeContext.jsx';
import { LanguageProvider } from './context/LanguageContext.jsx';
import './index.css';

class ErrorBoundary extends React.Component {
  state = { hasError: false, error: null };
  static getDerivedStateFromError(error) { return { hasError: true, error }; }
  componentDidCatch(error, info) { console.error("Error capturado:", error, info); }
  render() {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen bg-[#141414] text-white flex flex-col items-center justify-center p-6 text-center">
          <h1 className="text-2xl font-bold text-red-500 mb-2">Error de renderizado en FlixHN</h1>
          <p className="text-zinc-400 max-w-md text-sm mb-4">{this.state.error?.message}</p>
          <button onClick={() => window.location.reload()} className="px-4 py-2 bg-[#E50914] rounded font-semibold text-sm cursor-pointer hover:bg-[#F40612] transition-colors">
            Recargar aplicación
          </button>
        </div>
      );
    }
    return this.props.children;
  }
}

createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <ErrorBoundary>
      <ThemeProvider>
        <LanguageProvider>
          <AuthProvider>
            <ErrorBoundary>
              <App />
            </ErrorBoundary>
          </AuthProvider>
        </LanguageProvider>
      </ThemeProvider>
    </ErrorBoundary>
  </React.StrictMode>
);

