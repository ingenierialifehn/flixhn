import React, { useState, useEffect, useCallback, useMemo } from 'react';
import api from '../../services/api';
import {
  DownloadCloud,
  RefreshCw,
  CheckCircle2,
  AlertCircle,
  AlertTriangle,
  X,
  HardDrive,
  Film,
  Tv,
  RotateCcw,
  Trash2,
  ExternalLink,
  Search,
  Filter,
  Check,
  Lock,
  Pause,
  Server,
  Activity,
  Layers,
  Sparkles,
  Info,
  Plus,
  Folder,
  ArrowRight,
  ShieldCheck,
  Gauge,
  Clock,
  Play,
  Copy,
  Terminal,
} from 'lucide-react';
import FolderPickerModal from './FolderPickerModal';

export default function IngestManagement() {
  // Estados principales de tareas y métricas
  const [tasks, setTasks] = useState([]);
  const [metrics, setMetrics] = useState({
    active_tasks: 0,
    completed_tasks: 0,
    failed_tasks: 0,
    total_speed: '0.0 MB/s',
    disk_free_formatted: 'Cargando...',
    disk_total_formatted: 'Cargando...',
    disk_used_percentage: 0,
    storage_path: '/storage/media',
  });
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('all'); // all, active, completed, failed, cancelled

  // Estado del formulario de nueva ingesta
  const [sourceUrl, setSourceUrl] = useState('');
  const [customTitle, setCustomTitle] = useState('');
  const [targetFolder, setTargetFolder] = useState('/storage/media/movies');
  const [qualityProfile, setQualityProfile] = useState('1080p');
  const [autoIndex, setAutoIndex] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  // Modal selector de carpetas del servidor
  const [isFolderPickerOpen, setIsFolderPickerOpen] = useState(false);

  // Modal de confirmación de seguridad administrativa (Credenciales)
  const [isSecurityModalOpen, setIsSecurityModalOpen] = useState(false);
  const [adminPassword, setAdminPassword] = useState('');
  const [rememberAuth, setRememberAuth] = useState(true);
  const [cachedAdminPassword, setCachedAdminPassword] = useState(() => {
    try {
      return sessionStorage.getItem('flixhn_ingest_auth') || '';
    } catch {
      return '';
    }
  });

  // Modal de confirmación para acciones críticas (Cancelar / Eliminar)
  const [confirmModal, setConfirmModal] = useState({
    isOpen: false,
    type: null, // 'cancel' | 'delete'
    taskId: null,
    taskTitle: '',
  });

  // Toast / Feedback de acciones
  const [feedback, setFeedback] = useState(null); // { type: 'success' | 'error' | 'info', message: string }

  const showFeedback = useCallback((type, message) => {
    setFeedback({ type, message });
    setTimeout(() => {
      setFeedback((prev) => (prev?.message === message ? null : prev));
    }, 4500);
  }, []);

  // Cargar lista de tareas y métricas desde el servidor
  const loadData = useCallback(async (isSilent = false) => {
    if (!isSilent) setRefreshing(true);
    try {
      const res = await api.get('/admin/ingest/tasks', {
        params: {
          status: statusFilter !== 'all' ? statusFilter : undefined,
          q: searchQuery || undefined,
        },
      });

      if (res.data) {
        setTasks(Array.isArray(res.data.tasks) ? res.data.tasks : []);
        if (res.data.metrics) {
          setMetrics(res.data.metrics);
        }
      }
    } catch (err) {
      console.error('Error al cargar tareas de ingesta FlixEngine:', err);
      if (!isSilent) {
        showFeedback('error', 'No fue posible sincronizar el estado del motor de ingesta.');
      }
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [statusFilter, searchQuery, showFeedback]);

  // Carga inicial
  useEffect(() => {
    loadData();
  }, [loadData]);

  // Sondeo en tiempo real continuo (Polling cada 2.5s cuando hay descargas activas)
  useEffect(() => {
    const hasActiveTasks = tasks.some((t) =>
      ['pending', 'downloading', 'processing'].includes(t.status)
    );

    const intervalTime = hasActiveTasks ? 2500 : 7000;
    const interval = setInterval(() => {
      loadData(true);
    }, intervalTime);

    return () => clearInterval(interval);
  }, [tasks, loadData]);

  // Detectar tipo de fuente en tiempo real a medida que el usuario tipea
  const detectedSourceType = useMemo(() => {
    const url = sourceUrl.trim().toLowerCase();
    if (!url) return null;
    if (url.includes('.m3u8') || url.includes('manifest')) {
      return { label: 'HLS / Manifest', color: 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' };
    }
    if (url.includes('youtube.com') || url.includes('youtu.be')) {
      return { label: 'YouTube Stream', color: 'bg-red-500/20 text-red-400 border-red-500/30' };
    }
    if (url.includes('.mp4') || url.includes('.mkv') || url.includes('.webm')) {
      return { label: 'Direct VOD', color: 'bg-blue-500/20 text-blue-400 border-blue-500/30' };
    }
    return { label: 'Flujo Externo', color: 'bg-purple-500/20 text-purple-400 border-purple-500/30' };
  }, [sourceUrl]);

  // Enviar formulario de inicio de ingesta
  const handleInitiateIngest = (e) => {
    if (e) e.preventDefault();

    if (!sourceUrl.trim()) {
      showFeedback('error', 'Debe proporcionar una URL de origen o manifiesto válido.');
      return;
    }

    // Si no hay contraseña administrativa en memoria para esta sesión, pedirla
    if (!cachedAdminPassword) {
      setIsSecurityModalOpen(true);
      return;
    }

    // Proceder con la creación
    submitIngestTask(cachedAdminPassword);
  };

  const submitIngestTask = async (passwordToUse) => {
    setSubmitting(true);
    try {
      const payload = {
        url: sourceUrl.trim(),
        title: customTitle.trim() || undefined,
        target_folder: targetFolder.trim(),
        quality: qualityProfile,
        auto_index: autoIndex,
        admin_password: passwordToUse || undefined,
      };

      const res = await api.post('/admin/ingest/tasks', payload);

      if (res.data && res.data.status === 'success') {
        showFeedback('success', `Ingesta iniciada: "${res.data.task?.title || 'Contenido'}"`);
        setSourceUrl('');
        setCustomTitle('');
        setIsSecurityModalOpen(false);
        setAdminPassword('');
        loadData();
      }
    } catch (err) {
      console.error('Error al iniciar ingesta:', err);
      const msg = err.response?.data?.message || 'Error al conectar con el servidor de ingesta.';
      showFeedback('error', msg);

      // Si fue error de contraseña, reabrir modal
      if (err.response?.status === 422 && msg.toLowerCase().includes('contraseña')) {
        setCachedAdminPassword('');
        sessionStorage.removeItem('flixhn_ingest_auth');
        setIsSecurityModalOpen(true);
      }
    } finally {
      setSubmitting(false);
    }
  };

  // Confirmar credenciales en el modal de seguridad
  const handleConfirmSecurity = (e) => {
    if (e) e.preventDefault();
    if (!adminPassword.trim()) {
      showFeedback('error', 'Ingrese su contraseña de administrador para autorizar la ingesta.');
      return;
    }

    if (rememberAuth) {
      try {
        sessionStorage.setItem('flixhn_ingest_auth', adminPassword);
      } catch {}
      setCachedAdminPassword(adminPassword);
    }

    submitIngestTask(adminPassword);
  };

  // Cancelar descarga en segundo plano
  const handleCancelTask = async (taskId) => {
    try {
      const res = await api.post(`/admin/ingest/tasks/${taskId}/cancel`);
      if (res.data) {
        showFeedback('info', res.data.message || 'Descarga cancelada correctamente.');
        loadData();
      }
    } catch (err) {
      console.error('Error cancelando descarga:', err);
      showFeedback('error', err.response?.data?.message || 'No fue posible cancelar la descarga.');
    } finally {
      setConfirmModal({ isOpen: false, type: null, taskId: null, taskTitle: '' });
    }
  };

  // Reintentar descarga fallida
  const handleRetryTask = async (taskId) => {
    try {
      const res = await api.post(`/admin/ingest/tasks/${taskId}/retry`);
      if (res.data) {
        showFeedback('success', res.data.message || 'Reanudando ingesta...');
        loadData();
      }
    } catch (err) {
      console.error('Error reintentando descarga:', err);
      showFeedback('error', err.response?.data?.message || 'No fue posible reiniciar la descarga.');
    }
  };

  // Eliminar tarea y purgar archivos
  const handleDeleteTask = async (taskId) => {
    try {
      const res = await api.delete(`/admin/ingest/tasks/${taskId}`);
      if (res.data) {
        showFeedback('success', 'Tarea eliminada del historial.');
        loadData();
      }
    } catch (err) {
      console.error('Error eliminando tarea:', err);
      showFeedback('error', err.response?.data?.message || 'No fue posible eliminar la tarea.');
    } finally {
      setConfirmModal({ isOpen: false, type: null, taskId: null, taskTitle: '' });
    }
  };

  // Copiar al portapapeles con feedback visual
  const handleCopyPath = (path) => {
    if (navigator?.clipboard) {
      navigator.clipboard.writeText(path);
      showFeedback('info', 'Ruta copiada al portapapeles.');
    }
  };

  // Filtrado de tareas local
  const filteredTasks = useMemo(() => {
    return tasks.filter((t) => {
      if (statusFilter === 'active') {
        return ['pending', 'downloading', 'processing'].includes(t.status);
      }
      if (statusFilter !== 'all' && t.status !== statusFilter) {
        return false;
      }
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase();
        const matchesTitle = t.title?.toLowerCase().includes(q);
        const matchesUrl = t.source_url?.toLowerCase().includes(q);
        const matchesOutput = t.output_file?.toLowerCase().includes(q);
        return matchesTitle || matchesUrl || matchesOutput;
      }
      return true;
    });
  }, [tasks, statusFilter, searchQuery]);

  return (
    <div className="space-y-6 pb-12 select-none">
      {/* Toast Feedback Banner */}
      {feedback && (
        <div
          className={`fixed top-4 right-4 z-50 flex items-center gap-3 px-4 py-3 rounded-xl border shadow-2xl backdrop-blur-md transition-all duration-300 animate-in fade-in slide-in-from-top-2 ${
            feedback.type === 'success'
              ? 'bg-emerald-950/90 border-emerald-500/40 text-emerald-200'
              : feedback.type === 'error'
              ? 'bg-red-950/90 border-red-500/40 text-red-200'
              : 'bg-zinc-900/90 border-zinc-700 text-zinc-200'
          }`}
        >
          {feedback.type === 'success' && <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0" />}
          {feedback.type === 'error' && <AlertCircle className="w-5 h-5 text-red-400 shrink-0" />}
          {feedback.type === 'info' && <Info className="w-5 h-5 text-zinc-400 shrink-0" />}
          <span className="text-sm font-medium">{feedback.message}</span>
          <button
            onClick={() => setFeedback(null)}
            className="p-1 text-zinc-400 hover:text-white rounded-lg hover:bg-white/10"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* =========================================================================
          1. BARRA SUPERIOR CON MÉTRICAS EN TIEMPO REAL
         ========================================================================= */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Tareas Activas */}
        <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-5 relative overflow-hidden shadow-lg">
          <div className="flex items-center justify-between">
            <span className="text-xs uppercase tracking-wider font-semibold text-zinc-400">
              Tareas Activas
            </span>
            <div className="p-2.5 bg-blue-500/10 text-blue-400 rounded-xl border border-blue-500/20">
              <Activity className="w-5 h-5" />
            </div>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-3xl font-extrabold text-white">
              {metrics.active_tasks}
            </span>
            {metrics.active_tasks > 0 && (
              <span className="flex items-center gap-1.5 text-xs text-blue-400 font-medium bg-blue-500/10 px-2 py-0.5 rounded-full border border-blue-500/20">
                <span className="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span>
                Descargando
              </span>
            )}
          </div>
          <p className="mt-1 text-xs text-zinc-500">
            Descargas y transcodificaciones en segundo plano
          </p>
        </div>

        {/* Velocidad Total de Descarga */}
        <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-5 relative overflow-hidden shadow-lg">
          <div className="flex items-center justify-between">
            <span className="text-xs uppercase tracking-wider font-semibold text-zinc-400">
              Velocidad Total
            </span>
            <div className="p-2.5 bg-emerald-500/10 text-emerald-400 rounded-xl border border-emerald-500/20">
              <Gauge className="w-5 h-5" />
            </div>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-3xl font-extrabold text-emerald-400">
              {metrics.total_speed}
            </span>
          </div>
          <p className="mt-1 text-xs text-zinc-500">
            Tráfico On-Net agregado consumido por el motor
          </p>
        </div>

        {/* Almacenamiento Libre en Disco */}
        <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-5 relative overflow-hidden shadow-lg">
          <div className="flex items-center justify-between">
            <span className="text-xs uppercase tracking-wider font-semibold text-zinc-400">
              Disco de Medios
            </span>
            <div className="p-2.5 bg-red-500/10 text-[#E50914] rounded-xl border border-red-500/20">
              <HardDrive className="w-5 h-5" />
            </div>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-2xl font-extrabold text-white">
              {metrics.disk_free_formatted}
            </span>
            <span className="text-xs text-zinc-400">
              libres de {metrics.disk_total_formatted}
            </span>
          </div>
          {/* Barra de progreso de uso de disco */}
          <div className="mt-2 w-full h-1.5 bg-zinc-800 rounded-full overflow-hidden">
            <div
              className={`h-full transition-all duration-500 ${
                metrics.disk_used_percentage > 85 ? 'bg-red-500' : 'bg-[#E50914]'
              }`}
              style={{ width: `${metrics.disk_used_percentage}%` }}
            ></div>
          </div>
          <p className="mt-1.5 text-[11px] text-zinc-500 truncate" title={metrics.storage_path}>
            Ruta: {metrics.storage_path}
          </p>
        </div>

        {/* Historial de Ingestas */}
        <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-5 relative overflow-hidden shadow-lg">
          <div className="flex items-center justify-between">
            <span className="text-xs uppercase tracking-wider font-semibold text-zinc-400">
              Finalizadas
            </span>
            <div className="p-2.5 bg-purple-500/10 text-purple-400 rounded-xl border border-purple-500/20">
              <Layers className="w-5 h-5" />
            </div>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-3xl font-extrabold text-white">
              {metrics.completed_tasks}
            </span>
            {metrics.failed_tasks > 0 && (
              <span className="text-xs text-red-400 bg-red-500/10 px-2 py-0.5 rounded-full border border-red-500/20">
                {metrics.failed_tasks} con error
              </span>
            )}
          </div>
          <p className="mt-1 text-xs text-zinc-500">
            Títulos indexados automáticamente al catálogo On-Net
          </p>
        </div>
      </div>

      {/* =========================================================================
          2. FORMULARIO DE INGESTA RÁPIDA (FlixEngine Ingest Panel)
         ========================================================================= */}
      <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-xl">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-5 border-b border-zinc-800">
          <div>
            <div className="flex items-center gap-2">
              <div className="p-2 bg-[#E50914]/10 text-[#E50914] rounded-lg border border-[#E50914]/20">
                <DownloadCloud className="w-5 h-5" />
              </div>
              <h2 className="text-lg font-bold text-white tracking-wide">
                FlixEngine Ingest — Ingesta Rápida
              </h2>
            </div>
            <p className="mt-1 text-xs text-zinc-400">
              Descarga automatizada en segundo plano desde URLs, manifiestos HLS (.m3u8), VOD o streams externos con post-procesamiento e indexación en TMDb.
            </p>
          </div>

          <div className="flex items-center gap-2">
            {cachedAdminPassword ? (
              <span className="flex items-center gap-1.5 text-xs text-emerald-400 bg-emerald-500/10 px-3 py-1.5 rounded-xl border border-emerald-500/30 font-medium">
                <ShieldCheck className="w-4 h-4 text-emerald-400" />
                Seguridad Autenticada
              </span>
            ) : (
              <button
                type="button"
                onClick={() => setIsSecurityModalOpen(true)}
                className="flex items-center gap-1.5 text-xs text-zinc-400 hover:text-white bg-zinc-900 hover:bg-zinc-800 px-3 py-1.5 rounded-xl border border-zinc-800 transition-colors"
                title="Configurar confirmación de credenciales administrativas"
              >
                <Lock className="w-3.5 h-3.5 text-amber-400" />
                Confirmar Credenciales
              </button>
            )}
          </div>
        </div>

        <form onSubmit={handleInitiateIngest} className="mt-6 space-y-5">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
            {/* Campo URL o Manifiesto */}
            <div className="lg:col-span-8 space-y-1.5">
              <label className="text-xs font-semibold text-zinc-300 flex items-center justify-between">
                <span>URL de Origen o Manifiesto de Video *</span>
                {detectedSourceType && (
                  <span className={`text-[10px] font-bold px-2 py-0.5 rounded-md border ${detectedSourceType.color}`}>
                    {detectedSourceType.label}
                  </span>
                )}
              </label>
              <div className="relative">
                <input
                  type="text"
                  value={sourceUrl}
                  onChange={(e) => setSourceUrl(e.target.value)}
                  placeholder="https://servidor-origen.com/stream/manifest.m3u8 o video.mp4"
                  className="w-full bg-[#121212] border border-zinc-700/80 focus:border-[#E50914] focus:ring-1 focus:ring-[#E50914] text-white text-sm rounded-xl px-4 py-3 placeholder-zinc-500 transition-colors"
                  required
                />
              </div>
              <p className="text-[11px] text-zinc-500">
                Soporta listas HLS (.m3u8), enlaces directos de video (.mp4, .mkv, .webm) y plataformas compatibles con yt-dlp.
              </p>
            </div>

            {/* Campo Título Opcional */}
            <div className="lg:col-span-4 space-y-1.5">
              <label className="text-xs font-semibold text-zinc-300">
                Título del Contenido (Opcional)
              </label>
              <input
                type="text"
                value={customTitle}
                onChange={(e) => setCustomTitle(e.target.value)}
                placeholder="Ej. Gladiador II (2024)"
                className="w-full bg-[#121212] border border-zinc-700/80 focus:border-[#E50914] focus:ring-1 focus:ring-[#E50914] text-white text-sm rounded-xl px-4 py-3 placeholder-zinc-500 transition-colors"
              />
              <p className="text-[11px] text-zinc-500">
                Si se omite, se inferirá automáticamente de la URL y TMDb.
              </p>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-12 gap-5 pt-2">
            {/* Selector de Carpeta de Destino */}
            <div className="md:col-span-6 space-y-1.5">
              <label className="text-xs font-semibold text-zinc-300 flex items-center justify-between">
                <span>Carpeta Física de Destino en el Servidor</span>
                <span className="text-[10px] text-zinc-500 font-mono">Almacenamiento Local</span>
              </label>
              <div className="flex items-center gap-2">
                <div className="relative flex-1">
                  <input
                    type="text"
                    value={targetFolder}
                    onChange={(e) => setTargetFolder(e.target.value)}
                    className="w-full bg-[#121212] border border-zinc-700/80 text-zinc-200 text-sm font-mono rounded-xl px-3.5 py-2.5 focus:border-[#E50914] focus:ring-1 focus:ring-[#E50914] transition-colors"
                  />
                </div>
                <button
                  type="button"
                  onClick={() => setIsFolderPickerOpen(true)}
                  className="flex items-center gap-2 px-4 py-2.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-xs font-semibold rounded-xl border border-zinc-700 transition-colors shrink-0 cursor-pointer shadow-sm"
                >
                  <Folder className="w-4 h-4 text-amber-400" />
                  <span>Explorar Carpetas</span>
                </button>
              </div>
            </div>

            {/* Selector de Perfil de Calidad */}
            <div className="md:col-span-3 space-y-1.5">
              <label className="text-xs font-semibold text-zinc-300">
                Perfil de Calidad
              </label>
              <select
                value={qualityProfile}
                onChange={(e) => setQualityProfile(e.target.value)}
                className="w-full bg-[#121212] border border-zinc-700/80 text-zinc-200 text-sm rounded-xl px-3.5 py-2.5 focus:border-[#E50914] focus:ring-1 focus:ring-[#E50914] transition-colors"
              >
                <option value="1080p">1080p Full HD (Recomendado)</option>
                <option value="720p">720p HD Adaptativo</option>
                <option value="4K">4K Ultra HD (HDR / Mejor)</option>
                <option value="best">Mejor Calidad Disponible</option>
              </select>
            </div>

            {/* Checkbox Auto-Indexación */}
            <div className="md:col-span-3 flex flex-col justify-end pb-1">
              <label className="flex items-start gap-2.5 cursor-pointer select-none bg-[#121212] border border-zinc-800 hover:border-zinc-700 p-2.5 rounded-xl transition-colors">
                <input
                  type="checkbox"
                  checked={autoIndex}
                  onChange={(e) => setAutoIndex(e.target.checked)}
                  className="mt-0.5 rounded border-zinc-700 text-[#E50914] focus:ring-[#E50914] bg-zinc-900"
                />
                <div>
                  <span className="text-xs font-semibold text-zinc-200 block">
                    Auto-Indexar a /browse
                  </span>
                  <span className="text-[10px] text-zinc-500 leading-tight block">
                    Consulta TMDb y publica inmediatamente en catálogo.
                  </span>
                </div>
              </label>
            </div>
          </div>

          {/* Botón de Envío */}
          <div className="flex items-center justify-end pt-3">
            <button
              type="submit"
              disabled={submitting || !sourceUrl.trim()}
              className="flex items-center gap-2.5 px-6 py-3 bg-[#E50914] hover:bg-[#b80710] disabled:bg-zinc-800 disabled:text-zinc-600 disabled:cursor-not-allowed text-white font-bold text-sm rounded-xl shadow-lg shadow-red-900/30 transition-all cursor-pointer"
            >
              {submitting ? (
                <>
                  <RefreshCw className="w-4 h-4 animate-spin" />
                  <span>Iniciando Ingesta...</span>
                </>
              ) : (
                <>
                  <DownloadCloud className="w-4 h-4" />
                  <span>Iniciar Ingesta en Segundo Plano</span>
                </>
              )}
            </button>
          </div>
        </form>
      </div>

      {/* =========================================================================
          3. TABLA DE PROCESOS EN TIEMPO REAL
         ========================================================================= */}
      <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl shadow-xl overflow-hidden">
        {/* Cabecera y Filtros de la Tabla */}
        <div className="p-5 border-b border-zinc-800 flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className="flex items-center gap-1.5">
              <h3 className="text-base font-bold text-white tracking-wide">
                Cola de Ingesta y Descargas en Vivo
              </h3>
              <span className="text-xs font-semibold bg-zinc-800 text-zinc-400 px-2.5 py-0.5 rounded-full">
                {filteredTasks.length}
              </span>
            </div>

            {refreshing && (
              <span className="flex items-center gap-1 text-[11px] text-zinc-400 font-mono">
                <RefreshCw className="w-3 h-3 animate-spin text-[#E50914]" />
                Sincronizando...
              </span>
            )}
          </div>

          <div className="flex flex-wrap items-center gap-2.5">
            {/* Buscador de tareas */}
            <div className="relative">
              <Search className="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Buscar por título o ruta..."
                className="bg-[#121212] border border-zinc-800 focus:border-zinc-700 text-white text-xs rounded-xl pl-9 pr-3 py-2 w-52 placeholder-zinc-500 transition-colors"
              />
            </div>

            {/* Pestañas de Filtro Rápido */}
            <div className="flex items-center bg-[#121212] p-1 rounded-xl border border-zinc-800 text-xs">
              <button
                type="button"
                onClick={() => setStatusFilter('all')}
                className={`px-3 py-1 rounded-lg font-medium transition-colors ${
                  statusFilter === 'all' ? 'bg-zinc-800 text-white' : 'text-zinc-400 hover:text-white'
                }`}
              >
                Todas
              </button>
              <button
                type="button"
                onClick={() => setStatusFilter('active')}
                className={`px-3 py-1 rounded-lg font-medium transition-colors ${
                  statusFilter === 'active' ? 'bg-zinc-800 text-blue-400' : 'text-zinc-400 hover:text-white'
                }`}
              >
                Activas
              </button>
              <button
                type="button"
                onClick={() => setStatusFilter('completed')}
                className={`px-3 py-1 rounded-lg font-medium transition-colors ${
                  statusFilter === 'completed' ? 'bg-zinc-800 text-emerald-400' : 'text-zinc-400 hover:text-white'
                }`}
              >
                Completadas
              </button>
              <button
                type="button"
                onClick={() => setStatusFilter('failed')}
                className={`px-3 py-1 rounded-lg font-medium transition-colors ${
                  statusFilter === 'failed' ? 'bg-zinc-800 text-red-400' : 'text-zinc-400 hover:text-white'
                }`}
              >
                Fallidas
              </button>
            </div>

            {/* Botón Refrescar Manualmente */}
            <button
              type="button"
              onClick={() => loadData()}
              disabled={refreshing}
              className="p-2 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white rounded-xl border border-zinc-700 transition-colors cursor-pointer"
              title="Refrescar datos ahora"
            >
              <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin' : ''}`} />
            </button>
          </div>
        </div>

        {/* Tabla de Resultados */}
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="border-b border-zinc-800 bg-zinc-900/60 text-[11px] font-bold text-zinc-400 uppercase tracking-wider">
                <th className="py-3.5 px-5">Contenido / Título</th>
                <th className="py-3.5 px-4">Origen / Proveedor</th>
                <th className="py-3.5 px-4 min-w-[220px]">Progreso y Velocidad</th>
                <th className="py-3.5 px-4">Ruta en Servidor</th>
                <th className="py-3.5 px-5 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-800/60 text-xs text-zinc-300">
              {loading && tasks.length === 0 ? (
                <tr>
                  <td colSpan="5" className="py-12 text-center text-zinc-500">
                    <RefreshCw className="w-6 h-6 animate-spin mx-auto mb-2 text-[#E50914]" />
                    <span>Cargando cola de descargas...</span>
                  </td>
                </tr>
              ) : filteredTasks.length === 0 ? (
                <tr>
                  <td colSpan="5" className="py-16 text-center text-zinc-500">
                    <DownloadCloud className="w-12 h-12 stroke-[1] mx-auto mb-3 text-zinc-600" />
                    <p className="text-sm font-semibold text-zinc-400">
                      No hay tareas de ingesta registradas
                    </p>
                    <p className="text-xs text-zinc-600 mt-1 max-w-sm mx-auto">
                      Ingrese una URL o manifiesto en el formulario superior para iniciar una descarga automatizada.
                    </p>
                  </td>
                </tr>
              ) : (
                filteredTasks.map((t) => {
                  const isActive = ['pending', 'downloading', 'processing'].includes(t.status);
                  const isCompleted = t.status === 'completed';
                  const isFailed = t.status === 'failed';
                  const isCancelled = t.status === 'cancelled';

                  // Badge de estado
                  let statusBadge = (
                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-400 border border-blue-500/30 flex items-center gap-1 w-fit">
                      <span className="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span>
                      Descargando
                    </span>
                  );

                  if (t.status === 'pending') {
                    statusBadge = (
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-400 border border-amber-500/30 w-fit">
                        En Cola
                      </span>
                    );
                  } else if (t.status === 'processing') {
                    statusBadge = (
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-500/20 text-purple-400 border border-purple-500/30 flex items-center gap-1 w-fit">
                        <Sparkles className="w-3 h-3 animate-spin" />
                        Indexando TMDb...
                      </span>
                    );
                  } else if (isCompleted) {
                    statusBadge = (
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 flex items-center gap-1 w-fit">
                        <Check className="w-3 h-3" />
                        Completado
                      </span>
                    );
                  } else if (isFailed) {
                    statusBadge = (
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-500/20 text-red-400 border border-red-500/30 w-fit">
                        Falló
                      </span>
                    );
                  } else if (isCancelled) {
                    statusBadge = (
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-zinc-800 text-zinc-400 border border-zinc-700 w-fit">
                        Cancelado
                      </span>
                    );
                  }

                  return (
                    <tr key={t.id} className="hover:bg-zinc-800/30 transition-colors">
                      {/* Título y Detalles */}
                      <td className="py-4 px-5">
                        <div className="flex items-center gap-3">
                          <div className="w-9 h-9 rounded-xl bg-zinc-800 flex items-center justify-center text-zinc-400 shrink-0 border border-zinc-700/60">
                            {t.provider === 'series' ? (
                              <Tv className="w-4 h-4 text-purple-400" />
                            ) : (
                              <Film className="w-4 h-4 text-[#E50914]" />
                            )}
                          </div>
                          <div className="min-w-0">
                            <span className="font-bold text-white block truncate max-w-xs sm:max-w-md">
                              {t.title || 'Contenido Ingestado'}
                            </span>
                            <div className="flex items-center gap-2 mt-1">
                              <span className="text-[10px] font-mono text-zinc-400 bg-zinc-900 px-1.5 py-0.2 rounded border border-zinc-800">
                                {t.quality || '1080p'}
                              </span>
                              {t.auto_index && (
                                <span className="text-[10px] text-zinc-400 flex items-center gap-0.5">
                                  <Sparkles className="w-3 h-3 text-amber-400" />
                                  Auto-Index
                                </span>
                              )}
                            </div>
                          </div>
                        </div>
                      </td>

                      {/* Origen y Proveedor */}
                      <td className="py-4 px-4">
                        <div className="space-y-1">
                          <span className="uppercase text-[10px] font-bold px-2 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-300">
                            {t.provider || 'external'}
                          </span>
                          <span
                            className="text-[11px] font-mono text-zinc-500 truncate block max-w-[200px]"
                            title={t.source_url}
                          >
                            {t.source_url}
                          </span>
                        </div>
                      </td>

                      {/* Progreso, Velocidad y ETA */}
                      <td className="py-4 px-4 min-w-[220px]">
                        <div className="space-y-1.5">
                          <div className="flex items-center justify-between text-[11px]">
                            {statusBadge}
                            <span className="font-mono font-bold text-zinc-200">
                              {t.progress}%
                            </span>
                          </div>

                          {/* Barra de progreso interactiva */}
                          <div className="w-full h-2 bg-zinc-800 rounded-full overflow-hidden">
                            <div
                              className={`h-full transition-all duration-300 ${
                                isCompleted
                                  ? 'bg-emerald-500'
                                  : isFailed
                                  ? 'bg-red-500'
                                  : isCancelled
                                  ? 'bg-zinc-600'
                                  : 'bg-gradient-to-r from-red-600 via-rose-500 to-red-500'
                              }`}
                              style={{ width: `${Math.max(2, t.progress)}%` }}
                            ></div>
                          </div>

                          {/* Velocidad y Tiempo Restante */}
                          {isActive && (
                            <div className="flex items-center justify-between text-[10px] text-zinc-400 font-mono">
                              <span className="flex items-center gap-1">
                                <Gauge className="w-3 h-3 text-emerald-400" />
                                {t.download_speed || '0.0 MB/s'}
                              </span>
                              <span className="flex items-center gap-1 text-zinc-500">
                                <Clock className="w-3 h-3" />
                                {t.eta || '--:--'}
                              </span>
                            </div>
                          )}

                          {/* Mensaje de error si falló */}
                          {isFailed && t.error_message && (
                            <p
                              className="text-[10px] text-red-400 truncate max-w-xs mt-1"
                              title={t.error_message}
                            >
                              {t.error_message}
                            </p>
                          )}
                        </div>
                      </td>

                      {/* Ruta Física en Servidor */}
                      <td className="py-4 px-4">
                        <div className="flex items-center gap-2 max-w-[220px]">
                          <span
                            className="text-[11px] font-mono text-zinc-400 truncate"
                            title={t.output_file || t.target_folder}
                          >
                            {t.output_file || t.target_folder}
                          </span>
                          {t.output_file && (
                            <button
                              type="button"
                              onClick={() => handleCopyPath(t.output_file)}
                              className="p-1 text-zinc-500 hover:text-zinc-200 rounded hover:bg-zinc-800 transition-colors shrink-0"
                              title="Copiar ruta física"
                            >
                              <Copy className="w-3.5 h-3.5" />
                            </button>
                          )}
                        </div>
                      </td>

                      {/* Acciones */}
                      <td className="py-4 px-5 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          {/* Cancelar / Detener */}
                          {isActive && (
                            <button
                              type="button"
                              onClick={() =>
                                setConfirmModal({
                                  isOpen: true,
                                  type: 'cancel',
                                  taskId: t.id,
                                  taskTitle: t.title || 'esta descarga',
                                })
                              }
                              className="p-2 text-amber-400 hover:text-amber-300 hover:bg-amber-500/10 rounded-xl transition-colors cursor-pointer"
                              title="Detener y cancelar descarga"
                            >
                              <Pause className="w-4 h-4" />
                            </button>
                          )}

                          {/* Reintentar si falló o fue cancelado */}
                          {(isFailed || isCancelled) && (
                            <button
                              type="button"
                              onClick={() => handleRetryTask(t.id)}
                              className="p-2 text-blue-400 hover:text-blue-300 hover:bg-blue-500/10 rounded-xl transition-colors cursor-pointer"
                              title="Reintentar descarga"
                            >
                              <RotateCcw className="w-4 h-4" />
                            </button>
                          )}

                          {/* Eliminar del historial */}
                          <button
                            type="button"
                            onClick={() =>
                              setConfirmModal({
                                isOpen: true,
                                type: 'delete',
                                taskId: t.id,
                                taskTitle: t.title || 'esta tarea',
                              })
                            }
                            className="p-2 text-zinc-500 hover:text-red-400 hover:bg-red-500/10 rounded-xl transition-colors cursor-pointer"
                            title="Eliminar tarea del historial y purgar temporales"
                          >
                            <Trash2 className="w-4 h-4" />
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

      {/* =========================================================================
          MODAL DE CONFIRMACIÓN DE SEGURIDAD ADMINISTRATIVA (Credenciales)
         ========================================================================= */}
      {isSecurityModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-[#181818] border border-zinc-700/80 rounded-2xl max-w-md w-full p-6 shadow-2xl relative">
            <button
              onClick={() => setIsSecurityModalOpen(false)}
              className="absolute top-4 right-4 text-zinc-400 hover:text-white p-1 rounded-lg hover:bg-zinc-800"
            >
              <X className="w-5 h-5" />
            </button>

            <div className="flex items-center gap-3 mb-4">
              <div className="p-3 bg-red-500/10 text-[#E50914] rounded-xl border border-red-500/20">
                <ShieldCheck className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-base font-bold text-white">
                  Autorización Administrativa Requerida
                </h3>
                <p className="text-xs text-zinc-400">
                  FlixEngine Ingest — Protección Perimetral
                </p>
              </div>
            </div>

            <p className="text-xs text-zinc-300 leading-relaxed mb-4">
              Por directiva de seguridad del ISP, el aprovisionamiento de descargas masivas y almacenamiento en disco requiere confirmar las credenciales del Administrador.
            </p>

            <form onSubmit={handleConfirmSecurity} className="space-y-4">
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-zinc-300">
                  Contraseña de Administrador
                </label>
                <div className="relative">
                  <input
                    type="password"
                    value={adminPassword}
                    onChange={(e) => setAdminPassword(e.target.value)}
                    placeholder="••••••••••••"
                    className="w-full bg-[#121212] border border-zinc-700 focus:border-[#E50914] focus:ring-1 focus:ring-[#E50914] text-white text-sm rounded-xl px-4 py-2.5 transition-colors"
                    autoFocus
                    required
                  />
                </div>
              </div>

              <label className="flex items-center gap-2 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={rememberAuth}
                  onChange={(e) => setRememberAuth(e.target.checked)}
                  className="rounded border-zinc-700 text-[#E50914] focus:ring-[#E50914] bg-zinc-900"
                />
                <span className="text-xs text-zinc-400">
                  Recordar confirmación durante esta sesión del navegador
                </span>
              </label>

              <div className="flex items-center justify-end gap-2.5 pt-2">
                <button
                  type="button"
                  onClick={() => setIsSecurityModalOpen(false)}
                  className="px-4 py-2.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs font-semibold rounded-xl transition-colors cursor-pointer"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={!adminPassword.trim() || submitting}
                  className="px-5 py-2.5 bg-[#E50914] hover:bg-[#b80710] disabled:bg-zinc-800 disabled:text-zinc-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-red-900/30 transition-colors cursor-pointer"
                >
                  {submitting ? 'Verificando...' : 'Autorizar e Iniciar'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* =========================================================================
          MODAL DE CONFIRMACIÓN DE ACCIONES CRÍTICAS (Cancelar / Eliminar)
         ========================================================================= */}
      {confirmModal.isOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-in fade-in duration-200">
          <div className="bg-[#181818] border border-zinc-700/80 rounded-2xl max-w-sm w-full p-6 shadow-2xl relative">
            <button
              onClick={() => setConfirmModal({ isOpen: false, type: null, taskId: null, taskTitle: '' })}
              className="absolute top-4 right-4 text-zinc-400 hover:text-white p-1 rounded-lg hover:bg-zinc-800"
            >
              <X className="w-5 h-5" />
            </button>

            <div className="flex items-center gap-3 mb-4">
              <div
                className={`p-3 rounded-xl border ${
                  confirmModal.type === 'cancel'
                    ? 'bg-amber-500/10 text-amber-400 border-amber-500/20'
                    : 'bg-red-500/10 text-red-400 border-red-500/20'
                }`}
              >
                {confirmModal.type === 'cancel' ? (
                  <Pause className="w-6 h-6" />
                ) : (
                  <Trash2 className="w-6 h-6" />
                )}
              </div>
              <div>
                <h3 className="text-base font-bold text-white">
                  {confirmModal.type === 'cancel' ? '¿Detener Descarga?' : '¿Eliminar Tarea?'}
                </h3>
                <p className="text-xs text-zinc-400">Confirmación requerida</p>
              </div>
            </div>

            <p className="text-xs text-zinc-300 leading-relaxed mb-6">
              {confirmModal.type === 'cancel' ? (
                <>
                  ¿Está seguro de detener y cancelar la descarga de{' '}
                  <strong className="text-white">"{confirmModal.taskTitle}"</strong>? El proceso se
                  interrumpirá inmediatamente.
                </>
              ) : (
                <>
                  ¿Desea eliminar del historial la tarea{' '}
                  <strong className="text-white">"{confirmModal.taskTitle}"</strong> y purgar
                  archivos temporales incompletos?
                </>
              )}
            </p>

            <div className="flex items-center justify-end gap-2.5">
              <button
                type="button"
                onClick={() => setConfirmModal({ isOpen: false, type: null, taskId: null, taskTitle: '' })}
                className="px-4 py-2 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs font-semibold rounded-xl transition-colors"
              >
                Cancelar
              </button>
              <button
                type="button"
                onClick={() => {
                  if (confirmModal.type === 'cancel') {
                    handleCancelTask(confirmModal.taskId);
                  } else {
                    handleDeleteTask(confirmModal.taskId);
                  }
                }}
                className={`px-4 py-2 text-white text-xs font-bold rounded-xl shadow-lg transition-colors ${
                  confirmModal.type === 'cancel'
                    ? 'bg-amber-600 hover:bg-amber-700'
                    : 'bg-red-600 hover:bg-red-700 shadow-red-900/30'
                }`}
              >
                {confirmModal.type === 'cancel' ? 'Detener Descarga' : 'Eliminar Tarea'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* =========================================================================
          MODAL EXPLORADOR DE CARPETAS DEL SERVIDOR
         ========================================================================= */}
      <FolderPickerModal
        isOpen={isFolderPickerOpen}
        onClose={() => setIsFolderPickerOpen(false)}
        initialPath={targetFolder}
        title="Seleccionar Carpeta de Destino para Ingesta"
        onSelect={(selectedPath) => {
          setTargetFolder(selectedPath);
          setIsFolderPickerOpen(false);
          showFeedback('info', `Carpeta de destino actualizada: ${selectedPath}`);
        }}
      />
    </div>
  );
}
