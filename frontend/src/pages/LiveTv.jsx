import React, { useState, useEffect } from 'react';
import api from '../services/api';
import { Tv, WifiOff, AlertCircle, Search } from 'lucide-react';
import LiveTvPlayer from '../components/LiveTvPlayer';

export default function LiveTv({ onPlayChannel }) {
  const [channels, setChannels] = useState([]);
  const [categories, setCategories] = useState(['Todos']);
  const [selectedCategory, setSelectedCategory] = useState('Todos');
  const [searchQuery, setSearchQuery] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [activeChannel, setActiveChannel] = useState(null);

  useEffect(() => {
    let isMounted = true;

    const fetchChannels = async () => {
      try {
        setLoading(true);
        setError(null);
        const response = await api.get('/livetv/channels');
        const data = Array.isArray(response.data) ? response.data : (response.data?.channels || []);

        if (isMounted) {
          setChannels(data);
          const uniqueGroups = ['Todos', ...new Set(data.map(ch => ch.group_title || ch.category).filter(Boolean))];
          setCategories(uniqueGroups);
        }
      } catch (err) {
        console.error("Error al cargar canales reales del servidor:", err);
        if (isMounted) {
          setChannels([]); // JAMÁS pongas canales de respaldo aquí
          setError("No se pudieron cargar los canales del servidor.");
        }
      } finally {
        if (isMounted) setLoading(false);
      }
    };

    fetchChannels();
    return () => { isMounted = false; };
  }, []);

  // Manejo de reproducción tanto con prop del App como con estado local
  const handleSelectChannel = (channel) => {
    if (typeof onPlayChannel === 'function') {
      onPlayChannel(channel, channels);
    } else {
      setActiveChannel(channel);
    }
  };

  // Helper para resolver logotipo fallback oficial basado en marca limpia
  const getFallbackLogo = (channelName) => {
    if (!channelName) return '';
    const clean = channelName
      .toLowerCase()
      .replace(/\[[^\]]*\]|\([^\)]*\)/g, ' ')
      .replace(/\b(hd|fhd|4k|sd|1080p|720p|hevc|latino|esp|honduras|hon|hnd|mx|rd|gua)\b/gi, '')
      .replace(/[^a-zA-Z0-9\s-]/g, ' ')
      .trim()
      .replace(/\s+/g, '-');
    return `https://tvlogos.b-cdn.net/${clean}.png`;
  };

  // Filtrado dinámico con ordenamiento alfabético estricto (A-Z)
  const filteredChannels = channels
    .filter(channel => {
      const matchesCategory = selectedCategory === 'Todos' || (channel.group_title || channel.category) === selectedCategory;
      const matchesSearch = !searchQuery || 
                            (channel.name && channel.name.toLowerCase().includes(searchQuery.toLowerCase())) || 
                            String(channel.channel_number || '').includes(searchQuery);
      return matchesCategory && matchesSearch;
    })
    .sort((a, b) => (a.name || '').localeCompare(b.name || '', undefined, { numeric: true, sensitivity: 'base' }));

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
            const isNew = channel.created_at && 
              (new Date() - new Date(channel.created_at)) / (1000 * 60 * 60 * 24) <= 7;
            const isOffline = channel.is_online === false || channel.status === 'offline';
            const channelLogo = channel.logo_url || getFallbackLogo(channel.name);

            return (
              <div
                key={channel.id || index}
                onClick={() => handleSelectChannel(channel)}
                className="group bg-[#181818] border border-zinc-800 hover:border-zinc-600 rounded-lg p-3 flex flex-col justify-between cursor-pointer transition-all duration-200 hover:scale-[1.02]"
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

                {/* Logo o Icono con fallback dinámico y onError */}
                <div className="h-20 w-full flex items-center justify-center p-2 my-1 relative">
                  {channelLogo ? (
                    <>
                      <img
                        src={channelLogo}
                        alt={channel.name}
                        className="max-h-full max-w-full object-contain filter drop-shadow"
                        onError={(e) => {
                          const fallback = getFallbackLogo(channel.name);
                          if (fallback && !e.currentTarget.dataset.fallbackTried && e.currentTarget.src !== fallback) {
                            e.currentTarget.dataset.fallbackTried = 'true';
                            e.currentTarget.src = fallback;
                          } else {
                            e.currentTarget.style.display = 'none';
                            const icon = e.currentTarget.parentElement?.querySelector('.channel-fallback-icon');
                            if (icon) {
                              icon.style.display = 'flex';
                            }
                          }
                        }}
                      />
                      <div className="channel-fallback-icon hidden items-center justify-center">
                        <Tv className="w-8 h-8 text-zinc-600 group-hover:text-red-500 transition-colors" />
                      </div>
                    </>
                  ) : (
                    <Tv className="w-8 h-8 text-zinc-600 group-hover:text-red-500 transition-colors" />
                  )}
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
