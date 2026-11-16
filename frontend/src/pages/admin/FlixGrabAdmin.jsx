import React, { useState, useEffect, useCallback, useMemo } from 'react';
import api from '../../services/api';
import {
  DownloadCloud,
  HardDrive,
  Gauge,
  Clock,
  CheckCircle2,
  AlertCircle,
  X,
  RotateCcw,
  Play,
  Search,
  Filter,
  Layers,
  Film,
  RefreshCw,
  Sliders,
  ShieldCheck,
  Zap,
  Trash2,
  Activity,
  ArrowRight,
  TrendingUp,
  UserCheck
} from 'lucide-react';

export default function FlixGrabAdmin() {
  const [jobs, setJobs] = useState([]);
  const [metrics, setMetrics] = useState({
    active_tasks: 0,
    completed_tasks: 0,
    failed_tasks: 0,
    total_speed: '0.0 MB/s',
    disk_free_formatted: 'Cargando...',
    disk_total_formatted: 'Cargando...',
    disk_free_mb: 0,
    disk_total_mb: 0,
    storage_path: '/storage/media',
    max_concurrent: 3,
  });
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('all'); // all, active, completed, failed
  const [feedback, setFeedback] = useState(null);

  // Formulario de nueva descarga manual
  const [showAddModal, setShowAddModal] = useState(false);
  const [newUrl, setNewUrl] = useState('');
  const [newTitle, setNewTitle] = useState('');
  const [newPriority, setNewPriority] = useState(5);
  const [newQuality, setNewQuality] = useState('1080p');
  const [submitting, setSubmitting] = useState(false);

  const showToast = useCallback((type, message) => {
    setFeedback({ type, message });
    setTimeout(() => {
      setFeedback((prev) => (prev?.message === message ? null : prev));
    }, 4500);
  }, []);

  // Carga de trabajos y telemetría de disco
  const fetchJobs = useCallback(async (silent = false) => {
    if (!silent) setRefreshing(true);
    try {
      const res = await api.get('/admin/flixgrab/jobs');
      if (res.data?.success) {
        setJobs(res.data.jobs || []);
        if (res.data.metrics) {
          setMetrics(res.data.metrics);
        }
      }
    } catch (err) {
      console.warn('Error cargando trabajos de FlixGrab:', err);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    fetchJobs();
    // Polling regular cada 3.5 segundos para reflejar progreso en vivo
    const timer = setInterval(() => {
      fetchJobs(true);
    }, 3500);
    return () => clearInterval(timer);
  }, [fetchJobs]);

  // Cambiar prioridad de un trabajo
  const handleUpdatePriority = async (jobId, newPrio) => {
    try {
      const res = await api.post(`/admin/flixgrab/${jobId}/priority`, { priority: newPrio });
      if (res.data?.success) {
        showToast('success', `Prioridad del trabajo actualizada a ${newPrio}.`);
        fetchJobs(true);
      }
    } catch (e) {
      showToast('error', 'No se pudo actualizar la prioridad.');
    }
  };

  // Reintentar trabajo
  const handleRetryJob = async (jobId) => {
    try {
      const res = await api.post(`/admin/flixgrab/${jobId}/retry`);
      if (res.data?.success) {
        showToast('success', 'Trabajo re-encolado con éxito.');
        fetchJobs(true);
      }
    } catch (e) {
      showToast('error', 'Error al reintentar descarga.');
    }
  };

  // Cancelar trabajo
  const handleCancelJob = async (jobId) => {
    try {
      const res = await api.post(`/download/${jobId}/cancel`);
      if (res.data?.success) {
        showToast('info', 'Trabajo cancelado.');
        fetchJobs(true);
      }
    } catch (e) {
      showToast('error', 'No fue posible cancelar el trabajo.');
    }
  };

  // Enviar nueva solicitud manual
  const handleCreateJob = async (e) => {
    e.preventDefault();
    if (!newUrl.trim()) return;
    setSubmitting(true);
    try {
      const res = await api.post('/download/request', {
        source_url: newUrl.trim(),
        title: newTitle.trim() || undefined,
        priority: parseInt(newPriority, 10),
        quality: newQuality,
        provider: 'flixgrab',
      });
      if (res.data?.success) {
        showToast('success', res.data.message || 'Descarga solicitada correctamente.');
        setNewUrl('');
        setNewTitle('');
        setShowAddModal(false);
        fetchJobs(true);
      } else {
        showToast('error', res.data?.message || 'Error al solicitar descarga.');
      }
    } catch (err) {
      showToast('error', err.response?.data?.message || 'Error al conectar con FlixGrab.');
    } finally {
      setSubmitting(false);
    }
  };

  // Filtrado reactivo en UI
  const filteredJobs = useMemo(() => {
    return jobs.filter((j) => {
      const q = searchQuery.toLowerCase().trim();
      const matchQuery =
        !q ||
        j.title?.toLowerCase().includes(q) ||
        j.source_url?.toLowerCase().includes(q) ||
        j.job_id?.toLowerCase().includes(q);

      if (!matchQuery) return false;

      if (statusFilter === 'active') {
        return ['QUEUED', 'DOWNLOADING', 'PROCESSING'].includes(j.status);
      }
      if (statusFilter === 'completed') {
        return j.status === 'READY';
      }
      if (statusFilter === 'failed') {
        return j.status === 'ERROR' || j.status === 'CANCELLED';
      }
      return true;
    });
  }, [jobs, searchQuery, statusFilter]);

  // Cálculo de porcentaje de disco usado
  const diskPercentage = useMemo(() => {
    if (!metrics.disk_total_mb || metrics.disk_total_mb <= 0) return 0;
    const used = metrics.disk_total_mb - metrics.disk_free_mb;
    return Math.min(100, Math.max(0, Math.round((used / metrics.disk_total_mb) * 100)));
  }, [metrics.disk_total_mb, metrics.disk_free_mb]);

  return (
    <div className="space-y-6 text-zinc-100 animate-fadeIn">
      {/* Toast Feedback */}
      {feedback && (
        <div
          className={`fixed top-20 right-8 z-50 px-4 py-3 rounded-xl border shadow-2xl flex items-center gap-3 backdrop-blur-md animate-slideIn ${
            feedback.type === 'success'
              ? 'bg-emerald-950/90 border-emerald-500/50 text-emerald-200'
              : feedback.type === 'error'
              ? 'bg-red-950/90 border-red-500/50 text-red-200'
              : 'bg-blue-950/90 border-blue-500/50 text-blue-200'
          }`}
        >
          {feedback.type === 'success' ? (
            <CheckCircle2 className="w-5 h-5 text-emerald-400" />
          ) : feedback.type === 'error' ? (
            <AlertCircle className="w-5 h-5 text-red-400" />
          ) : (
            <Zap className="w-5 h-5 text-blue-400" />
          )}
          <span className="text-sm font-medium">{feedback.message}</span>
        </div>
      )}

      {/* Cabecera y Botón Nueva Tarea */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-zinc-900/60 p-5 rounded-2xl border border-zinc-800">
        <div>
          <div className="flex items-center gap-2">
            <span className="p-2 rounded-xl bg-red-600/20 text-[#E50914] border border-red-500/30">
              <DownloadCloud className="w-6 h-6" />
            </span>
            <div>
              <h1 className="text-xl md:text-2xl font-black text-white tracking-tight flex items-center gap-2">
                FlixGrab Engine <span className="text-xs px-2 py-0.5 rounded bg-red-600/30 text-red-400 font-mono">v2.0</span>
              </h1>
              <p className="text-xs md:text-sm text-zinc-400">
                Gestor central de descargas en segundo plano, segmentación HLS y reproducción progresiva.
              </p>
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={() => fetchJobs(false)}
            disabled={refreshing}
            className="p-2.5 rounded-xl bg-zinc-800/80 hover:bg-zinc-700 text-zinc-300 hover:text-white border border-zinc-700/60 transition-all cursor-pointer"
            title="Refrescar lista"
          >
            <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin text-[#E50914]' : ''}`} />
          </button>
          <button
            onClick={() => setShowAddModal(true)}
            className="flex items-center gap-2 px-4 py-2.5 bg-[#E50914] hover:bg-red-700 text-white font-semibold rounded-xl transition-all shadow-lg shadow-red-900/20 text-sm cursor-pointer"
          >
            <DownloadCloud className="w-4 h-4" />
            <span>Nueva Descarga</span>
          </button>
        </div>
      </div>

      {/* Tarjetas de Métricas de Sistema */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Descargas Activas */}
        <div className="bg-zinc-900/70 border border-zinc-800/80 rounded-2xl p-4 flex items-center justify-between">
          <div>
            <p className="text-xs font-semibold text-zinc-400 uppercase tracking-wider">Activas / En Cola</p>
            <p className="text-2xl font-black text-white mt-1">{metrics.active_tasks}</p>
            <span className="text-[10px] text-zinc-500">Concurrencia: máx {metrics.max_concurrent}</span>
          </div>
          <div className="p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl text-amber-400">
            <Activity className="w-6 h-6 animate-pulse" />
          </div>
        </div>

        {/* Velocidad Agregada */}
        <div className="bg-zinc-900/70 border border-zinc-800/80 rounded-2xl p-4 flex items-center justify-between">
          <div>
            <p className="text-xs font-semibold text-zinc-400 uppercase tracking-wider">Velocidad Total</p>
            <p className="text-2xl font-black text-emerald-400 mt-1">{metrics.total_speed}</p>
            <span className="text-[10px] text-zinc-500">Fibra óptica On-Net</span>
          </div>
          <div className="p-3 bg-emerald-500/10 border border-emerald-500/20 rounded-xl text-emerald-400">
            <TrendingUp className="w-6 h-6" />
          </div>
        </div>

        {/* Completadas / Listas */}
        <div className="bg-zinc-900/70 border border-zinc-800/80 rounded-2xl p-4 flex items-center justify-between">
          <div>
            <p className="text-xs font-semibold text-zinc-400 uppercase tracking-wider">Completadas (Ready)</p>
            <p className="text-2xl font-black text-blue-400 mt-1">{metrics.completed_tasks}</p>
            <span className="text-[10px] text-zinc-500">Indexadas al catálogo</span>
          </div>
          <div className="p-3 bg-blue-500/10 border border-blue-500/20 rounded-xl text-blue-400">
            <CheckCircle2 className="w-6 h-6" />
          </div>
        </div>

        {/* Almacenamiento en Disco */}
        <div className="bg-zinc-900/70 border border-zinc-800/80 rounded-2xl p-4">
          <div className="flex items-center justify-between mb-2">
            <p className="text-xs font-semibold text-zinc-400 uppercase tracking-wider">Disco Libre</p>
            <span className="text-xs font-mono font-bold text-red-400">{metrics.disk_free_formatted}</span>
          </div>
          <div className="w-full bg-zinc-800 h-2.5 rounded-full overflow-hidden">
            <div
              className={`h-full transition-all duration-500 ${
                diskPercentage > 85 ? 'bg-red-600' : diskPercentage > 65 ? 'bg-amber-500' : 'bg-emerald-500'
              }`}
              style={{ width: `${diskPercentage}%` }}
            />
          </div>
          <p className="text-[10px] text-zinc-500 mt-2">
            {diskPercentage}% usado de {metrics.disk_total_formatted} ({metrics.storage_path})
          </p>
        </div>
      </div>

      {/* Barra de Filtros y Búsqueda */}
      <div className="flex flex-col sm:flex-row items-center justify-between gap-3 bg-zinc-900/40 p-3 rounded-xl border border-zinc-800">
        <div className="relative w-full sm:w-80">
          <Search className="w-4 h-4 text-zinc-500 absolute left-3 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Buscar por título o URL..."
            className="w-full bg-zinc-950 border border-zinc-800 rounded-lg pl-9 pr-3 py-2 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-red-500"
          />
        </div>

        <div className="flex items-center gap-1 w-full sm:w-auto overflow-x-auto">
          {[
            { id: 'all', label: 'Todas' },
            { id: 'active', label: 'En Progreso' },
            { id: 'completed', label: 'Completadas' },
            { id: 'failed', label: 'Fallidas' },
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => setStatusFilter(tab.id)}
              className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors whitespace-nowrap cursor-pointer ${
                statusFilter === tab.id
                  ? 'bg-red-600 text-white font-bold'
                  : 'bg-zinc-800/60 text-zinc-400 hover:text-white hover:bg-zinc-800'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {/* Tabla de Trabajos */}
      <div className="bg-zinc-900/60 border border-zinc-800 rounded-2xl overflow-hidden shadow-xl">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-xs">
            <thead>
              <tr className="border-b border-zinc-800 bg-zinc-950/70 text-zinc-400 uppercase tracking-wider font-semibold">
                <th className="py-3 px-4">Título / Contenido</th>
                <th className="py-3 px-4">Estado</th>
                <th className="py-3 px-4">Progreso</th>
                <th className="py-3 px-4">Velocidad / ETA</th>
                <th className="py-3 px-4">Prioridad</th>
                <th className="py-3 px-4">Solicitante</th>
                <th className="py-3 px-4 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-800/60">
              {loading && jobs.length === 0 ? (
                <tr>
                  <td colSpan={7} className="py-12 text-center text-zinc-500">
                    <RefreshCw className="w-6 h-6 animate-spin mx-auto mb-2 text-red-500" />
                    Cargando trabajos de FlixGrab...
                  </td>
                </tr>
              ) : filteredJobs.length === 0 ? (
                <tr>
                  <td colSpan={7} className="py-12 text-center text-zinc-500">
                    No hay descargas registradas que coincidan con el filtro.
                  </td>
                </tr>
              ) : (
                filteredJobs.map((job) => {
                  const isActive = ['QUEUED', 'DOWNLOADING', 'PROCESSING'].includes(job.status);
                  const isReady = job.status === 'READY';
                  const isError = job.status === 'ERROR' || job.status === 'CANCELLED';

                  return (
                    <tr key={job.id || job.job_id} className="hover:bg-zinc-800/30 transition-colors">
                      {/* Título y Fuente */}
                      <td className="py-3.5 px-4">
                        <div className="font-semibold text-white max-w-xs truncate" title={job.title}>
                          {job.title}
                        </div>
                        <div className="text-[10px] text-zinc-500 truncate max-w-xs" title={job.source_url}>
                          {job.source_url}
                        </div>
                        <div className="flex items-center gap-1.5 mt-1">
                          <span className="px-1.5 py-0.5 rounded text-[9px] bg-zinc-800 text-zinc-300 font-mono">
                            {job.provider || 'FlixGrab'}
                          </span>
                          <span className="px-1.5 py-0.5 rounded text-[9px] bg-zinc-800/80 text-zinc-400 font-mono">
                            {job.quality || '1080p'}
                          </span>
                          {job.can_play_progressive && (
                            <span className="px-1.5 py-0.5 rounded text-[9px] bg-emerald-950 text-emerald-400 font-bold border border-emerald-500/30 flex items-center gap-0.5">
                              <Zap className="w-2.5 h-2.5" /> Progresivo ({job.segments_ready} segs)
                            </span>
                          )}
                        </div>
                      </td>

                      {/* Estado */}
                      <td className="py-3.5 px-4 whitespace-nowrap">
                        <span
                          className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider ${
                            job.status === 'DOWNLOADING'
                              ? 'bg-amber-500/20 text-amber-400 border border-amber-500/40 animate-pulse'
                              : job.status === 'PROCESSING'
                              ? 'bg-purple-500/20 text-purple-400 border border-purple-500/40'
                              : job.status === 'READY'
                              ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/40'
                              : job.status === 'QUEUED'
                              ? 'bg-sky-500/20 text-sky-400 border border-sky-500/40'
                              : 'bg-red-500/20 text-red-400 border border-red-500/40'
                          }`}
                        >
                          <span className="w-1.5 h-1.5 rounded-full bg-current" />
                          {job.status}
                        </span>
                      </td>

                      {/* Progreso */}
                      <td className="py-3.5 px-4 min-w-[140px]">
                        <div className="flex items-center justify-between text-[11px] font-mono mb-1">
                          <span className="text-white font-bold">{Math.round(job.progress || 0)}%</span>
                          <span className="text-zinc-500">{job.formatted_downloaded_bytes || '0 MB'}</span>
                        </div>
                        <div className="w-full bg-zinc-800 h-2 rounded-full overflow-hidden">
                          <div
                            className={`h-full transition-all duration-300 ${
                              isReady
                                ? 'bg-emerald-500'
                                : isError
                                ? 'bg-red-600'
                                : 'bg-[#E50914]'
                            }`}
                            style={{ width: `${Math.min(100, Math.max(0, job.progress || 0))}%` }}
                          />
                        </div>
                      </td>

                      {/* Velocidad / ETA */}
                      <td className="py-3.5 px-4 whitespace-nowrap">
                        <div className="font-mono text-zinc-200 font-semibold">{job.download_speed || '0.0 MB/s'}</div>
                        <div className="text-[10px] text-zinc-500 flex items-center gap-1 mt-0.5">
                          <Clock className="w-3 h-3" />
                          <span>ETA: {job.eta || '--:--'}</span>
                        </div>
                      </td>

                      {/* Prioridad con selector */}
                      <td className="py-3.5 px-4 whitespace-nowrap">
                        <select
                          value={job.priority || 5}
                          onChange={(e) => handleUpdatePriority(job.id, parseInt(e.target.value, 10))}
                          className="bg-zinc-950 border border-zinc-800 rounded px-2 py-1 text-xs text-white focus:outline-none focus:border-red-500"
                        >
                          <option value="1">1 (Muy Alta)</option>
                          <option value="3">3 (Alta)</option>
                          <option value="5">5 (Normal)</option>
                          <option value="8">8 (Baja)</option>
                          <option value="10">10 (Mínima)</option>
                        </select>
                      </td>

                      {/* Solicitante */}
                      <td className="py-3.5 px-4 whitespace-nowrap">
                        <div className="text-zinc-300 font-medium">{job.user?.username || job.user?.name || 'Sistema'}</div>
                        <div className="text-[10px] text-zinc-500">
                          {job.started_at ? new Date(job.started_at).toLocaleTimeString() : 'En espera'}
                        </div>
                      </td>

                      {/* Acciones */}
                      <td className="py-3.5 px-4 text-right whitespace-nowrap">
                        <div className="flex items-center justify-end gap-1.5">
                          {isActive && (
                            <button
                              onClick={() => handleCancelJob(job.id)}
                              className="p-1.5 rounded-lg bg-zinc-800 hover:bg-red-950 text-zinc-400 hover:text-red-400 border border-zinc-700 transition-colors cursor-pointer"
                              title="Cancelar Descarga"
                            >
                              <X className="w-3.5 h-3.5" />
                            </button>
                          )}
                          {isError && (
                            <button
                              onClick={() => handleRetryJob(job.id)}
                              className="p-1.5 rounded-lg bg-zinc-800 hover:bg-amber-950 text-zinc-400 hover:text-amber-400 border border-zinc-700 transition-colors cursor-pointer"
                              title="Reintentar Descarga"
                            >
                              <RotateCcw className="w-3.5 h-3.5" />
                            </button>
                          )}
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

      {/* Modal Nueva Descarga */}
      {showAddModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm p-4">
          <div className="bg-zinc-900 border border-zinc-800 rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-2xl animate-scaleUp">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
              <h3 className="text-lg font-bold text-white flex items-center gap-2">
                <DownloadCloud className="w-5 h-5 text-[#E50914]" />
                Nueva Tarea de Descarga FlixGrab
              </h3>
              <button onClick={() => setShowAddModal(false)} className="text-zinc-400 hover:text-white">
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleCreateJob} className="space-y-4 text-xs">
              <div>
                <label className="block text-zinc-300 font-semibold mb-1">URL de Origen Autorizada *</label>
                <input
                  type="url"
                  required
                  value={newUrl}
                  onChange={(e) => setNewUrl(e.target.value)}
                  placeholder="https://archive.org/download/.../movie.mp4"
                  className="w-full bg-zinc-950 border border-zinc-800 rounded-xl px-3 py-2.5 text-white focus:outline-none focus:border-red-500 font-mono text-xs"
                />
              </div>

              <div>
                <label className="block text-zinc-300 font-semibold mb-1">Título de la Película / Serie</label>
                <input
                  type="text"
                  value={newTitle}
                  onChange={(e) => setNewTitle(e.target.value)}
                  placeholder="Ej: Big Buck Bunny (2008)"
                  className="w-full bg-zinc-950 border border-zinc-800 rounded-xl px-3 py-2.5 text-white focus:outline-none focus:border-red-500 text-xs"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-zinc-300 font-semibold mb-1">Prioridad de Descarga</label>
                  <select
                    value={newPriority}
                    onChange={(e) => setNewPriority(e.target.value)}
                    className="w-full bg-zinc-950 border border-zinc-800 rounded-xl px-3 py-2.5 text-white focus:outline-none focus:border-red-500 text-xs"
                  >
                    <option value="1">1 (Muy Alta / Streaming Inmediato)</option>
                    <option value="3">3 (Alta)</option>
                    <option value="5">5 (Normal)</option>
                    <option value="8">8 (Baja)</option>
                  </select>
                </div>
                <div>
                  <label className="block text-zinc-300 font-semibold mb-1">Perfil de Calidad</label>
                  <select
                    value={newQuality}
                    onChange={(e) => setNewQuality(e.target.value)}
                    className="w-full bg-zinc-950 border border-zinc-800 rounded-xl px-3 py-2.5 text-white focus:outline-none focus:border-red-500 text-xs"
                  >
                    <option value="1080p">1080p Full HD</option>
                    <option value="720p">720p HD</option>
                    <option value="4K">4K Ultra HD</option>
                  </select>
                </div>
              </div>

              <div className="pt-3 flex justify-end gap-2 border-t border-zinc-800">
                <button
                  type="button"
                  onClick={() => setShowAddModal(false)}
                  className="px-4 py-2 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 rounded-xl cursor-pointer"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="px-5 py-2 bg-[#E50914] hover:bg-red-700 text-white font-bold rounded-xl shadow-lg cursor-pointer"
                >
                  {submitting ? 'Enviando...' : 'Iniciar Descarga'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
