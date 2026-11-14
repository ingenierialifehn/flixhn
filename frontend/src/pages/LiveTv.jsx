import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import api from '../services/api';
import { Tv, WifiOff, AlertCircle, Search } from 'lucide-react';
import LiveTvPlayer from '../components/LiveTvPlayer';

// Polyfill para crypto.randomUUID en entornos HTTP no seguros
if (typeof window !== 'undefined' && (!window.crypto || !window.crypto.randomUUID)) {
  if (!window.crypto) window.crypto = {};
  window.crypto.randomUUID = function () {
    return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
      (+c ^ (Math.random() * 16 >> (+c / 4))).toString(16)
    );
  };
}

// Sanitización y corrección de codificación UTF-8 / mojibake para categorías y nombres
function fixEncoding(text) {
  if (!text || typeof text !== 'string') return text || '';
  return text
    .replace(/M├║sica/gi, 'Música')
    .replace(/M├ºsica/gi, 'Música')
    .replace(/Música/gi, 'Música')
    .replace(/├║/g, 'ú')
    .replace(/├│/g, 'ó')
    .replace(/├í/g, 'á')
    .replace(/├®/g, 'é')
    .replace(/├¡/g, 'í')
    .replace(/├▒/g, 'ñ')
    .replace(/├Ñ/g, 'Ñ')
    .replace(/ú/g, 'ú')
    .replace(/ó/g, 'ó')
    .replace(/á/g, 'á')
    .replace(/é/g, 'é')
    .replace(/í/g, 'í')
    .replace(/ñ/g, 'ñ')
    .trim();
}

// Helper para extraer iniciales legibles del canal
function getChannelInitials(name) {
  if (!name) return 'TV';
  const clean = name.replace(/^(HD|SD|FHD|4K|\d+)\s*/i, '').trim();
  const words = clean.split(/\s+/).filter(Boolean);
  if (words.length >= 2) {
    return (words[0][0] + words[1][0]).toUpperCase();
  }
  return clean.slice(0, 3).toUpperCase() || 'TV';
}

const GRADIENT_PALETTES = [
  'from-red-950/70 via-[#181818] to-black',
  'from-rose-950/80 via-[#161616] to-black',
  'from-zinc-900 via-neutral-900 to-black',
  'from-red-900/40 via-zinc-900 to-black',
  'from-slate-900 via-zinc-900 to-black',
];

function getGradient(name, id) {
  const seed = typeof id === 'number' ? id : (name || '').split('').reduce((acc, c) => acc + c.charCodeAt(0), 0);
  return GRADIENT_PALETTES[Math.abs(seed) % GRADIENT_PALETTES.length];
}

// Componente para tarjeta de logo del canal con avatar/badge estilizado de FlixHN ante fallback
function ChannelLogoCard({ channel }) {
  const [imgError, setImgError] = useState(false);
  const logoSrc = channel.logo_url || channel.logo || channel.icon_url || channel.tvg_logo;
  const initials = getChannelInitials(channel.name);
  const gradient = getGradient(channel.name, channel.id);

  if (!logoSrc || imgError) {
    return (
      <div className={`w-full h-full flex flex-col items-center justify-center p-2 rounded bg-gradient-to-br ${gradient} border border-red-500/20 shadow-inner group-hover:border-red-500/40 transition-all select-none`}>
        <div className="w-10 h-10 rounded-full bg-red-600/20 border border-red-500/30 flex items-center justify-center shadow-lg mb-1 group-hover:scale-105 group-hover:bg-red-600/30 transition-transform">
          <span className="text-xs font-black tracking-wider text-red-400 group-hover:text-red-300">
            {initials}
          </span>
        </div>
        <span className="text-[10px] font-bold text-zinc-300 text-center line-clamp-1 group-hover:text-white px-1">
          {channel.name}
        </span>
      </div>
    );
  }

  return (
    <div className="w-full h-full flex items-center justify-center p-2 relative bg-[#121212] rounded">
      <img
        src={logoSrc}
        alt={channel.name}
        loading="lazy"
        className="max-h-full max-w-full object-contain filter drop-shadow select-none transition-transform duration-200 group-hover:scale-105"
        onError={() => setImgError(true)}
      />
    </div>
  );
}

export default function LiveTv({ onPlayChannel }) {
  // =========================================================================
  // HOOKS AL NIVEL SUPERIOR ABSOLUTO (Sin retornos condicionales previos)
  // =========================================================================
  const [channels, setChannels] = useState([]);
  const [categories, setCategories] = useState(['Todos']);
  const [selectedCategory, setSelectedCategory] = useState('Todos');
  const [searchQuery, setSearchQuery] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [activeChannel, setActiveChannel] = useState(null);
  const isMountedRef = useRef(true);

  // 1. Carga inicial de canales desde la API
  useEffect(() => {
    isMountedRef.current = true;

    const fetchChannels = async () => {
      try {
        setLoading(true);
        setError(null);
        let res;
        try {
          res = await api.get('/livetv/channels');
        } catch (e) {
          res = await api.get('/channels');
        }

        const rawData = Array.isArray(res.data) ? res.data : (res.data?.channels || []);
        const sanitizedData = rawData.map((ch) => {
          const cleanName = fixEncoding(ch.name);
          const cleanGroup = fixEncoding(ch.group_title || ch.category || 'General');
          return {
            ...ch,
            name: cleanName,
            group_title: cleanGroup,
            category: cleanGroup,
          };
        });

        if (isMountedRef.current) {
          setChannels(sanitizedData);
          const uniqueGroups = ['Todos', ...new Set(sanitizedData.map((ch) => ch.group_title).filter(Boolean))];
          setCategories(uniqueGroups);
        }
      } catch (err) {
        console.error('Error al cargar canales del servidor:', err);
        if (isMountedRef.current) {
          setChannels([]);
          setError('No se pudieron cargar los canales del servidor.');
        }
      } finally {
        if (isMountedRef.current) {
          setLoading(false);
        }
      }
    };

    fetchChannels();
    return () => {
      isMountedRef.current = false;
    };
  }, []);

  // 2. Escuchar evento global 'media:removed' para purgar automáticamente canales eliminados o caídos
  useEffect(() => {
    const handleMediaRemoved = (e) => {
      const { id, type } = e.detail || {};
      if (!id) return;
      const idStr = String(id);

      if (type === 'channel' || !type) {
        setChannels((prev) => prev.filter((ch) => String(ch.id) !== idStr));
        setActiveChannel((current) => {
          if (current && String(current.id) === idStr) {
            return null; // Cerrar el reproductor si ese canal dejó de estar disponible
          }
          return current;
        });
      }
    };

    window.addEventListener('media:removed', handleMediaRemoved);
    return () => {
      window.removeEventListener('media:removed', handleMediaRemoved);
    };
  }, []);

  // 3. Manejo de reproducción tanto con prop del App como con estado local
  const handleSelectChannel = useCallback((channel) => {
    if (!channel || channel.is_active === false || channel.deleted) return;
    if (typeof onPlayChannel === 'function') {
      onPlayChannel(channel, channels);
    } else {
      setActiveChannel(channel);
    }
  }, [channels, onPlayChannel]);

  // 4. Filtrado dinámico con ordenamiento alfabético estricto (A-Z)
  const filteredChannels = useMemo(() => {
    return channels
      .filter((channel) => {
        if (channel.is_active === false || channel.deleted) return false;
        const matchesCategory =
          selectedCategory === 'Todos' || (channel.group_title || channel.category) === selectedCategory;
        const matchesSearch =
          !searchQuery ||
          (channel.name && channel.name.toLowerCase().includes(searchQuery.toLowerCase())) ||
          String(channel.channel_number || '').includes(searchQuery);
        return matchesCategory && matchesSearch;
      })
      .sort((a, b) => (a.name || '').localeCompare(b.name || '', undefined, { numeric: true, sensitivity: 'base' }));
  }, [channels, selectedCategory, searchQuery]);

  return (
    <div className="min-h-screen bg-[#141414] text-white pt-20 px-4 md:px-10 pb-16">
      {/* Cabecera */}
      <div className="mb-6">
        <h1 className="text-2xl md:text-3xl font-extrabold text-white tracking-tight">
          Televisión en Vivo y Deportes On-Net
        </h1>
        <p className="text-xs md:text-sm text-zinc-400 mt-1">
          Señales en tiempo real transmitidas a través de la red local.
        </p>

        {/* Estado y Conexión */}
        <div className="flex items-center gap-4 mt-3 text-xs">
          <span className="flex items-center gap-1.5 text-zinc-300 bg-zinc-900 border border-zinc-800 px-3 py-1 rounded-full">
            <Tv className="w-3.5 h-3.5 text-red-500" />
            {channels.length} Canales Disponibles
          </span>
          <span className="flex items-center gap-1.5 text-green-400 bg-green-500/10 border border-green-500/20 px-3 py-1 rounded-full">
            <span className="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
            En Directo
          </span>
        </div>
      </div>

      {/* Buscador y Filtros */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        {/* Categorías */}
        {categories.length > 1 && (
          <div className="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none">
            {categories.map((cat) => (
              <button
                key={cat}
                type="button"
                onClick={() => setSelectedCategory(cat)}
                className={`px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap transition-colors cursor-pointer ${
                  selectedCategory === cat
                    ? 'bg-[#E50914] text-white'
                    : 'bg-[#222] text-zinc-400 hover:text-white hover:bg-[#333]'
                }`}
              >
                {cat}
              </button>
            ))}
          </div>
        )}

        {/* Input Buscador */}
        <div className="relative w-full md:w-64">
          <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500" />
          <input
            type="text"
            placeholder="Buscar canal por nombre o número..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full pl-9 pr-3 py-1.5 bg-[#222] border border-zinc-800 rounded-lg text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-zinc-600"
          />
        </div>
      </div>

      {/* Estados: Cargando / Error / Sin canales */}
      {loading && (
        <div className="flex flex-col items-center justify-center py-24 text-zinc-500">
          <div className="w-8 h-8 border-2 border-red-600 border-t-transparent rounded-full animate-spin mb-3"></div>
          <p className="text-xs">Sincronizando señales de TV...</p>
        </div>
      )}

      {error && !loading && (
        <div className="flex flex-col items-center justify-center py-20 bg-[#181818] border border-zinc-800 rounded-xl p-8 text-center max-w-lg mx-auto">
          <WifiOff className="w-12 h-12 text-red-500 mb-3" />
          <h3 className="text-base font-bold text-white mb-1">Error de Conexión</h3>
          <p className="text-xs text-zinc-400 mb-4">{error}</p>
          <button
            type="button"
            onClick={() => window.location.reload()}
            className="px-4 py-1.5 bg-zinc-800 hover:bg-zinc-700 text-xs font-semibold rounded-lg text-white transition-colors cursor-pointer"
          >
            Reintentar
          </button>
        </div>
      )}

      {!loading && !error && channels.length === 0 && (
        <div className="flex flex-col items-center justify-center py-20 bg-[#181818] border border-zinc-800 rounded-xl p-8 text-center max-w-lg mx-auto">
          <AlertCircle className="w-12 h-12 text-zinc-500 mb-3" />
          <h3 className="text-base font-bold text-white mb-1">No hay canales configurados</h3>
          <p className="text-xs text-zinc-400">
            Añada una fuente M3U desde la Consola de Administración para visualizar las señales de televisión.
          </p>
        </div>
      )}

      {!loading && !error && channels.length > 0 && filteredChannels.length === 0 && (
        <div className="flex flex-col items-center justify-center py-20 bg-[#181818] border border-zinc-800 rounded-xl p-8 text-center max-w-lg mx-auto">
          <Tv className="w-12 h-12 text-zinc-500 mb-3" />
          <h3 className="text-base font-bold text-white mb-1">No se encontraron canales</h3>
          <p className="text-xs text-zinc-400">
            {searchQuery
              ? `No hay canales que coincidan con "${searchQuery}".`
              : 'No hay canales disponibles en esta categoría.'}
          </p>
        </div>
      )}

      {/* Grid de Canales */}
      {!loading && !error && filteredChannels.length > 0 && (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
          {filteredChannels.map((channel, index) => {
            const isNew =
              channel.created_at && (new Date() - new Date(channel.created_at)) / (1000 * 60 * 60 * 24) <= 7;
            const isOffline = channel.is_online === false || channel.status === 'offline';
            const channelLogo = channel.logo || channel.logo_url || channel.tvg_logo;
            const isUnavailable = channel.is_active === false || channel.deleted;

            return (
              <div
                key={channel.id || index}
                onClick={() => !isUnavailable && handleSelectChannel(channel)}
                className={`group bg-[#181818] border border-zinc-800 hover:border-zinc-600 rounded-lg p-3 flex flex-col justify-between transition-all duration-200 select-none ${
                  isUnavailable
                    ? 'opacity-50 pointer-events-none cursor-not-allowed'
                    : 'cursor-pointer hover:scale-[1.02]'
                }`}
              >
                {/* Header CH / Badges */}
                <div className="flex items-center justify-between mb-2 gap-1.5">
                  <div className="flex items-center gap-1.5 min-w-0 flex-wrap">
                    <span className="text-[10px] font-mono font-bold text-zinc-400 bg-zinc-900 border border-zinc-800 px-1.5 py-0.5 rounded shrink-0">
                      CH {channel.channel_number || index + 1}
                    </span>
                    {isNew && (
                      <span className="bg-amber-500/20 text-amber-400 border border-amber-500/30 text-[9px] font-bold px-1.5 py-0.5 rounded shrink-0 whitespace-nowrap">
                        Nuevo Canal
                      </span>
                    )}
                  </div>

                  {isOffline ? (
                    <span className="bg-zinc-800 text-zinc-400 border border-zinc-700 text-[9px] font-semibold px-1.5 py-0.5 rounded shrink-0">
                      Sin Señal
                    </span>
                  ) : (
                    <span className="flex items-center gap-1 text-[9px] font-bold text-white bg-red-600 px-1.5 py-0.5 rounded-full shrink-0">
                      <span className="w-1 h-1 rounded-full bg-white animate-pulse"></span>
                      LIVE
                    </span>
                  )}
                </div>

                {/* Logo o Icono con persistencia local y fallback seguro */}
                <div className="h-24 w-full bg-[#121212] rounded-md flex items-center justify-center my-1 relative overflow-hidden border border-zinc-800/60 group-hover:border-zinc-700/80 transition-all">
                  <ChannelLogoCard channel={channel} />
                </div>

                {/* Título y Categoría */}
                <div className="border-t border-zinc-800/80 pt-2 mt-1">
                  <h4 className="text-xs font-semibold text-zinc-200 truncate group-hover:text-white" title={channel.name}>
                    {channel.name}
                  </h4>
                  <span className="text-[10px] text-zinc-500 truncate block">
                    {channel.group_title || channel.category || 'General'}
                  </span>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Reproductor Modal si se selecciona un canal en modo autónomo */}
      {activeChannel && (
        <LiveTvPlayer
          channel={activeChannel}
          channels={channels}
          onClose={() => setActiveChannel(null)}
          onSelectChannel={(newCh) => setActiveChannel(newCh)}
        />
      )}
    </div>
  );
}
