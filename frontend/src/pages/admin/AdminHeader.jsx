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
      {/* Título o ruta de sección institucional */}
      <div className="flex items-center gap-2 text-sm font-semibold tracking-wide select-none">
        <span className="text-zinc-400 font-medium">Consola ISP</span>
        <span className="text-zinc-600">/</span>
        <span className="text-zinc-400 font-medium">
          {currentTabTitle}
        </span>
      </div>

      {/* Acciones de la barra superior: Botón de alternar tema */}
      <div className="flex items-center gap-2.5">
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
