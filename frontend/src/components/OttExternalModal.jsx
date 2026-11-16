import React, { useState } from 'react';
import { ExternalLink, Globe, ArrowLeft, ShieldCheck, CheckCircle2 } from 'lucide-react';
import { resolvePosterUrl } from '../utils/media';

/**
 * Modal cinemático para contenidos OTT externos (Netflix, Disney+, Max, Prime Video).
 * Proporciona interoperabilidad oficial respetando los términos de distribución y DRM.
 */
const OttExternalModal = ({ title, playbackInfo, onClose }) => {
  const [appLaunched, setAppLaunched] = useState(false);

  if (!title && !playbackInfo) return null;

  const itemTitle = playbackInfo?.title || title?.name || title?.title || 'Contenido OTT';
  const providerKey = (playbackInfo?.provider || title?.provider || 'netflix').toLowerCase();
  const providerName = playbackInfo?.provider_name || title?.provider_name || 'Plataforma Oficial';
  const brandColor = playbackInfo?.brand_color || (
    providerKey === 'netflix' ? '#E50914' :
    providerKey.includes('disney') ? '#0063E5' :
    (providerKey.includes('max') || providerKey.includes('hbo')) ? '#5822b4' :
    (providerKey.includes('prime') || providerKey.includes('amazon')) ? '#00A8E1' :
    '#E50914'
  );

  const posterUrl = resolvePosterUrl(title?.poster_url || title?.backdrop_url || playbackInfo?.poster_url);
  const appUrl = playbackInfo?.app_url || `${providerKey}://`;
  const webUrl = playbackInfo?.web_url || `https://www.${providerKey}.com`;
  const disclaimer = playbackInfo?.disclaimer ||
    `Este título se distribuye bajo interoperabilidad y licencia oficial de ${providerName}. Por directrices técnicas de DRM y derechos de autor del titular, la reproducción debe ejecutarse en la aplicación oficial o portal autorizado.`;

  const handleOpenApp = () => {
    setAppLaunched(true);
    // Intentar abrir el URI scheme nativo
    window.location.href = appUrl;

    // Timeout de fallback: si la app no está instalada, ofrecer abrir el navegador web
    setTimeout(() => {
      setAppLaunched(false);
    }, 3500);
  };

  const handleOpenWeb = () => {
    window.open(webUrl, '_blank', 'noopener,noreferrer');
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-black/90 backdrop-blur-xl animate-fadeIn">
      {/* Fondo ambiental difuminado con brillo de marca */}
      <div
        className="absolute inset-0 opacity-25 pointer-events-none transition-opacity duration-700"
        style={{
          background: `radial-gradient(circle at center, ${brandColor}40 0%, transparent 70%)`
        }}
      />

      {/* Contenedor principal del modal */}
      <div className="relative w-full max-w-xl bg-zinc-950 border border-zinc-800/90 rounded-2xl shadow-2xl overflow-hidden flex flex-col z-10 animate-scaleUp">
        {/* Cabecera con degradado */}
        <div className="relative h-44 sm:h-52 w-full overflow-hidden bg-zinc-900 border-b border-zinc-800/80">
          <img
            src={posterUrl}
            alt={itemTitle}
            className="w-full h-full object-cover opacity-40 blur-sm scale-105"
            onError={(e) => {
              e.target.onerror = null;
              e.target.src = `/api/media/poster?title=${encodeURIComponent(itemTitle)}`;
            }}
          />
          <div className="absolute inset-0 bg-gradient-to-t from-zinc-950 via-zinc-950/60 to-transparent" />

          {/* Botón de cierre en esquina superior */}
          <button
            onClick={onClose}
            className="absolute top-4 left-4 z-20 flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-black/70 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-700/60 text-xs font-semibold backdrop-blur-md transition-all cursor-pointer"
            type="button"
          >
            <ArrowLeft className="w-4 h-4" />
            <span>Volver a FlixHN</span>
          </button>

          {/* Badge superior de plataforma oficial */}
          <div className="absolute top-4 right-4 z-20">
            <span
              className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider text-white shadow-lg backdrop-blur-md border border-white/20"
              style={{ backgroundColor: brandColor }}
            >
              <ShieldCheck className="w-3.5 h-3.5 text-white" />
              {providerName}
            </span>
          </div>

          {/* Información del título en la cabecera */}
          <div className="absolute bottom-4 left-6 right-6">
            <h2 className="text-xl sm:text-2xl font-black text-white leading-tight drop-shadow-md truncate">
              {itemTitle}
            </h2>
            <div className="flex items-center gap-2 mt-1 text-xs text-zinc-400 font-medium">
              <span>{title?.release_year || 'Estreno'}</span>
              <span>•</span>
              <span className="capitalize">{title?.genre || 'Catálogo Oficial'}</span>
              <span>•</span>
              <span className="text-emerald-400 font-semibold flex items-center gap-1">
                <CheckCircle2 className="w-3.5 h-3.5" /> Enlace Oficial Autorizado
              </span>
            </div>
          </div>
        </div>

        {/* Cuerpo del modal */}
        <div className="p-6 space-y-5">
          {/* Explicación de interoperabilidad y DRM */}
          <div className="bg-zinc-900/80 border border-zinc-800 rounded-xl p-4 text-xs text-zinc-300 leading-relaxed flex items-start gap-3 shadow-inner">
            <div
              className="w-2.5 h-2.5 rounded-full shrink-0 mt-1.5"
              style={{ backgroundColor: brandColor }}
            />
            <div>
              <p className="font-semibold text-white mb-1">
                Interoperabilidad y Distribución Autorizada
              </p>
              <p className="text-zinc-400">
                {disclaimer}
              </p>
            </div>
          </div>

          {/* Acciones principales */}
          <div className="space-y-3 pt-2">
            {/* Opción 1: Abrir en aplicación oficial (Deep Link) */}
            <button
              onClick={handleOpenApp}
              className="w-full flex items-center justify-center gap-2 py-3.5 px-4 rounded-xl text-sm font-bold text-white transition-all transform active:scale-98 shadow-xl cursor-pointer hover:brightness-110"
              style={{ backgroundColor: brandColor }}
              type="button"
            >
              <ExternalLink className="w-4 h-4" />
              <span>
                {appLaunched ? `Abriendo en ${providerName}...` : `Abrir en App Oficial de ${providerName}`}
              </span>
            </button>

            {/* Opción 2: Abrir en navegador web oficial */}
            <button
              onClick={handleOpenWeb}
              className="w-full flex items-center justify-center gap-2 py-3 px-4 rounded-xl text-sm font-bold bg-zinc-900 hover:bg-zinc-800 text-zinc-200 border border-zinc-700/80 hover:border-zinc-600 transition-all cursor-pointer"
              type="button"
            >
              <Globe className="w-4 h-4 text-zinc-400" />
              <span>Ver en Portal Web de {providerName}</span>
            </button>
          </div>

          {/* Pie informativo */}
          <div className="text-center pt-2">
            <p className="text-[11px] text-zinc-500">
              FlixHN ISP Network • Conexión cifrada directa con los servidores oficiales de distribución.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default OttExternalModal;
