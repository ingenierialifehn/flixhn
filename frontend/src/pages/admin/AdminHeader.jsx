import React from 'react';
import { useTheme } from '../../context/ThemeContext';
import { Sun, Moon } from 'lucide-react';

const AdminHeader = ({
  currentTabTitle = 'Dashboard',
  onBackToBrowse,
}) => {
  const { theme, toggleTheme } = useTheme();

  return (
    <div className="w-full flex items-center justify-between">
      {/* Título o ruta de sección con Logotipo FLIX HN */}
      <div className="flex items-center gap-3 sm:gap-4 text-sm font-semibold tracking-wide select-none">
        {/* Logotipo FLIX HN que redirige explícitamente a /browse (Puntos 6 & 10) */}
        <div
          onClick={() => {
            if (typeof onBackToBrowse === 'function') {
              onBackToBrowse();
            } else {
              window.history.pushState({}, '', '/browse');
              window.dispatchEvent(new PopStateEvent('popstate'));
            }
          }}
          className="flex items-center gap-1.5 cursor-pointer select-none group"
          title="Ir al Catálogo de Medios (/browse)"
        >
          <span className="font-display text-xl tracking-wider text-[#E50914] font-black group-hover:scale-105 transition-transform">
            FLIX
          </span>
          <span className="bg-[#E50914] text-white text-[10px] font-black px-1.5 py-0.5 rounded tracking-widest shadow-md">
            HN
          </span>
        </div>

        <span className="text-zinc-600 hidden sm:inline">|</span>

        <div className="hidden sm:flex items-center gap-2">
          <span className="text-zinc-400 font-medium">Consola ISP</span>
          <span className="text-zinc-600">/</span>
          <span className="text-white font-bold">
            {currentTabTitle}
          </span>
        </div>
      </div>

      {/* Acciones de la barra superior: Botón Ver Catálogo y Tema */}
      <div className="flex items-center gap-2.5">
        <button
          onClick={() => {
            if (typeof onBackToBrowse === 'function') {
              onBackToBrowse();
            } else {
              window.history.pushState({}, '', '/browse');
              window.dispatchEvent(new PopStateEvent('popstate'));
            }
          }}
          className="hidden md:flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-zinc-900 hover:bg-[#E50914] text-zinc-300 hover:text-white border border-zinc-800 hover:border-[#E50914] rounded-lg transition-colors cursor-pointer select-none"
          title="Ir a navegar catálogo (/browse)"
          type="button"
        >
          <span>Ir a FlixHN</span>
        </button>

        {/* Botón de alternar tema (Sol / Luna) */}
        <button
          onClick={toggleTheme}
          className="p-2 rounded-full text-zinc-400 hover:text-white bg-zinc-900 border border-zinc-800 hover:border-zinc-700 transition-colors cursor-pointer"
          title={theme === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
          aria-label={theme === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
          type="button"
        >
          {theme === 'dark' ? (
            <Sun className="w-4 h-4 text-yellow-400" />
          ) : (
            <Moon className="w-4 h-4 text-zinc-300" />
          )}
        </button>
      </div>
    </div>
  );
};

export default AdminHeader;
