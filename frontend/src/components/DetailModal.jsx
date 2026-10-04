import React, { useState, useEffect, useRef, useMemo } from 'react';
import { X, Play, Clock, Tv, Volume2, VolumeX } from 'lucide-react';
import api from '../api/axios';
import { useAuth } from '../context/AuthContext';

const DetailModal = ({ titleId, onClose, onPlay }) => {
  const { activeProfile } = useAuth();
  const [titleData, setTitleData] = useState(null);
  const [selectedSeason, setSelectedSeason] = useState(null);
  const [progressData, setProgressData] = useState({});
  const [loading, setLoading] = useState(true);

  // Estados del Trailer / Vista previa superior estilo Netflix
  const [isTrailerMuted, setIsTrailerMuted] = useState(true);
  const [isTrailerPlaying, setIsTrailerPlaying] = useState(true);
  const [isTrailerLoaded, setIsTrailerLoaded] = useState(false);
  const trailerVideoRef = useRef(null);

  useEffect(() => {
    if (!titleId) return;

    setLoading(true);
    const profileParam = activeProfile ? `?profile_id=${activeProfile.id}` : '';
    api.get(`/titles/${titleId}${profileParam}`)
      .then((res) => {
        const title = res.data.title;
        setTitleData(title);
        setProgressData(res.data.progress || {});
      })
      .catch((err) => {
        console.error('Error al cargar detalle del título:', err);
      })
      .finally(() => {
        setLoading(false);
      });
  }, [titleId, activeProfile]);

  // Manejador tecla ESC para cerrar
  useEffect(() => {
    const handleKeyDown = (e) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [onClose]);

  // Detección y filtrado de trailers con "0" o Especiales / Trailers (Puntos 3 y 4)
  const { cleanedSeasons, trailerUrl } = useMemo(() => {
    let detectedTrailer = titleData?.trailer_url || titleData?.trailer?.stream_url || null;

    if (!titleData?.seasons || titleData.seasons.length === 0) {
      return { cleanedSeasons: [], trailerUrl: detectedTrailer };
    }

    const validSeasons = [];

    // Ordenamiento estricto por season_number ASC
    const sortedSeasons = [...titleData.seasons].sort((a, b) => (a.season_number || 1) - (b.season_number || 1));

    sortedSeasons.forEach((season) => {
      const seasonNum = parseInt(season.season_number, 10);
      const isSeasonZero = seasonNum === 0 || /^(especiales|trailers|temporada\s*0)/i.test(season.title || '');

      const regularEpisodes = [];

      (season.episodes || []).forEach((ep) => {
        const epNum = parseInt(ep.episode_number, 10);
        const epTitle = (ep.title || '').trim();
        const epStream = ep.stream_url || ep.stream_path || '';

        // Detección si comienza con "0" (0, 00, Capitulo 0, etc.) o marcado como Especial/Trailer
        const isEpisodeZeroOrTrailer =
          isSeasonZero ||
          epNum === 0 ||
          /^(0+|0\d+|cap[ií]tulo\s*0+|capitulo\s*0+|episodio\s*0+|trailer|previa|especial)/i.test(epTitle) ||
          /\b(trailer|previa)\b/i.test(epTitle);

        if (isEpisodeZeroOrTrailer) {
          if (!detectedTrailer && epStream) {
            detectedTrailer = epStream;
          }
        } else {
          regularEpisodes.push(ep);
        }
      });

      // Si no es temporada 0 y tiene episodios regulares, la incluimos
      if (!isSeasonZero && regularEpisodes.length > 0) {
        // Ordenamiento estricto por episode_number ASC
        regularEpisodes.sort((a, b) => (a.episode_number || 1) - (b.episode_number || 1));
        validSeasons.push({
          ...season,
          episodes: regularEpisodes,
        });
      }
    });

    return { cleanedSeasons: validSeasons, trailerUrl: detectedTrailer };
  }, [titleData]);

  // Selección automática de la primera temporada válida al cargar
  useEffect(() => {
    if (cleanedSeasons.length > 0) {
      if (!selectedSeason || !cleanedSeasons.some((s) => s.id === selectedSeason.id)) {
        setSelectedSeason(cleanedSeasons[0]);
      }
    } else {
      setSelectedSeason(null);
    }
  }, [cleanedSeasons, selectedSeason]);

  // Sincronizar estado de mute con el elemento de video cuando cambia
  useEffect(() => {
    if (trailerVideoRef.current) {
      trailerVideoRef.current.muted = isTrailerMuted;
    }
  }, [isTrailerMuted]);

  if (!titleId) return null;

  const formatDuration = (seconds) => {
    if (!seconds) return 'N/D';
    const mins = Math.floor(seconds / 60);
    const hrs = Math.floor(mins / 60);
    const remainingMins = mins % 60;
    return hrs > 0 ? `${hrs} h ${remainingMins} min` : `${mins} min`;
  };

  const formatTime = (timeInSeconds) => {
    if (!timeInSeconds) return '00:00';
    const mins = Math.floor(timeInSeconds / 60);
    const secs = Math.floor(timeInSeconds % 60);
    return `${mins}:${secs < 10 ? '0' : ''}${secs}`;
  };

  return (
    <div
      onClick={onClose}
      className="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 md:p-6 bg-black/85 backdrop-blur-sm overflow-y-auto animate-fadeIn select-none"
    >
      <div
        onClick={(e) => e.stopPropagation()}
        className="relative w-full max-w-4xl bg-zinc-100 dark:bg-[#181818] rounded-xl overflow-hidden shadow-2xl border border-zinc-300/40 dark:border-zinc-800 my-auto transition-colors"
      >
        {/* Botón Cerrar */}
        <button
          onClick={onClose}
          className="absolute top-4 right-4 z-40 w-10 h-10 rounded-full bg-black/70 hover:bg-black/90 text-white flex items-center justify-center transition-all shadow-lg backdrop-blur-sm cursor-pointer hover:scale-105"
          aria-label="Cerrar modal"
        >
          <X className="w-5 h-5 stroke-[2.5]" />
        </button>

        {loading || !titleData ? (
          <div className="h-96 flex items-center justify-center">
            <div className="w-12 h-12 border-4 border-red-600 border-t-transparent rounded-full animate-spin"></div>
          </div>
        ) : (
          <div>
            {/* Cabecera con Trailer Automático o Backdrop Estilo Netflix */}
            <div className="relative aspect-[16/9] w-full max-h-[440px] overflow-hidden bg-zinc-900">
              {trailerUrl && isTrailerPlaying ? (
                <video
                  ref={trailerVideoRef}
                  src={trailerUrl}
                  autoPlay
                  muted={isTrailerMuted}
                  loop
                  playsInline
                  onPlaying={() => {
                    setIsTrailerLoaded(true);
                    setIsTrailerPlaying(true);
                  }}
                  onError={() => {
                    console.warn('Trailer no disponible para reproducción en cabecera');
                    setIsTrailerPlaying(false);
                  }}
                  className="w-full h-full object-cover object-center filter brightness-90 transition-opacity duration-700"
                />
              ) : (
                <img
                  src={titleData.backdrop_url || titleData.poster_url}
                  alt={titleData.name}
                  className="w-full h-full object-cover object-center filter brightness-90"
                />
              )}

              {/* Botón Flotante con Ícono de Volumen en la Esquina Inferior Derecha (Punto 4) */}
              {trailerUrl && isTrailerPlaying && (
                <button
                  onClick={() => {
                    const nextMuted = !isTrailerMuted;
                    setIsTrailerMuted(nextMuted);
                    if (trailerVideoRef.current) {
                      trailerVideoRef.current.muted = nextMuted;
                    }
                  }}
                  className="absolute bottom-6 right-6 md:right-10 z-30 w-10 h-10 rounded-full bg-black/70 hover:bg-black/90 text-white flex items-center justify-center border border-white/30 backdrop-blur-md transition-all hover:scale-110 active:scale-95 shadow-2xl cursor-pointer"
                  title={isTrailerMuted ? "Activar audio" : "Silenciar"}
                  type="button"
                >
                  {isTrailerMuted ? (
                    <VolumeX className="w-5 h-5 text-white" />
                  ) : (
                    <Volume2 className="w-5 h-5 text-white" />
                  )}
                </button>
              )}

              <div className="absolute inset-0 bg-gradient-to-t from-zinc-100 dark:from-[#181818] via-transparent to-black/30 pointer-events-none" />

              <div className="absolute bottom-6 left-6 md:left-10 right-20 space-y-3 z-20">
                <div className="flex items-center gap-2">
                  <span className="bg-[#E50914] text-white text-[11px] font-bold px-2 py-0.5 rounded tracking-wide uppercase shadow">
                    {titleData.type === 'movie' ? 'Película' : 'Serie'}
                  </span>
                  <span className="bg-black/60 text-gray-200 text-xs px-2 py-0.5 rounded border border-gray-600 backdrop-blur-sm">
                    HD / 4K • DirectPlay
                  </span>
                  {trailerUrl && isTrailerPlaying && (
                    <span className="bg-[#E50914]/80 text-white text-[10px] font-bold px-1.5 py-0.5 rounded uppercase tracking-wider backdrop-blur-sm">
                      Trailer
                    </span>
                  )}
                </div>

                <h2 className="font-display text-3xl md:text-5xl font-black text-white text-shadow-netflix tracking-wide">
                  {titleData.name}
                </h2>

                <div className="flex items-center gap-3 pt-1">
                  <button
                    onClick={() => {
                      onClose();
                      const firstEp = titleData.type === 'series' ? selectedSeason?.episodes?.[0] : null;
                      const resumeTime = firstEp && progressData[firstEp.id]
                        ? progressData[firstEp.id].current_seconds
                        : (progressData['main']?.current_seconds || 0);
                      onPlay(titleData, firstEp, resumeTime);
                    }}
                    className="flex items-center gap-2 bg-[#E50914] hover:bg-[#F40612] text-white font-bold px-6 py-2.5 rounded active:scale-95 transition-all text-sm md:text-base shadow-xl cursor-pointer"
                  >
                    <Play className="w-5 h-5 fill-white" />
                    Reproducir
                  </button>
                </div>
              </div>
            </div>

            {/* Metadatos y Sinopsis Completa */}
            <div className="p-6 md:p-10 space-y-6">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div className="md:col-span-2 space-y-4">
                  <div className="flex flex-wrap items-center gap-3 text-xs md:text-sm text-zinc-600 dark:text-zinc-300">
                    <span className="text-emerald-600 dark:text-emerald-400 font-bold">98% Coincidencia</span>
                    {titleData.release_year > 1900 && <span>{titleData.release_year}</span>}
                    <span className="border border-zinc-300 dark:border-zinc-700 px-1.5 py-0.5 rounded text-[11px]">
                      16+
                    </span>
                    {titleData.type === 'movie' && (
                      <span className="flex items-center gap-1">
                        <Clock className="w-3.5 h-3.5" />
                        {formatDuration(titleData.duration_seconds)}
                      </span>
                    )}
                    {titleData.type === 'series' && (
                      <span>{cleanedSeasons.length || 1} Temporada(s)</span>
                    )}
                  </div>

                  <p className="text-sm md:text-base text-zinc-700 dark:text-zinc-200 leading-relaxed">
                    {titleData.description}
                  </p>
                </div>

                {/* Ficha técnica lateral */}
                <div className="space-y-2 text-xs md:text-sm text-zinc-500 dark:text-zinc-400 border-l border-zinc-200 dark:border-zinc-800 pl-4">
                  <div>
                    <span className="text-zinc-400 dark:text-zinc-500">Género: </span>
                    <span className="text-zinc-800 dark:text-zinc-200 font-medium">{titleData.genre}</span>
                  </div>
                  <div>
                    <span className="text-zinc-400 dark:text-zinc-500">Disponibilidad: </span>
                    <span className="text-emerald-600 dark:text-emerald-400 font-medium">On-Net Local ISP</span>
                  </div>
                  <div>
                    <span className="text-zinc-400 dark:text-zinc-500">Audio: </span>
                    <span className="text-zinc-800 dark:text-zinc-200">Español Latino (Estéreo / 5.1)</span>
                  </div>
                </div>
              </div>

              {/* Selector de Temporadas y Lista de Episodios Estilo Netflix (Solo para Series) */}
              {titleData.type === 'series' && cleanedSeasons.length > 0 && (
                <div className="space-y-4 pt-6 border-t border-zinc-200 dark:border-zinc-800">
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <h3 className="text-lg md:text-xl font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                      <Tv className="w-5 h-5 text-[#E50914]" />
                      Episodios
                    </h3>

                    {/* Selector Desplegable de Temporadas Estilo Netflix (Punto 3) */}
                    {cleanedSeasons.length > 0 && (
                      <div className="flex items-center gap-2">
                        <select
                          value={selectedSeason?.id || selectedSeason?.season_number}
                          onChange={(e) => {
                            const season = cleanedSeasons.find(
                              (s) => s.id === parseInt(e.target.value) || s.season_number === parseInt(e.target.value)
                            );
                            setSelectedSeason(season);
                          }}
                          className="bg-zinc-200 dark:bg-zinc-800 text-zinc-900 dark:text-white font-bold border border-zinc-300 dark:border-zinc-700 text-sm rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-[#E50914] cursor-pointer shadow-sm transition-all"
                        >
                          {cleanedSeasons.map((s, idx) => (
                            <option key={s.id || idx} value={s.id || s.season_number}>
                              {s.title || `Temporada ${s.season_number || idx + 1}`}
                            </option>
                          ))}
                        </select>
                      </div>
                    )}
                  </div>

                  {/* Lista Vertical de Episodios Ordenados de la Temporada Seleccionada (Punto 3) */}
                  <div className="space-y-3">
                    {selectedSeason?.episodes?.map((ep) => {
                      const epProgress = progressData[ep.id] || progressData[`${ep.id}`];
                      const currentSeconds = epProgress?.current_seconds || 0;
                      const totalSeconds = ep.duration_seconds || epProgress?.duration_seconds || 0;
                      const percentWatched = totalSeconds > 0
                        ? Math.min(100, Math.round((currentSeconds / totalSeconds) * 100))
                        : 0;

                      return (
                        <div
                          key={ep.id}
                          onClick={() => {
                            onClose();
                            onPlay(titleData, ep, currentSeconds);
                          }}
                          className="group flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 p-3.5 rounded-xl bg-zinc-200/60 dark:bg-zinc-900/70 hover:bg-zinc-200 dark:hover:bg-zinc-800/90 border border-zinc-300/40 dark:border-zinc-800 cursor-pointer transition-all hover:scale-[1.01]"
                        >
                          <div className="flex items-center gap-4 flex-1">
                            {/* Número de capítulo en grande (Punto 3) */}
                            <span className="text-xl sm:text-2xl font-black text-zinc-400 dark:text-zinc-500 group-hover:text-[#E50914] w-8 text-center flex-shrink-0">
                              {ep.episode_number}
                            </span>

                            {/* Miniatura del episodio con barra de progreso individual */}
                            <div className="relative w-32 sm:w-40 aspect-video bg-zinc-300 dark:bg-neutral-800 rounded-lg overflow-hidden flex-shrink-0 shadow">
                              <img
                                src={ep.thumbnail_url || titleData.backdrop_url}
                                alt={ep.title}
                                className="w-full h-full object-cover group-hover:scale-105 transition-transform"
                                onError={(e) => {
                                  e.currentTarget.onerror = null;
                                  e.currentTarget.src = titleData.backdrop_url || '/api/media/poster?title=' + encodeURIComponent(titleData.name);
                                }}
                              />
                              <div className="absolute inset-0 bg-black/30 group-hover:bg-black/10 flex items-center justify-center transition-colors">
                                <div className="w-9 h-9 rounded-full bg-black/60 group-hover:bg-[#E50914] text-white flex items-center justify-center transition-all shadow-md group-hover:scale-110">
                                  <Play className="w-4 h-4 fill-white ml-0.5" />
                                </div>
                              </div>

                              {/* Barra de progreso individual del episodio */}
                              {percentWatched > 0 && (
                                <div className="absolute bottom-0 left-0 right-0 h-1.5 bg-black/60">
                                  <div
                                    className="h-full bg-[#E50914]"
                                    style={{ width: `${percentWatched}%` }}
                                  />
                                </div>
                              )}
                            </div>

                            {/* Información del episodio */}
                            <div className="space-y-1 flex-1">
                              <div className="flex items-center gap-2">
                                <h4 className="font-semibold text-sm text-zinc-900 dark:text-white group-hover:text-[#E50914] dark:group-hover:text-red-400 transition-colors">
                                  {ep.title}
                                </h4>
                                {currentSeconds > 60 && (
                                  <span className="text-[10px] bg-red-600/20 text-red-500 font-semibold px-1.5 py-0.5 rounded">
                                    Reanudar en {formatTime(currentSeconds)}
                                  </span>
                                )}
                              </div>
                              <p className="text-xs text-zinc-500 dark:text-zinc-400 line-clamp-2 max-w-lg leading-relaxed">
                                {ep.description || 'Sin descripción disponible para este episodio.'}
                              </p>
                            </div>
                          </div>

                          {/* Duración */}
                          <div className="text-xs text-zinc-500 dark:text-zinc-400 font-mono whitespace-nowrap self-end sm:self-center pr-2">
                            {formatDuration(ep.duration_seconds)}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default DetailModal;
