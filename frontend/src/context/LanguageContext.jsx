import React, { createContext, useContext, useState, useEffect, useCallback, useMemo } from 'react';

const LanguageContext = createContext();

const translations = {
  es: {
    home: 'Inicio',
    series: 'Series',
    movies: 'Películas',
    popular: 'Novedades Populares',
    continue_watching: 'Continuar Viendo',
    search_placeholder: 'Buscar títulos, géneros...',
    play: 'Reproducir',
    more_info: 'Más información',
    episodes: 'Episodios',
    season: 'Temporada',
    next_episode: 'Siguiente Episodio',
    audio_subtitles: 'Audio y Subtítulos',
    speed: 'Velocidad',
    fullscreen: 'Pantalla Completa',
    on_net_local: 'Red ISP Local',
    search_results_for: 'Resultados para',
    no_results_found: 'No se encontraron títulos que coincidan con',
    clear_search: 'Limpiar búsqueda',
    admin_panel: 'Consola ISP',
    switch_profile: 'Cambiar de Perfil',
    logout: 'Cerrar Sesión',
    match: 'Coincidencia',
    audio_latino: 'Español Latino',
    audio_english: 'Inglés [Original]',
    subtitles_off: 'Desactivados',
    original_isp: 'ORIGINAL ISP ON-NET',
    resuming_at: 'Reanudar en',
    livetv: 'TV en Vivo',
  },
  en: {
    home: 'Home',
    series: 'TV Shows',
    movies: 'Movies',
    popular: 'New & Popular',
    continue_watching: 'Continue Watching',
    search_placeholder: 'Search titles, genres...',
    play: 'Play',
    more_info: 'More Info',
    episodes: 'Episodes',
    season: 'Season',
    next_episode: 'Next Episode',
    audio_subtitles: 'Audio & Subtitles',
    speed: 'Speed',
    fullscreen: 'Fullscreen',
    on_net_local: 'Local ISP Network',
    search_results_for: 'Results for',
    no_results_found: 'No titles found matching',
    clear_search: 'Clear search',
    admin_panel: 'ISP Console',
    switch_profile: 'Switch Profile',
    logout: 'Sign Out',
    match: 'Match',
    audio_latino: 'Spanish (Latin America)',
    audio_english: 'English [Original]',
    subtitles_off: 'Off',
    original_isp: 'ORIGINAL ISP ON-NET',
    resuming_at: 'Resume at',
    livetv: 'Live TV',
  }
};

export const LanguageProvider = ({ children }) => {
  const [lang, setLang] = useState(() => {
    try {
      const saved = localStorage.getItem('flixhn_lang');
      if (saved === 'es' || saved === 'en') return saved;
    } catch (e) {}
    return 'es'; // Español por defecto
  });

  useEffect(() => {
    try {
      localStorage.setItem('flixhn_lang', lang);
      document.documentElement.lang = lang;
    } catch (e) {}
  }, [lang]);

  const toggleLang = useCallback(() => {
    setLang((prev) => (prev === 'es' ? 'en' : 'es'));
  }, []);

  const t = useCallback((key, fallback = '') => {
    return translations[lang]?.[key] || translations['es']?.[key] || fallback || key;
  }, [lang]);

  const value = useMemo(
    () => ({ lang, setLang, toggleLang, t }),
    [lang, toggleLang, t]
  );

  return (
    <LanguageContext.Provider value={value}>
      {children}
    </LanguageContext.Provider>
  );
};

export const useLanguage = () => {
  const context = useContext(LanguageContext);
  if (!context) {
    throw new Error('useLanguage debe ser utilizado dentro de un LanguageProvider');
  }
  return context;
};

export default LanguageContext;
