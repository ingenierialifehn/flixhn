import React, { useState, useEffect, useRef } from 'react';
import { Loader2, Film, Tv, X, DownloadCloud, Sparkles, CheckCircle2, AlertCircle } from 'lucide-react';
import api from '../api/axios';
import { getPlatformConfig } from './MovieCard';
import { resolvePosterUrl } from '../utils/media';

/**
 * Modal de Carga y Descarga en Segundo Plano On-Demand estilo Netflix.
 * El usuario ve una pantalla de carga estética de alta tecnología mientras el
 * servidor FlixHN descarga el contenido hacia el disco más vacío y lo prepara para reproducir.
 */
const OnDemandLoadingModal = ({ target, onReady, onCancel }) => {
  const [stage, setStage] = useState('connecting'); // connecting, downloading, finalizing, error
  const [progress, setProgress] = useState(8);
  const [speed, setSpeed] = useState('Iniciando enlace...');
  const [eta, setEta] = useState('Calculando...');
  const [errorMessage, setErrorMessage] = useState(null);
  const [taskId, setTaskId] = useState(null);
  const [titleId, setTitleId] = useState(null);
  const isCancelledRef = useRef(false);
  const pollTimerRef = useRef(null);

  const titleName = target?.name || target?.title || 'Contenido OTT';
  const providerRaw = target?.provider || target?.source_platform || 'netflix';
  const platform = getPlatformConfig(providerRaw);

  const rawBanner = target?.backdrop_url || target?.poster_url || target?.image;
  const bannerUrl = resolvePosterUrl(rawBanner);

  // Iniciar solicitud de descarga On-Demand
  useEffect(() => {
    isCancelledRef.current = false;
    let isMounted = true;

    const startDownload = async () => {
      try {
        setStage('connecting');
        setSpeed('Conectando con ' + platform.name + '...');

        const payload = {
          title: titleName,
          provider: platform.key,
          media_type: target?.type === 'series' ? 'series' : 'movie',
          poster_url: target?.poster_url || bannerUrl,
          backdrop_url: target?.backdrop_url || bannerUrl,
          overview: target?.description || target?.overview || '',
          year: target?.release_year || target?.year || new Date().getFullYear(),
          genre: target?.genre || 'Streaming',
        };

        const res = await api.post('/ott/on-demand-play', payload);
        if (!isMounted || isCancelledRef.current) return;

        if (res.data?.ready && res.data?.title) {
          setProgress(100);
          setStage('finalizing');
          setTimeout(() => {
            if (isMounted && !isCancelledRef.current) {
              onReady(res.data.title);
            }
          }, 400);
          return;
        }

        const tId = res.data?.task_id;
        const ttlId = res.data?.title_id;
        setTaskId(tId);
        setTitleId(ttlId);
        setStage('downloading');
        setProgress((prev) => Math.max(prev, res.data?.progress || 12));
        setSpeed(res.data?.speed || '38.4 MB/s');

        // Iniciar sondeo periódico de progreso
        pollStatus(tId, ttlId);
      } catch (err) {
        if (!isMounted || isCancelledRef.current) return;
        console.error('Error iniciando on-demand play:', err);
        setStage('error');
        setErrorMessage(
          err.response?.data?.message || 'No se pudo conectar con el motor de ingesta On-Net. Reintente en unos instantes.'
        );
      }
    };

    const pollStatus = (currentTaskId, currentTitleId) => {
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);

      pollTimerRef.current = setInterval(async () => {
        if (isCancelledRef.current) {
          clearInterval(pollTimerRef.current);
          return;
        }

        try {
          const queryParams = new URLSearchParams();
          if (currentTaskId) queryParams.append('task_id', currentTaskId);
          if (currentTitleId) queryParams.append('title_id', currentTitleId);

          const res = await api.get(`/ott/on-demand-status?${queryParams.toString()}`);
          if (isCancelledRef.current) return;

          const data = res.data;

          if (data?.ready && data?.title) {
            clearInterval(pollTimerRef.current);
            setProgress(100);
            setStage('finalizing');
            setSpeed('Verificación física completada');
            setTimeout(() => {
              if (!isCancelledRef.current) {
                onReady(data.title);
              }
            }, 500);
            return;
          }

          if (data?.status === 'failed') {
            clearInterval(pollTimerRef.current);
            setStage('error');
            setErrorMessage(data?.error || 'Falló la descarga del archivo en el servidor.');
            return;
          }

          if (data?.progress) {
            setProgress((prev) => Math.min(99, Math.max(prev + 1, data.progress)));
          } else {
            setProgress((prev) => Math.min(95, prev + Math.floor(Math.random() * 4) + 1));
          }

          if (data?.speed) setSpeed(data.speed);
          if (data?.eta) setEta(data.eta);
        } catch (err) {
          // Continuar reintentando suavemente en caso de micro-latencia de red
          setProgress((prev) => Math.min(90, prev + 2));
        }
      }, 1200);
    };

    startDownload();

    return () => {
      isMounted = false;
      isCancelledRef.current = true;
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);
    };
  }, [titleName, platform.key, platform.name, bannerUrl, onReady, target]);

  const handleCancel = () => {
    isCancelledRef.current = true;
    if (pollTimerRef.current) clearInterval(pollTimerRef.current);
    if (onCancel) onCancel();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/85 backdrop-blur-md animate-fadeIn select-none">
      {/* Fondo panorámico cinematográfico atenuado */}
      <div className="absolute inset-0 overflow-hidden pointer-events-none opacity-25">
        <img
          src={bannerUrl}
          alt={titleName}
          className="w-full h-full object-cover object-center filter blur-xl scale-110"
        />
        <div className="absolute inset-0 bg-gradient-to-t from-black via-black/80 to-black/60" />
      </div>

      {/* Tarjeta Central de Carga */}
      <div className="relative w-full max-w-lg bg-[#141414] rounded-2xl border border-zinc-800 shadow-2xl p-6 sm:p-8 flex flex-col items-center text-center space-y-6 overflow-hidden">
        {/* Glow Superior con color de la plataforma */}
        <div
          className="absolute -top-24 left-1/2 -translate-x-1/2 w-64 h-64 rounded-full blur-3xl opacity-30 pointer-events-none"
          style={{ backgroundColor: platform.color || '#E50914' }}
        />

        {/* Botón de Cierre / Cancelar en la esquina */}
        <button
          onClick={handleCancel}
          className="absolute top-4 right-4 text-zinc-400 hover:text-white p-1.5 rounded-full hover:bg-zinc-800/80 transition-colors cursor-pointer"
          title="Cancelar descarga"
          type="button"
        >
          <X className="w-5 h-5" />
        </button>

        {/* Indicador Visual Central: Spinner Animado con Logo / Ícono */}
        <div className="relative w-24 h-24 flex items-center justify-center mt-2">
          {/* Anillo exterior rotatorio */}
          <div
            className="absolute inset-0 rounded-full border-4 border-t-transparent animate-spin"
            style={{
              borderColor: `${platform.color || '#E50914'} transparent transparent transparent`,
              animationDuration: '1.2s',
            }}
          />
          {/* Anillo interior suave */}
          <div className="absolute inset-2 rounded-full border-2 border-white/10" />

          {/* Ícono central según estado */}
          {stage === 'finalizing' ? (
            <CheckCircle2 className="w-10 h-10 text-emerald-400 animate-bounce" />
          ) : stage === 'error' ? (
            <AlertCircle className="w-10 h-10 text-red-500" />
          ) : (
            <DownloadCloud
              className="w-9 h-9 animate-pulse"
              style={{ color: platform.color || '#E50914' }}
            />
          )}
        </div>

        {/* Badge y Título */}
        <div className="space-y-2 w-full px-2">
          <div className="flex items-center justify-center gap-2">
            <span
              className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded text-[10px] font-black uppercase tracking-wider ${platform.badgeBg} ${platform.badgeBorder} text-white shadow`}
            >
              <span>{platform.name}</span>
            </span>
            <span className="text-[10px] font-bold uppercase tracking-wider text-zinc-400 bg-zinc-800/80 px-2 py-0.5 rounded border border-white/5">
              {target?.type === 'series' ? 'Serie Completa' : 'Película HD'}
            </span>
          </div>

          <h2 className="text-xl sm:text-2xl font-black text-white tracking-tight leading-tight line-clamp-2">
            {titleName}
          </h2>

          <p className="text-xs sm:text-sm text-zinc-400 font-medium">
            {stage === 'connecting' && `Conectando con ${platform.name} para transmisión local On-Net...`}
            {stage === 'downloading' && 'Cargando y procesando contenido en el servidor local FlixHN...'}
            {stage === 'finalizing' && '¡Listo! Preparando reproductor de video...'}
            {stage === 'error' && 'Error al procesar el contenido.'}
          </p>
        </div>

        {/* Barra de Progreso y Métricas */}
        {stage !== 'error' && (
          <div className="w-full space-y-3 bg-zinc-900/80 rounded-xl p-4 border border-zinc-800/80">
            {/* Cabecera del Progreso */}
            <div className="flex items-center justify-between text-xs">
              <span className="text-zinc-400 font-semibold flex items-center gap-1.5">
                <Sparkles className="w-3.5 h-3.5 text-amber-400" />
                Cargando al disco más rápido
              </span>
              <span className="font-mono font-black text-white text-sm">
                {Math.round(progress)}%
              </span>
            </div>

            {/* Barra */}
            <div className="w-full h-2.5 bg-zinc-800 rounded-full overflow-hidden p-0.5 ring-1 ring-white/5">
              <div
                className="h-full rounded-full transition-all duration-300 ease-out bg-gradient-to-r from-red-600 via-[#E50914] to-red-500 shadow-[0_0_12px_rgba(229,9,20,0.6)]"
                style={{
                  width: `${progress}%`,
                  backgroundColor: platform.color || '#E50914',
                }}
              />
            </div>

            {/* Telemetría: Velocidad, Destino y ETA */}
            <div className="flex items-center justify-between text-[11px] text-zinc-400 pt-1 border-t border-zinc-800/60 font-mono">
              <div className="flex items-center gap-1">
                <span className="text-zinc-500">Velocidad:</span>
                <span className="text-emerald-400 font-bold">{speed}</span>
              </div>
              <div className="flex items-center gap-1">
                <span className="text-zinc-500">Red:</span>
                <span className="text-zinc-300 font-semibold">ISP On-Net 10 Gbps</span>
              </div>
            </div>
          </div>
        )}

        {/* Mensaje de Error si aplica */}
        {stage === 'error' && (
          <div className="w-full p-3.5 bg-red-950/40 border border-red-800/60 rounded-xl text-xs text-red-300 text-left">
            <p className="font-bold text-red-200 mb-1">No se pudo completar la carga:</p>
            <p>{errorMessage}</p>
          </div>
        )}

        {/* Acciones Inferiores */}
        <div className="flex items-center justify-center gap-3 w-full pt-1">
          <button
            type="button"
            onClick={handleCancel}
            className="w-full sm:w-auto px-6 py-2.5 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-xs sm:text-sm font-bold transition-colors cursor-pointer border border-zinc-700"
          >
            {stage === 'error' ? 'Cerrar' : 'Cancelar'}
          </button>
        </div>
      </div>
    </div>
  );
};

export default OnDemandLoadingModal;

