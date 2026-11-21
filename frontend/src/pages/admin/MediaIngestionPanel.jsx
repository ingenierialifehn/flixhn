import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import api from '../../services/api';
import { notifyCatalogUpdated } from '../../utils/catalogEvents';
import {
  UploadCloud,
  Film,
  Tv,
  HardDrive,
  RefreshCw,
  CheckCircle2,
  AlertCircle,
  Clock,
  X,
  Trash2,
  Ban,
  Search,
  Terminal,
  Copy,
  Link2,
  FolderOpen,
  Sparkles,
  Activity,
  Loader2,
  Check,
} from 'lucide-react';

const STATUS_BADGE = {
  pending: {
    label: 'En Cola',
    classes: 'bg-amber-500/15 text-amber-300 border-amber-500/30',
  },
  downloading: {
    label: 'Descargando',
    classes: 'bg-blue-500/15 text-blue-300 border-blue-500/30',
  },
  processing: {
    label: 'Empaquetando MP4',
    classes: 'bg-purple-500/15 text-purple-300 border-purple-500/30',
  },
  completed: {
    label: 'Catalogado',
    classes: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
  },
  failed: {
    label: 'Error',
    classes: 'bg-red-500/15 text-red-300 border-red-500/30',
  },
  cancelled: {
    label: 'Cancelado',
    classes: 'bg-zinc-700/40 text-zinc-400 border-zinc-700',
  },
};

const SOURCE_TYPE_LABELS = {
  hls_m3u8: { label: 'HLS (.m3u8)', classes: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' },
  direct_http: { label: 'HTTP Directo', classes: 'bg-blue-500/15 text-blue-300 border-blue-500/30' },
  ott_feed: { label: 'Feed OTT / On-Net', classes: 'bg-red-500/15 text-red-300 border-red-500/30' },
  web_standard: { label: 'Web Estándar (yt-dlp)', classes: 'bg-purple-500/15 text-purple-300 border-purple-500/30' },
};

const formatFileSize = (bytes) => {
  if (!bytes || bytes <= 0) return '—';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  return `${(bytes / Math.pow(1024, i)).toFixed(i >= 2 ? 1 : 0)} ${units[i]}`;
};

export default function MediaIngestionPanel() {
  // Datos del backend
  const [tasks, setTasks] = useState([]);
  const [summary, setSummary] = useState({ active: 0, completed: 0, failed: 0, total: 0 });
  const [disks, setDisks] = useState([]);
  const [tools, setTools] = useState({});
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  // Filtros de la tabla
  const [statusFilter, setStatusFilter] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');

  // Formulario de ingesta
  const [contentType, setContentType] = useState('movie');
  const [title, setTitle] = useState('');
  const [releaseYear, setReleaseYear] = useState('');
  const [imdbId, setImdbId] = useState('');
  const [overview, setOverview] = useState('');
  const [seasonNumber, setSeasonNumber] = useState('1');
  const [episodeNumber, setEpisodeNumber] = useState('1');
  const [episodeTitle, setEpisodeTitle] = useState('');
  const [targetDisk, setTargetDisk] = useState('');
  const [targetSubfolder, setTargetSubfolder] = useState('');
  const [sourceUrl, setSourceUrl] = useState('');
  const [quality, setQuality] = useState('1080p');

  // Estados de interacción
  const [submitting, setSubmitting] = useState(false);
  const [busyTaskId, setBusyTaskId] = useState(null);
  const [formError, setFormError] = useState('');
  const [feedback, setFeedback] = useState(null);
  const [selectedLogTask, setSelectedLogTask] = useState(null);
  const [copiedId, setCopiedId] = useState(null);

  const completedIdsRef = useRef(new Set());

  const showToast = useCallback((type, message) => {
    setFeedback({ type, message });
    setTimeout(() => {
      setFeedback((prev) => (prev?.message === message ? null : prev));
    }, 4500);
  }, []);

  const loadStatus = useCallback(async (silent = false) => {
    if (!silent) setRefreshing(true);
    try {
      const res = await api.get('/admin/ingestion/status');
      const data = res?.data || {};
      const nextTasks = Array.isArray(data.tasks) ? data.tasks : [];

      // Detectar nuevas tareas recién completadas para refrescar el catálogo automáticamente
      let newlyCompleted = false;
      nextTasks.forEach((t) => {
        if (t.status === 'completed' && !completedIdsRef.current.has(t.id)) {
          if (completedIdsRef.current.size > 0 || !loading) {
            newlyCompleted = true;
          }
          completedIdsRef.current.add(t.id);
        }
      });
      if (newlyCompleted) {
        notifyCatalogUpdated({ action: 'media_ingestion_completed' });
      }

      setTasks(nextTasks);
      if (data.summary) setSummary(data.summary);
      if (Array.isArray(data.disks)) {
        setDisks(data.disks);
        setTargetDisk((prev) => {
          if (prev && data.disks.some((d) => d.path === prev)) return prev;
          const firstWritable = data.disks.find((d) => d.mounted && d.writable);
          const firstMounted = data.disks.find((d) => d.mounted);
          return firstWritable?.path || firstMounted?.path || data.disks[0]?.path || '/mnt/media_library/Disk2';
        });
      }
      if (data.tools) setTools(data.tools);

      // Mantener sincronizado el modal de logs si está abierto
      setSelectedLogTask((current) => {
        if (!current) return null;
        return nextTasks.find((t) => t.id === current.id) || current;
      });
    } catch (err) {
      if (!silent) {
        showToast('error', err.response?.data?.message || 'Error consultando el estado del módulo de ingesta.');
      }
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [loading, showToast]);

  useEffect(() => {
    loadStatus(false);
  }, [loadStatus]);

  // Polling reactivo en tiempo real: 2.5s si hay tareas activas, 8s en reposo
  useEffect(() => {
    const hasActive = tasks.some((t) => ['pending', 'downloading', 'processing'].includes(t.status));
    const timer = setInterval(() => {
      loadStatus(true);
    }, hasActive ? 2500 : 8000);
    return () => clearInterval(timer);
  }, [tasks, loadStatus]);

  // Detección visual del tipo de enlace mientras el usuario escribe
  const detectedSourceBadge = useMemo(() => {
    const u = sourceUrl.trim().toLowerCase();
    if (!u) return null;
    if (u.includes('.m3u8') || u.includes('/hls/')) return SOURCE_TYPE_LABELS.hls_m3u8;
    if (/\.(mp4|mkv|m4v|mov|webm|ts|avi)(\?|$)/i.test(u)) return SOURCE_TYPE_LABELS.direct_http;
    if (u.includes('/emby/') || u.includes('/api/ott') || u.includes('/api/stream')) return SOURCE_TYPE_LABELS.ott_feed;
    return SOURCE_TYPE_LABELS.web_standard;
  }, [sourceUrl]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setFormError('');

    const trimmedTitle = title.trim();
    const trimmedUrl = sourceUrl.trim();
    const trimmedImdb = imdbId.trim();

    if (!trimmedTitle) {
      setFormError('Ingrese el título de la película o serie.');
      return;
    }
    if (!trimmedUrl || !/^https?:\/\//i.test(trimmedUrl)) {
      setFormError('Ingrese una URL HTTP o HTTPS válida (MP4 directo, manifiesto .m3u8 o fuente web).');
      return;
    }
    if (trimmedImdb && !/^tt\d{5,12}$/i.test(trimmedImdb)) {
      setFormError('El ID de IMDb debe seguir el formato estándar (ejemplo: tt1375666).');
      return;
    }
    if (!targetDisk) {
      setFormError('Seleccione un disco de destino válido.');
      return;
    }

    setSubmitting(true);
    try {
      const payload = {
        content_type: contentType,
        title: trimmedTitle,
        source_url: trimmedUrl,
        target_disk: targetDisk,
        target_subfolder: targetSubfolder.trim() || undefined,
        imdb_id: trimmedImdb || undefined,
        release_year: releaseYear ? parseInt(releaseYear, 10) : undefined,
        overview: overview.trim() || undefined,
        quality,
      };

      if (contentType === 'series') {
        payload.season_number = seasonNumber !== '' ? parseInt(seasonNumber, 10) : 1;
        payload.episode_number = episodeNumber !== '' ? parseInt(episodeNumber, 10) : 1;
        if (episodeTitle.trim()) {
          payload.episode_title = episodeTitle.trim();
        }
      }

      const res = await api.post('/admin/ingestion/enqueue', payload);
      if (res.data?.success || res.data?.status === 'success') {
        showToast('success', res.data.message || `Ingesta iniciada para "${trimmedTitle}".`);
        setTitle('');
        setSourceUrl('');
        setImdbId('');
        setReleaseYear('');
        setOverview('');
        setEpisodeTitle('');
        if (contentType === 'series') {
          setEpisodeNumber((prev) => String((parseInt(prev, 10) || 1) + 1));
        }
        await loadStatus(false);
      } else {
        setFormError(res.data?.message || 'No fue posible iniciar la ingesta.');
      }
    } catch (err) {
      const serverErrors = err.response?.data?.errors;
      if (serverErrors && typeof serverErrors === 'object') {
        const firstMsg = Object.values(serverErrors).flat()[0];
        setFormError(firstMsg || 'Verifique los campos del formulario.');
      } else {
        setFormError(err.response?.data?.message || 'Error conectando con el endpoint /api/admin/ingestion/enqueue.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const handleCancelOrDelete = async (task) => {
    const isActive = ['pending', 'downloading', 'processing'].includes(task.status);
    setBusyTaskId(task.id);
    try {
      const res = await api.delete(`/admin/ingestion/${task.id}`);
      if (res.data?.success || res.data?.status === 'success') {
        showToast('info', res.data.message || (isActive ? 'Tarea cancelada.' : 'Registro eliminado.'));
        await loadStatus(true);
      }
    } catch (err) {
      showToast('error', err.response?.data?.message || 'No fue posible completar la acción sobre la tarea.');
    } finally {
      setBusyTaskId(null);
    }
  };

  const handleCopyPath = (task) => {
    if (!task.output_file || !navigator?.clipboard) return;
    navigator.clipboard.writeText(task.output_file);
    setCopiedId(task.id);
    setTimeout(() => setCopiedId((prev) => (prev === task.id ? null : prev)), 1800);
  };

  const filteredTasks = useMemo(() => {
    return tasks.filter((t) => {
      if (statusFilter === 'active' && !['pending', 'downloading', 'processing'].includes(t.status)) {
        return false;
      }
      if (statusFilter === 'completed' && t.status !== 'completed') {
        return false;
      }
      if (statusFilter === 'failed' && !['failed', 'cancelled'].includes(t.status)) {
        return false;
      }
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase();
        return (
          t.title?.toLowerCase().includes(q) ||
          t.source_url?.toLowerCase().includes(q) ||
          t.output_file?.toLowerCase().includes(q) ||
          t.imdb_id?.toLowerCase().includes(q)
        );
      }
      return true;
    });
  }, [tasks, statusFilter, searchQuery]);

  const mountedDisksCount = useMemo(
    () => disks.filter((d) => d.mounted && d.writable).length,
    [disks]
  );

  return (
    <div className="space-y-6 text-zinc-100">
      {/* Toast de feedback */}
      {feedback && (
        <div
          className={`fixed top-20 right-6 z-50 flex items-center gap-3 px-4 py-3 rounded-xl border shadow-2xl backdrop-blur-md ${
            feedback.type === 'success'
              ? 'bg-emerald-950/90 border-emerald-500/40 text-emerald-200'
              : feedback.type === 'error'
              ? 'bg-red-950/90 border-red-500/40 text-red-200'
              : 'bg-zinc-900/95 border-zinc-700 text-zinc-200'
          }`}
        >
          {feedback.type === 'success' && <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0" />}
          {feedback.type === 'error' && <AlertCircle className="w-4 h-4 text-red-400 shrink-0" />}
          {feedback.type === 'info' && <Activity className="w-4 h-4 text-blue-400 shrink-0" />}
          <span className="text-xs font-medium">{feedback.message}</span>
          <button
            type="button"
            onClick={() => setFeedback(null)}
            className="p-0.5 text-zinc-400 hover:text-white rounded cursor-pointer"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        </div>
      )}

      {/* 1. Cabecera del Módulo */}
      <div className="bg-[#181818] border border-zinc-800 rounded-xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div className="flex items-start gap-3.5">
          <div className="p-2.5 rounded-xl bg-red-600/15 text-[#E50914] border border-red-500/30 shrink-0">
            <UploadCloud className="w-6 h-6" />
          </div>
          <div>
            <div className="flex items-center gap-2 flex-wrap">
              <h1 className="text-lg md:text-xl font-black text-white tracking-tight">
                Ingesta / Importador Multimedia
              </h1>
              <span className="text-[10px] font-mono uppercase px-2 py-0.5 rounded bg-zinc-900 text-zinc-300 border border-zinc-800">
                H.264 + AAC MP4
              </span>
            </div>
            <p className="text-xs text-zinc-400 mt-1">
              Importación directa desde enlaces HTTP/MP4, manifiestos HLS (.m3u8), fuentes web y feeds OTT hacia los discos locales <code className="text-zinc-300">/mnt/media_library/Disk2..Disk7</code> con catalogación automática.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2 flex-wrap">
          <div className="flex items-center gap-1.5 text-[11px] font-mono bg-zinc-900 border border-zinc-800 px-2.5 py-1.5 rounded-lg">
            <span className={`w-2 h-2 rounded-full ${tools.ffmpeg ? 'bg-emerald-500' : 'bg-amber-500'}`} />
            <span className="text-zinc-400">ffmpeg</span>
            <span className="text-zinc-700">•</span>
            <span className={`w-2 h-2 rounded-full ${tools['yt-dlp'] ? 'bg-emerald-500' : 'bg-zinc-600'}`} />
            <span className="text-zinc-400">yt-dlp</span>
            <span className="text-zinc-700">•</span>
            <span className={`w-2 h-2 rounded-full ${tools.aria2c || tools.curl ? 'bg-emerald-500' : 'bg-zinc-600'}`} />
            <span className="text-zinc-400">{tools.aria2c ? 'aria2c' : 'curl'}</span>
          </div>
          <button
            type="button"
            onClick={() => loadStatus(false)}
            disabled={refreshing}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 text-zinc-300 hover:text-white border border-zinc-800 text-xs font-medium transition-colors cursor-pointer disabled:opacity-50"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${refreshing ? 'animate-spin text-[#E50914]' : ''}`} />
            <span>Refrescar</span>
          </button>
        </div>
      </div>

      {/* 2. KPI Cards (Mismo estilo que AdminDashboardView) */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-[#181818] border border-zinc-800 rounded-lg p-4 flex items-center justify-between">
          <div>
            <p className="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">EN PROGRESO</p>
            <p className="text-2xl font-black text-white mt-1">{summary.active}</p>
          </div>
          <div className="p-2.5 rounded-lg bg-blue-500/10 text-blue-400">
            <Activity className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-[#181818] border border-zinc-800 rounded-lg p-4 flex items-center justify-between">
          <div>
            <p className="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">CATALOGADOS</p>
            <p className="text-2xl font-black text-white mt-1">{summary.completed}</p>
          </div>
          <div className="p-2.5 rounded-lg bg-emerald-500/10 text-emerald-400">
            <CheckCircle2 className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-[#181818] border border-zinc-800 rounded-lg p-4 flex items-center justify-between">
          <div>
            <p className="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">FALLIDOS / CANCELADOS</p>
            <p className="text-2xl font-black text-white mt-1">{summary.failed}</p>
          </div>
          <div className="p-2.5 rounded-lg bg-red-500/10 text-[#E50914]">
            <AlertCircle className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-[#181818] border border-zinc-800 rounded-lg p-4 flex items-center justify-between">
          <div>
            <p className="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">DISCOS ESCRIBIBLES</p>
            <p className="text-2xl font-black text-white mt-1">
              {mountedDisksCount}
              <span className="text-xs font-normal text-zinc-500 ml-1">/ {disks.length}</span>
            </p>
          </div>
          <div className="p-2.5 rounded-lg bg-red-500/10 text-[#E50914]">
            <HardDrive className="w-5 h-5" />
          </div>
        </div>
      </div>

      {/* 3. Formulario de Nueva Ingesta */}
      <form
        onSubmit={handleSubmit}
        className="bg-[#181818] border border-zinc-800 rounded-xl p-6 space-y-5"
      >
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-zinc-800 pb-4">
          <div className="flex items-center gap-2">
            <Sparkles className="w-4 h-4 text-[#E50914]" />
            <h2 className="text-sm font-bold text-white uppercase tracking-wider">
              Nueva Tarea de Ingesta Multimedia
            </h2>
          </div>

          {/* Selector Tipo de Contenido */}
          <div className="inline-flex bg-zinc-900 p-1 rounded-lg border border-zinc-800">
            <button
              type="button"
              onClick={() => setContentType('movie')}
              className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-colors cursor-pointer ${
                contentType === 'movie'
                  ? 'bg-[#E50914] text-white shadow'
                  : 'text-zinc-400 hover:text-white'
              }`}
            >
              <Film className="w-3.5 h-3.5" />
              Película
            </button>
            <button
              type="button"
              onClick={() => setContentType('series')}
              className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-colors cursor-pointer ${
                contentType === 'series'
                  ? 'bg-[#E50914] text-white shadow'
                  : 'text-zinc-400 hover:text-white'
              }`}
            >
              <Tv className="w-3.5 h-3.5" />
              Serie / Episodio
            </button>
          </div>
        </div>

        {formError && (
          <div className="flex items-center gap-2.5 px-4 py-3 rounded-lg bg-red-950/60 border border-red-500/40 text-red-200 text-xs">
            <AlertCircle className="w-4 h-4 text-red-400 shrink-0" />
            <span>{formError}</span>
          </div>
        )}

        {/* Fila 1: URL del flujo multimedia */}
        <div className="space-y-1.5">
          <div className="flex items-center justify-between gap-2">
            <label className="text-xs font-semibold text-zinc-300 flex items-center gap-1.5">
              <Link2 className="w-3.5 h-3.5 text-[#E50914]" />
              URL del Flujo Multimedia o Enlace Directo *
            </label>
            {detectedSourceBadge && (
              <span className={`text-[10px] font-mono px-2 py-0.5 rounded border ${detectedSourceBadge.classes}`}>
                {detectedSourceBadge.label}
              </span>
            )}
          </div>
          <input
            type="url"
            value={sourceUrl}
            onChange={(e) => setSourceUrl(e.target.value)}
            placeholder="https://servidor.ejemplo.com/video.mp4  o  https://cdn.ejemplo.com/master.m3u8"
            className="w-full bg-[#141414] border border-zinc-800 focus:border-[#E50914] rounded-lg px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 outline-none transition-colors"
            required
          />
        </div>

        {/* Fila 2: Título, Año, IMDb ID */}
        <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
          <div className="md:col-span-6 space-y-1.5">
            <label className="text-xs font-semibold text-zinc-300">
              {contentType === 'series' ? 'Título de la Serie *' : 'Título de la Película *'}
            </label>
            <input
              type="text"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder={contentType === 'series' ? 'Ej. Dark' : 'Ej. Inception'}
              className="w-full bg-[#141414] border border-zinc-800 focus:border-[#E50914] rounded-lg px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 outline-none transition-colors"
              required
            />
          </div>

          <div className="md:col-span-3 space-y-1.5">
            <label className="text-xs font-semibold text-zinc-300">
              IMDb ID <span className="text-zinc-500 font-normal">(carátula auto)</span>
            </label>
            <input
              type="text"
              value={imdbId}
              onChange={(e) => setImdbId(e.target.value)}
              placeholder="tt1375666"
              className="w-full bg-[#141414] border border-zinc-800 focus:border-[#E50914] rounded-lg px-3.5 py-2.5 text-sm text-white font-mono placeholder-zinc-500 outline-none transition-colors"
            />
          </div>

          <div className="md:col-span-3 space-y-1.5">
            <label className="text-xs font-semibold text-zinc-300">
              Año <span className="text-zinc-500 font-normal">(opcional)</span>
            </label>
            <input
              type="number"
              min="1900"
              max="2100"
              value={releaseYear}
              onChange={(e) => setReleaseYear(e.target.value)}
              placeholder="2024"
              className="w-full bg-[#141414] border border-zinc-800 focus:border-[#E50914] rounded-lg px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 outline-none transition-colors"
            />
          </div>
        </div>

        {/* Fila condicional: Temporada y Episodio (solo Series) */}
        {contentType === 'series' && (
          <div className="grid grid-cols-1 md:grid-cols-12 gap-4 bg-zinc-900/50 border border-zinc-800/80 rounded-lg p-4">
            <div className="md:col-span-3 space-y-1.5">
              <label className="text-xs font-semibold text-zinc-300">Temporada *</label>
              <input
                type="number"
                min="0"
                max="999"
                value={seasonNumber}
                onChange={(e) => setSeasonNumber(e.target.value)}
                className="w-full bg-[#141414] border border-zinc-800 focus:border-[#E50914] rounded-lg px-3 py-2 text-sm text-white outline-none"
                required
              />
            </div>
            <div className="md:col-span-3 space-y-1.5">
              <label className="text-xs font-semibold text-zinc-300">Episodio *</label>
              <input
                type="number"
                min="1"
                max="9999"
                value={episodeNumber}
                onChange={(e) => setEpisodeNumber(e.target.value)}
                className="w-full bg-[#141414] border border-zinc-800 focus:border-[#E50914] rounded-lg px-3 py-2 text-sm text-white outline-none"
                required
              />
            </div>
            <div className="md:col-span-6 space-y-1.5">
              <label className="text-xs font-semibold text-zinc-300">
                Nombre del Episodio <span className="text-zinc-500 font-normal">(opcional)</span>
              </label>
              <input
                type="text"
                value={episodeTitle}
                onChange={(e) => setEpisodeTitle(e.target.value)}
                placeholder="Ej. Secretos"
                className="w-full bg-[#141414] border border-zinc-800 focus:border-[#E50914] rounded-lg px-3 py-2 text-sm text-white placeholder-zinc-500 outline-none"
              />
            </div>
          </div>
        )}

        {/* Fila 3: Disco de destino, Subcarpeta opcional y Calidad */}
        <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
          <div className="md:col-span-6 space-y-1.5">
            <label className="text-xs font-semibold text-zinc-300 flex items-center gap-1.5">
              <HardDrive className="w-3.5 h-3.5 text-[#E50914]" />
              Disco de Destino (/mnt/media_library/Disk2..Disk7) *
            </label>
            <select
              value={targetDisk}
              onChange={(e) => setTargetDisk(e.target.value)}
              className="w-full bg-[#141414] border border-zinc-800 focus:border-[#E50914] rounded-lg px-3.5 py-2.5 text-sm text-white outline-none transition-colors"
              required
            >
              {disks.length === 0 && (
                <option value="/mnt/media_library/Disk2">/mnt/media_library/Disk2</option>
              )}
              {disks.map((d) => (
                <option key={d.path} value={d.path} disabled={!d.mounted || !d.writable}>
                  {d.path} — {d.mounted ? (d.writable ? `Libre: ${d.free_formatted} (${d.used_percent}% usado)` : 'Solo lectura') : 'No montado'}
                </option>
              ))}
            </select>
          </div>

          <div className="md:col-span-4 space-y-1.5">
            <label className="text-xs font-semibold text-zinc-300 flex items-center gap-1.5">
              <FolderOpen className="w-3.5 h-3.5 text-zinc-400" />
              Subcarpeta <span className="text-zinc-500 font-normal">(opcional)</span>
            </label>
            <input
              type="text"
              value={targetSubfolder}
              onChange={(e) => setTargetSubfolder(e.target.value)}
              placeholder={contentType === 'series' ? 'Series' : 'Peliculas'}
              className="w-full bg-[#141414] border border-zinc-800 focus:border-[#E50914] rounded-lg px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 outline-none transition-colors"
            />
          </div>

          <div className="md:col-span-2 space-y-1.5">
            <label className="text-xs font-semibold text-zinc-300">Calidad</label>
            <select
              value={quality}
              onChange={(e) => setQuality(e.target.value)}
              className="w-full bg-[#141414] border border-zinc-800 focus:border-[#E50914] rounded-lg px-3 py-2.5 text-sm text-white outline-none transition-colors"
            >
              <option value="2160p">4K (2160p)</option>
              <option value="1080p">Full HD (1080p)</option>
              <option value="720p">HD (720p)</option>
              <option value="480p">SD (480p)</option>
            </select>
          </div>
        </div>

        {/* Fila 4: Sinopsis opcional + Botón Submit */}
        <div className="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
          <div className="md:col-span-9 space-y-1.5">
            <label className="text-xs font-semibold text-zinc-300">
              Sinopsis / Descripción <span className="text-zinc-500 font-normal">(opcional — si se omite se consulta en TMDb/.nfo)</span>
            </label>
            <input
              type="text"
              value={overview}
              onChange={(e) => setOverview(e.target.value)}
              placeholder="Descripción breve para mostrar en el catálogo de FlixHN..."
              className="w-full bg-[#141414] border border-zinc-800 focus:border-[#E50914] rounded-lg px-3.5 py-2.5 text-sm text-white placeholder-zinc-500 outline-none transition-colors"
            />
          </div>

          <div className="md:col-span-3">
            <button
              type="submit"
              disabled={submitting}
              className="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-[#E50914] hover:bg-red-700 text-white text-sm font-bold rounded-lg transition-colors shadow-lg shadow-red-900/20 cursor-pointer disabled:opacity-60"
            >
              {submitting ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin" />
                  <span>Encolando...</span>
                </>
              ) : (
                <>
                  <UploadCloud className="w-4 h-4" />
                  <span>Iniciar Ingesta</span>
                </>
              )}
            </button>
          </div>
        </div>
      </form>

      {/* 4. Tabla de Progreso en Tiempo Real */}
      <div className="bg-[#181818] border border-zinc-800 rounded-xl overflow-hidden">
        <div className="p-4 border-b border-zinc-800 flex flex-col md:flex-row md:items-center justify-between gap-3">
          <div className="flex items-center gap-2 flex-wrap">
            {[
              { id: 'all', label: `Todas (${summary.total})` },
              { id: 'active', label: `Activas (${summary.active})` },
              { id: 'completed', label: `Completadas (${summary.completed})` },
              { id: 'failed', label: `Fallidas (${summary.failed})` },
            ].map((tab) => (
              <button
                key={tab.id}
                type="button"
                onClick={() => setStatusFilter(tab.id)}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer ${
                  statusFilter === tab.id
                    ? 'bg-[#E50914]/20 text-[#E50914] border border-[#E50914]/40'
                    : 'bg-zinc-900 text-zinc-400 hover:text-white border border-zinc-800'
                }`}
              >
                {tab.label}
              </button>
            ))}
          </div>

          <div className="relative w-full md:w-72">
            <Search className="w-3.5 h-3.5 text-zinc-500 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Buscar título, IMDb, ruta..."
              className="w-full bg-[#141414] border border-zinc-800 focus:border-zinc-700 rounded-lg pl-8 pr-3 py-1.5 text-xs text-white placeholder-zinc-500 outline-none"
            />
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="border-b border-zinc-800 bg-zinc-900/50 text-[11px] uppercase tracking-wider text-zinc-400">
                <th className="py-3 px-4 font-semibold">Contenido</th>
                <th className="py-3 px-4 font-semibold">Destino en Disco</th>
                <th className="py-3 px-4 font-semibold">Estado</th>
                <th className="py-3 px-4 font-semibold w-56">Progreso</th>
                <th className="py-3 px-4 font-semibold text-right">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-800/70 text-xs">
              {loading ? (
                <tr>
                  <td colSpan={5} className="py-10 text-center text-zinc-500">
                    <Loader2 className="w-5 h-5 animate-spin mx-auto mb-2 text-[#E50914]" />
                    Cargando cola de ingesta...
                  </td>
                </tr>
              ) : filteredTasks.length === 0 ? (
                <tr>
                  <td colSpan={5} className="py-12 text-center text-zinc-500">
                    No hay tareas de ingesta que coincidan con el filtro actual.
                  </td>
                </tr>
              ) : (
                filteredTasks.map((task) => {
                  const badge = STATUS_BADGE[task.status] || STATUS_BADGE.pending;
                  const srcBadge = SOURCE_TYPE_LABELS[task.source_type] || SOURCE_TYPE_LABELS.web_standard;
                  const isActive = ['pending', 'downloading', 'processing'].includes(task.status);
                  const pct = Math.min(100, Math.max(0, Number(task.progress) || 0));

                  return (
                    <tr key={task.id} className="hover:bg-zinc-900/40 transition-colors">
                      {/* Contenido */}
                      <td className="py-3.5 px-4 align-top">
                        <div className="flex items-start gap-2.5">
                          <div className="p-2 rounded-lg bg-zinc-900 border border-zinc-800 text-zinc-300 shrink-0 mt-0.5">
                            {task.content_type === 'series' ? (
                              <Tv className="w-4 h-4 text-[#E50914]" />
                            ) : (
                              <Film className="w-4 h-4 text-[#E50914]" />
                            )}
                          </div>
                          <div className="min-w-0">
                            <div className="flex items-center gap-1.5 flex-wrap">
                              <span className="font-bold text-white text-sm truncate max-w-[260px]">
                                {task.title}
                              </span>
                              {task.release_year && (
                                <span className="text-[11px] text-zinc-400">({task.release_year})</span>
                              )}
                              {task.content_type === 'series' && (
                                <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-zinc-800 text-zinc-200">
                                  S{String(task.season_number ?? 1).padStart(2, '0')}E{String(task.episode_number ?? 1).padStart(2, '0')}
                                </span>
                              )}
                            </div>
                            <div className="flex items-center gap-1.5 mt-1 flex-wrap">
                              <span className={`text-[10px] font-mono px-1.5 py-0.5 rounded border ${srcBadge.classes}`}>
                                {srcBadge.label}
                              </span>
                              {task.imdb_id && (
                                <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-300 border border-amber-500/20">
                                  {task.imdb_id}
                                </span>
                              )}
                              {task.file_size > 0 && (
                                <span className="text-[10px] text-zinc-400 font-mono">
                                  {formatFileSize(task.file_size)}
                                </span>
                              )}
                            </div>
                            <p
                              className="text-[11px] text-zinc-500 truncate max-w-[320px] mt-1 font-mono"
                              title={task.source_url}
                            >
                              {task.source_url}
                            </p>
                          </div>
                        </div>
                      </td>

                      {/* Destino */}
                      <td className="py-3.5 px-4 align-top">
                        <div className="space-y-1">
                          <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-zinc-200">
                            <HardDrive className="w-3 h-3 text-zinc-400" />
                            {task.target_disk}
                          </span>
                          <div className="flex items-center gap-1.5">
                            <span
                              className="text-[11px] font-mono text-zinc-400 truncate max-w-[240px]"
                              title={task.output_file}
                            >
                              {task.output_file}
                            </span>
                            {task.output_file && (
                              <button
                                type="button"
                                onClick={() => handleCopyPath(task)}
                                className="p-1 text-zinc-500 hover:text-white rounded cursor-pointer"
                                title="Copiar ruta completa"
                              >
                                {copiedId === task.id ? (
                                  <Check className="w-3 h-3 text-emerald-400" />
                                ) : (
                                  <Copy className="w-3 h-3" />
                                )}
                              </button>
                            )}
                          </div>
                        </div>
                      </td>

                      {/* Estado */}
                      <td className="py-3.5 px-4 align-top">
                        <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold border ${badge.classes}`}>
                          {isActive && <span className="w-1.5 h-1.5 rounded-full bg-current animate-pulse" />}
                          {badge.label}
                        </span>
                        {task.error_log && (
                          <p
                            className="text-[11px] text-red-400 mt-1.5 max-w-[220px] line-clamp-2"
                            title={task.error_log}
                          >
                            {task.error_log}
                          </p>
                        )}
                      </td>

                      {/* Progreso */}
                      <td className="py-3.5 px-4 align-top">
                        <div className="space-y-1.5">
                          <div className="flex items-center justify-between text-[11px] font-mono">
                            <span className="font-bold text-white">{pct}%</span>
                            <span className="text-zinc-400">{task.download_speed || '—'}</span>
                          </div>
                          <div className="w-full h-2 bg-zinc-900 rounded-full overflow-hidden border border-zinc-800">
                            <div
                              className={`h-full transition-all duration-500 ${
                                task.status === 'completed'
                                  ? 'bg-emerald-500'
                                  : task.status === 'failed'
                                  ? 'bg-red-600'
                                  : task.status === 'cancelled'
                                  ? 'bg-zinc-600'
                                  : 'bg-[#E50914]'
                              }`}
                              style={{ width: `${pct}%` }}
                            />
                          </div>
                          <div className="flex items-center justify-between text-[10px] text-zinc-500">
                            <span className="flex items-center gap-1">
                              <Clock className="w-3 h-3" />
                              {task.eta || '—'}
                            </span>
                            {task.title_id && (
                              <span className="text-emerald-400 font-mono">Title #{task.title_id}</span>
                            )}
                          </div>
                        </div>
                      </td>

                      {/* Acciones */}
                      <td className="py-3.5 px-4 align-top text-right">
                        <div className="inline-flex items-center gap-1.5">
                          <button
                            type="button"
                            onClick={() => setSelectedLogTask(task)}
                            className="p-1.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 text-zinc-400 hover:text-white border border-zinc-800 transition-colors cursor-pointer"
                            title="Ver logs de ejecución"
                          >
                            <Terminal className="w-3.5 h-3.5" />
                          </button>

                          <button
                            type="button"
                            onClick={() => handleCancelOrDelete(task)}
                            disabled={busyTaskId === task.id}
                            className={`p-1.5 rounded-lg border transition-colors cursor-pointer disabled:opacity-50 ${
                              isActive
                                ? 'bg-amber-500/10 hover:bg-amber-500/20 text-amber-300 border-amber-500/30'
                                : 'bg-zinc-900 hover:bg-red-950/60 text-zinc-400 hover:text-red-400 border-zinc-800 hover:border-red-500/30'
                            }`}
                            title={isActive ? 'Cancelar descarga' : 'Eliminar del historial'}
                          >
                            {busyTaskId === task.id ? (
                              <Loader2 className="w-3.5 h-3.5 animate-spin" />
                            ) : isActive ? (
                              <Ban className="w-3.5 h-3.5" />
                            ) : (
                              <Trash2 className="w-3.5 h-3.5" />
                            )}
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Modal de Logs de Ejecución */}
      {selectedLogTask && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-[#181818] border border-zinc-800 rounded-xl w-full max-w-2xl overflow-hidden shadow-2xl">
            <div className="px-5 py-4 border-b border-zinc-800 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Terminal className="w-4 h-4 text-[#E50914]" />
                <h3 className="text-sm font-bold text-white">
                  Registro de Ingesta #{selectedLogTask.id} — {selectedLogTask.title}
                </h3>
              </div>
              <button
                type="button"
                onClick={() => setSelectedLogTask(null)}
                className="p-1 text-zinc-400 hover:text-white rounded hover:bg-zinc-800 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-5 space-y-3">
              {selectedLogTask.error_log && (
                <div className="p-3 rounded-lg bg-red-950/60 border border-red-500/40 text-red-200 text-xs font-mono whitespace-pre-wrap">
                  {selectedLogTask.error_log}
                </div>
              )}
              <pre className="bg-[#141414] border border-zinc-800 rounded-lg p-4 text-[11px] font-mono text-zinc-300 max-h-80 overflow-y-auto whitespace-pre-wrap leading-relaxed">
                {selectedLogTask.logs || 'Sin entradas de log registradas todavía.'}
              </pre>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
