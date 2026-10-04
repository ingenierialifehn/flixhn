import React, { useState, useEffect, useCallback } from 'react';
import {
  Tv,
  Search,
  Radio,
  Play,
  Layers,
  Sparkles,
  Wifi,
  Filter,
  CheckCircle,
  Clock,
  RadioTower,
  SlidersHorizontal,
} from 'lucide-react';
import api from '../api/axios';

export default function LiveTvView({ onPlayChannel }) {
  const [channels, setChannels] = useState([]);
  const [categories, setCategories] = useState([]);
  const [selectedCategory, setSelectedCategory] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [loading, setLoading] = useState(true);

  // Carga de canales desde la API
  const loadLiveTv = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/livetv/channels');
      if (res.data) {
        setChannels(res.data.channels || []);
        setCategories(res.data.categories || []);
      }
    } catch (err) {
      console.error('Error cargando canales de TV en vivo:', err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadLiveTv();
  }, [loadLiveTv]);

  // Filtrado reactivo de canales
  const filteredChannels = channels.filter((ch) => {
    const matchesSearch =
      ch.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      ch.channel_number?.toString().includes(searchQuery) ||
      ch.group_title?.toLowerCase().includes(searchQuery.toLowerCase());

    const matchesCategory =
      selectedCategory === 'all' || ch.group_title === selectedCategory;

    return matchesSearch && matchesCategory;
  });

  return (
    <div className="pt-20 md:pt-24 px-4 md:px-12 pb-16 min-h-[85vh] space-y-8 animate-fadeIn">
      {/* Banner Hero Superior de TV en Vivo */}
      <div className="relative rounded-2xl overflow-hidden bg-gradient-to-r from-red-950/60 via-zinc-900 to-[#141414] border border-zinc-800 p-6 md:p-8 shadow-2xl">
        <div className="relative z-10 max-w-2xl space-y-3">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold bg-[#E50914] text-white tracking-wider uppercase font-mono shadow-md">
            <span className="w-2 h-2 rounded-full bg-white animate-pulse" />
            FlixHN Live DirectPlay
          </div>
          <h1 className="text-2xl md:text-4xl font-black text-white tracking-wide">
            Televisión en Vivo y Deportes On-Net
          </h1>
          <p className="text-xs md:text-sm text-zinc-300 leading-relaxed">
            Señales digitales en tiempo real transmitidas directamente a través de la red de fibra óptica del ISP, con latencia ultra-baja y resolución en alta definición.
          </p>

          <div className="flex flex-wrap items-center gap-4 pt-2 text-xs text-zinc-400">
            <span className="flex items-center gap-1.5 font-medium text-white">
              <RadioTower className="w-4 h-4 text-[#E50914]" />
              {channels.length} Canales Disponibles
            </span>
            <span>•</span>
            <span className="flex items-center gap-1.5 font-medium text-emerald-400">
              <CheckCircle className="w-4 h-4" />
              Búfer HLS Optimizado
            </span>
            <span>•</span>
            <span className="flex items-center gap-1.5 font-medium text-zinc-400 font-mono">
              On-Net &lt; 2ms
            </span>
          </div>
        </div>

        {/* Efecto decorativo de fondo */}
        <div className="absolute right-0 top-0 bottom-0 w-1/3 bg-gradient-to-l from-red-600/10 to-transparent pointer-events-none" />
        <div className="absolute -right-10 -bottom-10 opacity-10 pointer-events-none">
          <Tv className="w-72 h-72 text-white stroke-[1]" />
        </div>
      </div>

      {/* Barra de Filtros, Categorías y Buscador de Canales */}
      <div className="space-y-4">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-zinc-800 pb-4">
          {/* Pestañas de Categorías (Nacionales, Deportes, Cine, Noticias, Infantiles, etc.) */}
          <div className="flex items-center gap-2 overflow-x-auto pb-2 md:pb-0 scrollbar-none">
            {categories.map((cat) => {
              const isActive = selectedCategory === cat.id;
              return (
                <button
                  key={cat.id}
                  type="button"
                  onClick={() => setSelectedCategory(cat.id)}
                  className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap cursor-pointer ${
                    isActive
                      ? 'bg-[#E50914] text-white shadow-lg scale-102'
                      : 'bg-zinc-850 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-800'
                  }`}
                >
                  <span>{cat.name}</span>
                  <span
                    className={`px-1.5 py-0.2 rounded-full text-[10px] font-mono ${
                      isActive ? 'bg-black/30 text-white' : 'bg-zinc-900 text-zinc-400'
                    }`}
                  >
                    {cat.count}
                  </span>
                </button>
              );
            })}
          </div>

          {/* Buscador de Canales en Tiempo Real */}
          <div className="relative w-full md:w-72 shrink-0">
            <Search className="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Buscar canal por nombre o número..."
              className="w-full bg-[#181818] border border-zinc-700 focus:border-[#E50914] rounded-xl pl-9 pr-3 py-2 text-xs text-white placeholder-zinc-500 outline-none transition-colors"
            />
          </div>
        </div>
      </div>

      {/* Grid de Tarjetas de Canales */}
      {loading ? (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
          {[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map((i) => (
            <div
              key={i}
              className="aspect-video bg-[#181818] border border-zinc-800 rounded-xl animate-pulse"
            />
          ))}
        </div>
      ) : filteredChannels.length === 0 ? (
        <div className="py-20 text-center space-y-3">
          <Tv className="w-12 h-12 text-zinc-600 mx-auto" />
          <h3 className="text-base font-bold text-white">No se encontraron canales</h3>
          <p className="text-xs text-zinc-400 max-w-sm mx-auto">
            {searchQuery
              ? `No hay canales que coincidan con "${searchQuery}".`
              : 'No hay canales disponibles en esta categoría.'}
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
          {filteredChannels.map((channel) => (
            <div
              key={channel.id}
              onClick={() => onPlayChannel(channel, channels)}
              className="group relative bg-[#181818] hover:bg-[#202020] border border-zinc-800 hover:border-zinc-600 rounded-xl overflow-hidden cursor-pointer transition-all duration-300 hover:scale-103 hover:shadow-2xl flex flex-col justify-between"
            >
              {/* Contenedor de Logo del Canal */}
              <div className="relative aspect-video bg-[#121212] flex items-center justify-center p-4 border-b border-zinc-800/80 group-hover:border-zinc-700">
                {channel.logo_url ? (
                  <img
                    src={channel.logo_url}
                    alt={channel.name}
                    className="max-h-16 max-w-full object-contain filter drop-shadow-md group-hover:scale-105 transition-transform"
                    onError={(e) => {
                      e.target.style.display = 'none';
                    }}
                  />
                ) : (
                  <Tv className="w-10 h-10 text-zinc-600 group-hover:text-[#E50914] transition-colors" />
                )}

                {/* Número de Canal */}
                {channel.channel_number && (
                  <span className="absolute top-2 left-2 bg-black/75 backdrop-blur-md text-white font-mono text-[10px] font-bold px-1.5 py-0.5 rounded border border-white/10">
                    CH {channel.channel_number}
                  </span>
                )}

                {/* Indicador EN VIVO (Live) */}
                <span className="absolute top-2 right-2 flex items-center gap-1 bg-red-600/90 text-white text-[10px] font-bold font-mono px-2 py-0.5 rounded-full uppercase tracking-wider shadow-md">
                  <span className="w-1.5 h-1.5 rounded-full bg-white animate-ping" />
                  Live
                </span>

                {/* Botón de Play Overlay en Hover */}
                <div className="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 flex items-center justify-center transition-opacity">
                  <div className="w-12 h-12 rounded-full bg-[#E50914] text-white flex items-center justify-center shadow-2xl transform scale-75 group-hover:scale-100 transition-transform">
                    <Play className="w-5 h-5 fill-current ml-0.5" />
                  </div>
                </div>
              </div>

              {/* Información del Canal */}
              <div className="p-3 space-y-1">
                <h3 className="text-xs md:text-sm font-bold text-white truncate group-hover:text-[#E50914] transition-colors">
                  {channel.name}
                </h3>
                <div className="flex items-center justify-between text-[11px] text-zinc-400">
                  <span className="truncate">{channel.group_title || 'General'}</span>
                  <span className="text-[10px] font-mono text-zinc-500">HD 1080p</span>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
