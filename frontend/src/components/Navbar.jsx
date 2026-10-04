import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useAuth } from '../context/AuthContext';
import { useTheme } from '../context/ThemeContext';
import { useLanguage } from '../context/LanguageContext';
import {
  LogOut,
  Users,
  Wifi,
  ChevronDown,
  Settings,
  Sun,
  Moon,
  Search,
  X,
  Loader2,
  Globe,
} from 'lucide-react';
import api from '../api/axios';

const Navbar = ({
  currentTab,
  setCurrentTab,
  onPlay,
  onOpenModal,
  onSearchResults,
  onSearchQueryChange,
}) => {
  const { user, activeProfile, clientIp, isOnNet, logout, clearProfile } = useAuth();
  const { theme, toggleTheme } = useTheme();
  const { lang, toggleLang, t } = useLanguage();

  const [isScrolled, setIsScrolled] = useState(false);
  const [showDropdown, setShowDropdown] = useState(false);

  // Estados de búsqueda reactiva en tiempo real
  const [searchOpen, setSearchOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [isSearching, setIsSearching] = useState(false);
  const searchInputRef = useRef(null);
  const searchContainerRef = useRef(null);

  // Referencia de guardia estricta para evitar bucles infinitos de renderizado
  const prevQueryRef = useRef('');

  // Escuchar clics fuera para colapsar input si está vacío
  useEffect(() => {
    const handleClickOutside = (e) => {
      if (searchContainerRef.current && !searchContainerRef.current.contains(e.target)) {
        if (!searchQuery.trim()) {
          setSearchOpen(false);
        }
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [searchQuery]);

  // Filtrado reactivo en tiempo real: debounce con guarda estricta contra renders infinitos
  useEffect(() => {
    const trimmed = searchQuery.trim();

    // Condición de guarda: evitar llamadas redundantes si la consulta no cambió
    if (trimmed === prevQueryRef.current) {
      return;
    }

    const timer = setTimeout(async () => {
      if (trimmed === prevQueryRef.current) {
        return;
      }
      prevQueryRef.current = trimmed;

      if (typeof onSearchQueryChange === 'function') {
        onSearchQueryChange(trimmed);
      }

      if (!trimmed) {
        setIsSearching(false);
        if (typeof onSearchResults === 'function') {
          onSearchResults([], '');
        }
        return;
      }

      setIsSearching(true);
      try {
        const res = await api.get(`/titles?q=${encodeURIComponent(trimmed)}`);
        const results = res.data?.results || res.data?.data || [];
        if (typeof onSearchResults === 'function') {
          onSearchResults(results, trimmed);
        }
      } catch (err) {
        console.warn('Error en búsqueda reactiva de títulos:', err);
        if (typeof onSearchResults === 'function') {
          onSearchResults([], trimmed);
        }
      } finally {
        setIsSearching(false);
      }
    }, 250);

    return () => clearTimeout(timer);
  }, [searchQuery, onSearchQueryChange, onSearchResults]);

  const handleToggleSearch = useCallback(() => {
    setSearchOpen((prev) => {
      const next = !prev;
      if (next) {
        setTimeout(() => searchInputRef.current?.focus(), 80);
      }
      return next;
    });
  }, []);

  const handleClearSearch = useCallback(() => {
    setSearchQuery('');
    setSearchOpen(false);
    if (prevQueryRef.current !== '') {
      prevQueryRef.current = '';
      if (typeof onSearchQueryChange === 'function') {
        onSearchQueryChange('');
      }
      if (typeof onSearchResults === 'function') {
        onSearchResults([], '');
      }
    }
  }, [onSearchQueryChange, onSearchResults]);

  const handleKeyDown = useCallback((e) => {
    if (e.key === 'Escape') {
      handleClearSearch();
      searchInputRef.current?.blur();
    }
  }, [handleClearSearch]);

  useEffect(() => {
    const handleScroll = () => {
      if (window.scrollY > 40) {
        setIsScrolled(true);
      } else {
        setIsScrolled(false);
      }
    };

    window.addEventListener('scroll', handleScroll);
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  return (
    <nav
      className={`fixed top-0 w-full z-50 transition-all duration-300 px-4 md:px-12 py-3 flex items-center justify-between ${
        isScrolled
          ? 'bg-zinc-100/95 dark:bg-[#141414]/95 backdrop-blur-md shadow-md dark:shadow-2xl border-b border-zinc-200 dark:border-transparent'
          : 'bg-gradient-to-b from-black/85 via-black/45 to-transparent'
      }`}
    >
      {/* Lado Izquierdo: Logo y Navegación Principal */}
      <div className="flex items-center gap-6 md:gap-8">
        {/* Logotipo FlixHN Oficial */}
        <div
          onClick={() => {
            handleClearSearch();
            setCurrentTab('home');
          }}
          className="cursor-pointer flex items-center gap-1 select-none group"
        >
          <span className="font-display text-3xl md:text-4xl tracking-wider text-[#E50914] group-hover:scale-105 transition-transform font-black">
            FLIX
          </span>
          <span className="bg-[#E50914] text-white text-xs md:text-sm font-black px-1.5 py-0.5 rounded tracking-widest shadow-md">
            HN
          </span>
        </div>

        {/* Enlaces de Navegación estilo Netflix */}
        <div className="hidden md:flex items-center gap-5 lg:gap-6 text-sm font-medium">
          <button
            onClick={() => {
              handleClearSearch();
              setCurrentTab('home');
            }}
            className={`transition-colors cursor-pointer ${
              currentTab === 'home' && !searchQuery.trim()
                ? isScrolled
                  ? 'text-zinc-900 dark:text-white font-bold'
                  : 'text-white font-bold'
                : isScrolled
                ? 'text-zinc-600 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white'
                : 'text-zinc-300 hover:text-white'
            }`}
          >
            {t('home')}
          </button>
          <button
            onClick={() => {
              handleClearSearch();
              setCurrentTab('series');
            }}
            className={`transition-colors cursor-pointer ${
              currentTab === 'series' && !searchQuery.trim()
                ? isScrolled
                  ? 'text-zinc-900 dark:text-white font-bold'
                  : 'text-white font-bold'
                : isScrolled
                ? 'text-zinc-600 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white'
                : 'text-zinc-300 hover:text-white'
            }`}
          >
            {t('series')}
          </button>
          <button
            onClick={() => {
              handleClearSearch();
              setCurrentTab('movies');
            }}
            className={`transition-colors cursor-pointer ${
              currentTab === 'movies' && !searchQuery.trim()
                ? isScrolled
                  ? 'text-zinc-900 dark:text-white font-bold'
                  : 'text-white font-bold'
                : isScrolled
                ? 'text-zinc-600 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white'
                : 'text-zinc-300 hover:text-white'
            }`}
          >
            {t('movies')}
          </button>
          <button
            onClick={() => {
              handleClearSearch();
              setCurrentTab('featured');
            }}
            className={`transition-colors cursor-pointer ${
              currentTab === 'featured' && !searchQuery.trim()
                ? isScrolled
                  ? 'text-zinc-900 dark:text-white font-bold'
                  : 'text-white font-bold'
                : isScrolled
                ? 'text-zinc-600 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white'
                : 'text-zinc-300 hover:text-white'
            }`}
          >
            {t('popular')}
          </button>
        </div>
      </div>

      {/* Lado Derecho: Buscador Netflix, Idioma, Red On-Net (Solo Admin), Tema, Consola y Perfil */}
      <div className="flex items-center gap-2 md:gap-3">
        {/* Barra de Búsqueda Reactiva Estilo Netflix (Sin dropdowns flotantes deformados) */}
        <div ref={searchContainerRef} className="relative flex items-center">
          <div
            className={`flex items-center transition-all duration-300 rounded-lg ${
              searchOpen
                ? 'w-56 sm:w-64 md:w-80 bg-black/80 dark:bg-black/90 border border-zinc-700 backdrop-blur-md px-3 py-1.5 shadow-lg'
                : 'w-9 h-9 justify-center bg-transparent border-transparent'
            }`}
          >
            <button
              onClick={handleToggleSearch}
              className={`p-1 rounded-full transition-all flex items-center justify-center cursor-pointer ${
                searchOpen
                  ? 'text-zinc-400 hover:text-white'
                  : isScrolled
                  ? 'text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-800'
                  : 'text-zinc-200 hover:text-white hover:bg-black/50'
              }`}
              title="Buscar en el catálogo..."
              type="button"
            >
              {isSearching ? (
                <Loader2 className="w-4 h-4 text-[#E50914] animate-spin" />
              ) : (
                <Search className="w-4 h-4 stroke-[2.2]" />
              )}
            </button>

            <input
              ref={searchInputRef}
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder={t('search_placeholder')}
              className={`bg-transparent text-white text-xs md:text-sm placeholder-zinc-400 focus:outline-none transition-all duration-300 ${
                searchOpen ? 'w-full ml-2 opacity-100' : 'w-0 opacity-0 pointer-events-none'
              }`}
            />

            {searchOpen && searchQuery && (
              <button
                onClick={handleClearSearch}
                className="text-zinc-400 hover:text-white p-0.5 ml-1 transition-colors cursor-pointer"
                title="Limpiar búsqueda (Esc)"
                type="button"
              >
                <X className="w-4 h-4" />
              </button>
            )}
          </div>
        </div>

        {/* Selector de Idioma (ES / EN) con persistencia en localStorage */}
        <button
          onClick={toggleLang}
          className={`px-2.5 py-1 rounded-full text-xs font-bold font-mono transition-all border shadow-sm flex items-center gap-1 cursor-pointer active:scale-95 ${
            isScrolled
              ? 'bg-zinc-200 dark:bg-[#181818] text-zinc-800 dark:text-zinc-200 border-zinc-300 dark:border-zinc-700 hover:border-[#E50914]'
              : 'bg-black/60 text-white border-white/20 hover:border-[#E50914] backdrop-blur-sm'
          }`}
          title={lang === 'es' ? 'Switch to English' : 'Cambiar a Español'}
          type="button"
        >
          <Globe className="w-3.5 h-3.5 text-[#E50914]" />
          <span>{lang.toUpperCase()}</span>
        </button>

        {/* Indicador de IP Local ISP - EXCLUSIVO PARA ADMINISTRADORES */}
        {user?.role === 'admin' && (
          <div
            className={`hidden xl:flex items-center gap-2 px-3 py-1 rounded-full text-xs shadow-inner border transition-colors ${
              isScrolled
                ? 'bg-zinc-200 dark:bg-[#181818] border-zinc-300 dark:border-zinc-800 text-zinc-800 dark:text-zinc-300'
                : 'bg-black/60 border-white/20 text-gray-200 backdrop-blur-sm'
            }`}
          >
            <Wifi className={`w-3.5 h-3.5 ${isOnNet ? 'text-emerald-500 animate-pulse' : 'text-amber-500'}`} />
            <span className="font-mono text-[11px]">
              {clientIp ? `On-Net: ${clientIp}` : 'Red ISP Local'}
            </span>
          </div>
        )}

        {/* Botón Theme Toggle (Sol / Luna) */}
        <button
          onClick={toggleTheme}
          title={theme === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
          className={`p-2 rounded-full text-zinc-400 hover:text-zinc-900 dark:hover:text-white transition-colors cursor-pointer border ${
            isScrolled
              ? 'bg-zinc-100 dark:bg-[#181818] text-zinc-600 dark:text-zinc-400 border-zinc-300 dark:border-zinc-800'
              : 'bg-black/50 text-zinc-400 hover:text-white border-white/20 backdrop-blur-sm'
          }`}
          aria-label={theme === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
          type="button"
        >
          {theme === 'dark' ? (
            <Sun className="w-5 h-5 text-yellow-400" />
          ) : (
            <Moon className="w-5 h-5 text-zinc-700 dark:text-zinc-300" />
          )}
        </button>

        {/* Ícono de Configuración ISP (Solo Administradores) */}
        {user?.role === 'admin' && (
          <button
            onClick={() => {
              setCurrentTab('admin');
              window.history.pushState({}, '', '/admin');
            }}
            title="Consola de Administración ISP (/admin)"
            className={`p-2 rounded-full transition-all duration-200 border cursor-pointer ${
              currentTab === 'admin'
                ? 'bg-[#E50914] text-white border-red-500 shadow-lg shadow-red-900/40 ring-2 ring-red-400/50 scale-105'
                : isScrolled
                ? 'bg-zinc-200 dark:bg-[#181818] text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white border-zinc-300 dark:border-zinc-800'
                : 'bg-black/60 text-white hover:bg-black/80 border-white/20 backdrop-blur-sm'
            }`}
            type="button"
          >
            <Settings className="w-4 h-4 stroke-[2]" />
          </button>
        )}

        {/* Selector de Perfil y Menú Desplegable */}
        <div className="relative">
          <button
            onClick={() => setShowDropdown(!showDropdown)}
            className="flex items-center gap-2 group cursor-pointer focus:outline-none"
            type="button"
          >
            <div
              className="w-8 h-8 rounded-md flex items-center justify-center font-bold text-white shadow-md text-sm ring-2 ring-transparent group-hover:ring-white transition-all"
              style={{ backgroundColor: activeProfile?.avatar_color || '#E50914' }}
            >
              {activeProfile?.name?.charAt(0)?.toUpperCase() || 'U'}
            </div>
            <span
              className={`hidden sm:inline text-xs font-medium transition-colors ${
                isScrolled
                  ? 'text-zinc-700 dark:text-zinc-200 group-hover:text-zinc-900 dark:group-hover:text-white'
                  : 'text-zinc-200 group-hover:text-white'
              }`}
            >
              {activeProfile?.name || user?.username}
            </span>
            <ChevronDown
              className={`w-3.5 h-3.5 transition-transform ${
                isScrolled
                  ? 'text-zinc-500 dark:text-zinc-400 group-hover:text-zinc-900 dark:group-hover:text-white'
                  : 'text-zinc-300 group-hover:text-white'
              } ${showDropdown ? 'rotate-180' : ''}`}
            />
          </button>

          {/* Menú Desplegable Estilo Netflix */}
          {showDropdown && (
            <div
              onMouseLeave={() => setShowDropdown(false)}
              className="absolute right-0 mt-3 w-56 bg-zinc-100 dark:bg-[#181818] border border-zinc-200 dark:border-zinc-800 rounded-md shadow-2xl py-2 z-50 text-sm animate-fadeIn transition-colors"
            >
              <div className="px-4 py-2 border-b border-zinc-200/60 dark:border-zinc-800">
                <p className="text-xs text-zinc-500 dark:text-zinc-400">Suscriptor ISP:</p>
                <p className="font-semibold text-zinc-900 dark:text-white truncate">
                  {user?.customer_name || user?.username}
                </p>
                <span className="text-[10px] bg-red-100 dark:bg-red-600/30 text-red-600 dark:text-red-400 px-1.5 py-0.5 rounded font-mono uppercase mt-1 inline-block">
                  {user?.role} • {user?.max_screens || 2} Pantallas
                </span>
              </div>

              {/* Cambiar de Perfil */}
              <button
                onClick={() => {
                  setShowDropdown(false);
                  clearProfile();
                }}
                className="w-full text-left px-4 py-2.5 hover:bg-zinc-200 dark:hover:bg-[#282828] flex items-center gap-2.5 text-zinc-700 dark:text-zinc-200 hover:text-zinc-900 dark:hover:text-white transition-colors cursor-pointer"
                type="button"
              >
                <Users className="w-4 h-4 text-zinc-400" />
                {t('switch_profile')}
              </button>

              {/* Panel de Administración */}
              {user?.role === 'admin' && (
                <button
                  onClick={() => {
                    setShowDropdown(false);
                    setCurrentTab('admin');
                    window.history.pushState({}, '', '/admin');
                  }}
                  className="w-full text-left px-4 py-2.5 hover:bg-zinc-200 dark:hover:bg-[#282828] flex items-center gap-2.5 text-[#E50914] dark:text-red-400 hover:text-[#F40612] dark:hover:text-red-300 transition-colors cursor-pointer"
                  type="button"
                >
                  <Settings className="w-4 h-4" />
                  {t('admin_panel')}
                </button>
              )}

              <div className="border-t border-zinc-200/60 dark:border-zinc-800 my-1"></div>

              {/* Cerrar Sesión */}
              <button
                onClick={() => {
                  setShowDropdown(false);
                  logout();
                }}
                className="w-full text-left px-4 py-2.5 hover:bg-zinc-200 dark:hover:bg-[#282828] flex items-center gap-2.5 text-zinc-600 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white transition-colors cursor-pointer"
                type="button"
              >
                <LogOut className="w-4 h-4 text-zinc-400" />
                {t('logout')}
              </button>
            </div>
          )}
        </div>
      </div>
    </nav>
  );
};

export default React.memo(Navbar);
