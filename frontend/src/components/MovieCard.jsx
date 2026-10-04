import React from 'react';
import { Play, X } from 'lucide-react';

const MovieCard = ({ item, title: propTitle, isProgress, onPlay, onOpenModal, onRemoveProgress }) => {
  // Manejar si el item viene de prop directa title, PlaybackProgress o Title directo
  const title = propTitle || (isProgress ? item?.title : item);
  const episode = isProgress ? item?.episode : null;

  if (!title) return null;

  const posterUrl = isProgress && episode?.thumbnail_url
    ? episode.thumbnail_url
    : (title?.poster_url || title?.image || title?.poster);

  // Cálculo de porcentaje de progreso visto
  const percentWatched = isProgress && item.duration_seconds > 0
    ? Math.min(100, Math.round((item.current_seconds / item.duration_seconds) * 100))
    : 0;

  // Lógica de expiración dinámica estilo Netflix (≤ 14 días desde su ingreso/sincronización)
  const isNew = !isProgress && (
    item?.is_new ?? (
      title?.created_at
        ? (new Date().getTime() - new Date(title.created_at).getTime()) / (1000 * 60 * 60 * 24) <= 14
        : false
    )
  );

  return (
    <div
      onClick={() => onOpenModal(title)}
      className="group relative flex-shrink-0 w-36 sm:w-44 md:w-56 bg-zinc-100 dark:bg-[#181818] rounded-md overflow-hidden cursor-pointer shadow-sm dark:shadow-lg border border-zinc-300/40 dark:border-zinc-800 transition-all duration-300 ease-out hover:scale-105 hover:z-30 hover:shadow-xl hover:ring-2 hover:ring-[#E50914]/50"
    >
      {/* Imagen del Poster */}
      <div className="relative aspect-[16/9] md:aspect-[2/3] w-full overflow-hidden bg-zinc-200 dark:bg-neutral-900">
        <img
          src={posterUrl}
          alt={title.name || title.title}
          loading="lazy"
          onError={(e) => {
            e.target.onerror = null;
            e.target.src = '/placeholder-poster.png';
          }}
          className="w-full h-full object-cover object-center group-hover:brightness-110 transition-all duration-300 rounded"
        />

        {/* Badge de tipo de contenido */}
        <div className="absolute top-2 left-2 z-10 pointer-events-none">
          <span className="bg-black/75 backdrop-blur-sm text-[10px] text-white font-bold px-1.5 py-0.5 rounded uppercase border border-white/20 shadow">
            {title.type === 'movie' ? 'Película' : 'Serie'}
          </span>
        </div>

        {/* Insignia Dinámica Estilo Netflix: Recién Agregado */}
        {isNew && (
          <div className="absolute top-2 right-2 z-10 pointer-events-none">
            <span className="bg-[#E50914] text-white text-[9px] sm:text-[10px] font-black px-1.5 py-0.5 rounded shadow-lg uppercase tracking-wider border border-white/20">
              Recién Agregado
            </span>
          </div>
        )}

        {/* Botón flotante para quitar de 'Continuar Viendo' */}
        {isProgress && onRemoveProgress && (
          <button
            onClick={(e) => {
              e.stopPropagation();
              onRemoveProgress(title.id, episode?.id);
            }}
            className="absolute top-2 right-2 z-20 w-7 h-7 rounded-full bg-black/70 hover:bg-[#E50914] text-white/80 hover:text-white flex items-center justify-center backdrop-blur-sm border border-white/20 transition-all opacity-0 group-hover:opacity-100 shadow-lg cursor-pointer hover:scale-110 active:scale-95"
            title="Quitar de Continuar Viendo"
            type="button"
          >
            <X className="w-3.5 h-3.5 stroke-[2.5]" />
          </button>
        )}

        {/* Botón flotante de reproducción en hover */}
        <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center pointer-events-none">
          <button
            onClick={(e) => {
              e.stopPropagation();
              onPlay(title, episode);
            }}
            className="w-12 h-12 rounded-full bg-[#E50914] text-white flex items-center justify-center hover:bg-[#F40612] hover:scale-110 active:scale-95 transition-all shadow-xl pointer-events-auto cursor-pointer"
            title="Reproducir ahora"
            type="button"
          >
            <Play className="w-6 h-6 fill-white ml-0.5" />
          </button>
        </div>

        {/* Barra Roja de Progreso de Netflix para 'Continuar Viendo' */}
        {isProgress && percentWatched > 0 && (
          <div className="absolute bottom-0 left-0 right-0 h-1.5 bg-neutral-800/80 z-10">
            <div
              className="h-full bg-[#E50914]"
              style={{ width: `${percentWatched}%` }}
            />
          </div>
        )}
      </div>

      {/* Información condensada en el pie de la tarjeta (Fondo oscuro integrado #181818 / bg-zinc-900/90) */}
      <div className="p-2.5 space-y-1 bg-zinc-100 dark:bg-[#181818] border-t border-zinc-200/50 dark:border-zinc-800/80 transition-colors">
        <h3 className="font-semibold text-xs md:text-sm text-zinc-900 dark:text-zinc-100 truncate group-hover:text-[#E50914] transition-colors">
          {title.name}
        </h3>
        
        {isProgress && episode && (
          <p className="text-[11px] text-zinc-500 dark:text-zinc-400 truncate">
            {episode.title}
          </p>
        )}

        <div className="flex items-center justify-between text-[11px] text-zinc-500 dark:text-zinc-400">
          <span>{title.release_year > 1900 ? title.release_year : ''}</span>
          <span className="truncate max-w-[95px]">{title.genre}</span>
        </div>
      </div>
    </div>
  );
};

export default MovieCard;
