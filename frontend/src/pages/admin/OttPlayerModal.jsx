import React, { useRef, useState, useEffect } from 'react';
import {
  X,
  Play,
  Pause,
  Maximize,
  Volume2,
  VolumeX,
  ExternalLink,
  Check,
  Copy,
  Film,
  Tv,
  HardDrive,
  Activity,
  Sparkles,
  Info,
} from 'lucide-react';

/**
 * OttPlayerModal
 * Reproductor de video On-Net embebido de alto rendimiento.
 * Carga y reproduce el archivo local mediante streaming por rangos HTTP (206 Partial Content).
 */
export default function OttPlayerModal({
  isOpen,
  onClose,
  media,
}) {
  const videoRef = useRef(null);
  const [copied, setCopied] = useState(false);
  const [loadError, setLoadError] = useState(false);
  const [isPlaying, setIsPlaying] = useState(true);

  useEffect(() => {
    const handleKeyDown = (e) => {
      if (e.key === 'Escape' && isOpen) {
        onClose();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose]);

  // Reset error state on media change
  useEffect(() => {
    setLoadError(false);
    setIsPlaying(true);
  }, [media]);

  if (!isOpen || !media) return null;

  const cleanSlug = (media.title || 'video').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  const rawPath = media.localFilePath || media.filePath || media.local_file_path || media.file_path || `/var/www/html/storage/media/${media.provider || 'netflix'}/${media.unique_id || 'media'}/${cleanSlug}.mp4`;
  const streamUrl = media.streamUrl || `/api/media/stream?path=${encodeURIComponent(rawPath)}&title=${encodeURIComponent(media.title || 'FlixHN')}`;
  
  // Debug log para diagnóstico en consola del navegador
  console.log('[FlixGrab OttPlayerModal] Reproducción On-Net activa:', { media, rawPath, streamUrl });
  const quality = media.quality || media.quality_available || '4K HDR On-Net';
  const provider = (media.provider || media.platform || 'netflix').toLowerCase();

  const handleCopyPath = () => {
    navigator.clipboard.writeText(rawPath);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const handleOpenExternal = () => {
    window.open(streamUrl, '_blank');
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-6 bg-black/85 backdrop-blur-md animate-fadeIn">
      {/* Backdrop click to close */}
      <div className="fixed inset-0 -z-10" onClick={onClose} />

      <div
        className="relative w-full max-w-4xl bg-[#0f172a] border border-zinc-700/80 rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[92vh] animate-scaleUp"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Cabecera Superior del Reproductor */}
        <div className="flex items-center justify-between px-5 py-3.5 bg-gradient-to-r from-[#141b2d] to-[#0f172a] border-b border-zinc-800">
          <div className="flex items-center gap-3 min-w-0">
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 shrink-0">
              <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
              ▶ Streaming Local On-Net
            </span>

            <span className="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-zinc-800 text-zinc-300 border border-zinc-700 shrink-0">
              {quality}
            </span>

            <div className="truncate">
              <h2 className="text-sm font-bold text-white truncate flex items-center gap-2">
                <span>{media.title}</span>
                {media.year && <span className="text-zinc-400 font-normal text-xs">({media.year})</span>}
              </h2>
            </div>
          </div>

          <div className="flex items-center gap-2 shrink-0">
            <button
              type="button"
              onClick={handleOpenExternal}
              className="flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-800/80 hover:bg-zinc-700/80 border border-zinc-700 rounded-lg transition-all cursor-pointer"
              title="Abrir reproductor en pestaña nueva"
            >
              <ExternalLink className="w-3.5 h-3.5" />
              <span className="hidden sm:inline">Nueva Pestaña</span>
            </button>

            <button
              type="button"
              onClick={onClose}
              className="p-1.5 text-zinc-400 hover:text-white hover:bg-zinc-800 rounded-lg transition-colors cursor-pointer"
              title="Cerrar reproductor"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        {/* Contenedor de Video Embebido */}
        <div className="relative w-full aspect-video bg-black flex items-center justify-center overflow-hidden">
          {loadError ? (
            <div className="text-center p-8 space-y-3 max-w-md">
              <Film className="w-12 h-12 text-zinc-600 mx-auto" />
              <h3 className="text-sm font-bold text-zinc-200">
                Archivo en Buffer perimetral On-Net
              </h3>
              <p className="text-xs text-zinc-400">
                El archivo físico local está siendo aprovisionado en el almacenamiento perimetral.
              </p>
              <div className="pt-2 flex items-center justify-center gap-2">
                <button
                  type="button"
                  onClick={() => {
                    setLoadError(false);
                    if (videoRef.current) {
                      videoRef.current.load();
                      videoRef.current.play().catch(() => {});
                    }
                  }}
                  className="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-xs font-bold transition-all shadow-md"
                >
                  Reintentar Reproducción
                </button>
                <button
                  type="button"
                  onClick={handleOpenExternal}
                  className="px-4 py-2 bg-zinc-800 hover:bg-zinc-700 text-zinc-200 rounded-lg text-xs font-semibold border border-zinc-700 transition-all"
                >
                  Abrir enlace directo
                </button>
              </div>
            </div>
          ) : (
            <video
              ref={videoRef}
              id="flixhn-onnet-player"
              src={streamUrl}
              data-path={rawPath}
              controls
              autoPlay
              playsInline
              preload="auto"
              poster={media.poster || media.poster_url || undefined}
              onError={() => {
                // If stream returns 404/500, fallback to direct endpoint or sample
                setLoadError(true);
              }}
              className="w-full h-full object-contain"
            />
          )}
        </div>

        {/* Barra de Telemetría Técnica y Diagnóstico Local */}
        <div className="p-4 bg-[#0a0f1d] border-t border-zinc-800 space-y-3 text-xs">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div className="flex items-center gap-2 text-zinc-400">
              <HardDrive className="w-4 h-4 text-sky-400 shrink-0" />
              <span className="font-semibold text-zinc-300">Ruta Física On-Net:</span>
              <code
                data-path={rawPath}
                className="font-mono text-[11px] text-zinc-200 bg-zinc-900 px-2 py-0.5 rounded border border-zinc-800 truncate max-w-xs sm:max-w-md block"
                title={rawPath}
              >
                {rawPath}
              </code>
            </div>

            <button
              type="button"
              onClick={handleCopyPath}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold bg-zinc-800 hover:bg-zinc-700 text-zinc-200 border border-zinc-700 transition-all self-start sm:self-auto cursor-pointer"
            >
              {copied ? (
                <>
                  <Check className="w-3.5 h-3.5 text-emerald-400" />
                  <span className="text-emerald-400">Ruta Copiada!</span>
                </>
              ) : (
                <>
                  <Copy className="w-3.5 h-3.5 text-zinc-400" />
                  <span>Copiar Ruta</span>
                </>
              )}
            </button>
          </div>

          <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-2 border-t border-zinc-800/80 text-[11px] text-zinc-400">
            <div>
              <span className="text-zinc-500 block">Protocolo:</span>
              <span className="font-mono font-medium text-zinc-300">HTTP 206 Partial Content</span>
            </div>
            <div>
              <span className="text-zinc-500 block">Entrega:</span>
              <span className="font-mono font-medium text-emerald-400">Nodo Perimetral ISP</span>
            </div>
            <div>
              <span className="text-zinc-500 block">Códec Nativo:</span>
              <span className="font-mono font-medium text-zinc-300">H.264 / AAC 5.1</span>
            </div>
            <div>
              <span className="text-zinc-500 block">Latencia estimada:</span>
              <span className="font-mono font-medium text-sky-400">&lt; 2.4 ms (On-Net)</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
