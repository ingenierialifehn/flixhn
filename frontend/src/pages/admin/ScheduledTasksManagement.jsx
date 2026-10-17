import React, { useState, useEffect, useCallback, useRef } from 'react';
import api from '../../services/api';
import {
  CalendarClock,
  RefreshCw,
  Play,
  Clock,
  CheckCircle2,
  AlertTriangle,
  AlertCircle,
  X,
  Loader2,
  Server,
  Database,
  Cpu,
  Film,
  Tv,
  BarChart3,
  Copy,
  Check,
  Terminal,
  Activity,
  Layers,
  Sparkles,
} from 'lucide-react';

const CATEGORY_ICONS = {
  application: Server,
  database: Database,
  conversions: Cpu,
  library: Film,
  livetv: Tv,
  statistics: BarChart3,
};

// Tareas identificadas como intensivas en I/O o base de datos que requieren confirmación
const HEAVY_TASKS = ['scan_media', 'optimize_database'];

export default function ScheduledTasksManagement() {
  const [tasks, setTasks] = useState([]);
  const [grouped, setGrouped] = useState({});
  const [categoriesMeta, setCategoriesMeta] = useState({});
  const [summary, setSummary] = useState({
    total: 0,
    running: 0,
    completed: 0,
    failed: 0,
    idle: 0,
  });

  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [runningTaskKeys, setRunningTaskKeys] = useState(new Set());

  // Sistema de Notificaciones Toast (Cero alertas nativas)
  const [toast, setToast] = useState(null); // { type: 'success' | 'error' | 'info', message: string, title?: string }

  const showToast = useCallback((type, message, title = '') => {
    setToast({ type, message, title });
  }, []);

  useEffect(() => {
    if (!toast) return;
    const timer = setTimeout(() => {
      setToast(null);
    }, 4500);
    return () => clearTimeout(timer);
  }, [toast]);

  // Modal de confirmación para tareas pesadas
  const [confirmModal, setConfirmModal] = useState({
    open: false,
    task: null,
  });

  // Modal de detalles de error
  const [errorModal, setErrorModal] = useState({
    open: false,
    task: null,
    copied: false,
  });

  // Carga de tareas desde la API
  const fetchTasks = useCallback(async (isSilent = false) => {
    if (!isSilent) setRefreshing(true);

    try {
      const res = await api.get('/admin/tasks');
      if (res.data?.status === 'success' && res.data?.data) {
        const { tasks: taskList, grouped: groupedData, categories, summary: summaryData } = res.data.data;
        setTasks(taskList || []);
        setGrouped(groupedData || {});
        setCategoriesMeta(categories || {});
        setSummary(summaryData || { total: 0, running: 0, completed: 0, failed: 0, idle: 0 });

        // Actualizar tareas actualmente en ejecución
        const currentlyRunning = new Set();
        (taskList || []).forEach((t) => {
          if (t.status === 'running') {
            currentlyRunning.add(t.key);
          }
        });
        setRunningTaskKeys(currentlyRunning);
      }
    } catch (err) {
      console.error('Error al cargar tareas programadas:', err);
      if (!isSilent) {
        showToast('error', 'No fue posible obtener el estado de las tareas programadas.', 'Error del Servidor');
      }
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [showToast]);

  // Carga inicial
  useEffect(() => {
    fetchTasks();
  }, [fetchTasks]);

  // Polling automático mientras existan tareas en ejecución
  const pollIntervalRef = useRef(null);

  useEffect(() => {
    if (summary.running > 0 || runningTaskKeys.size > 0) {
      if (!pollIntervalRef.current) {
        pollIntervalRef.current = setInterval(() => {
          fetchTasks(true);
        }, 2500);
      }
    } else {
      if (pollIntervalRef.current) {
        clearInterval(pollIntervalRef.current);
        pollIntervalRef.current = null;
      }
    }

    return () => {
      if (pollIntervalRef.current) {
        clearInterval(pollIntervalRef.current);
        pollIntervalRef.current = null;
      }
    };
  }, [summary.running, runningTaskKeys.size, fetchTasks]);

  // Ejecución de tarea bajo demanda
  const executeTask = async (task) => {
    if (!task) return;

    // Actualización optimista en interfaz
    setRunningTaskKeys((prev) => new Set(prev).add(task.key));
    setTasks((prev) =>
      prev.map((t) => (t.key === task.key ? { ...t, status: 'running' } : t))
    );
    setGrouped((prev) => {
      const nextGrouped = { ...prev };
      if (nextGrouped[task.category]) {
        nextGrouped[task.category] = {
          ...nextGrouped[task.category],
          tasks: nextGrouped[task.category].tasks.map((t) =>
            t.key === task.key ? { ...t, status: 'running' } : t
          ),
        };
      }
      return nextGrouped;
    });

    showToast('info', `Iniciando ejecución de: ${task.name}...`, 'Tarea Lanzada');

    try {
      const res = await api.post(`/admin/tasks/${task.key}/run`);

      if (res.data?.status === 'success') {
        showToast(
          'success',
          res.data.message || `La tarea ${task.name} finalizó exitosamente.`,
          'Tarea Completada'
        );
      } else {
        showToast(
          'error',
          res.data?.message || `La tarea ${task.name} no se completó satisfactoriamente.`,
          'Fallo de Ejecución'
        );
      }
    } catch (err) {
      const errMsg = err.response?.data?.message || err.message || 'Error desconocido';
      showToast('error', `Error en ${task.name}: ${errMsg}`, 'Fallo de Tarea');
    } finally {
      // Sincronizar estado final real
      fetchTasks(true);
    }
  };

  // Manejador del botón Play con interceptación para tareas pesadas
  const handlePlayClick = (task) => {
    if (HEAVY_TASKS.includes(task.key)) {
      setConfirmModal({
        open: true,
        task,
      });
      return;
    }
    executeTask(task);
  };

  // Confirmar ejecución de tarea pesada
  const handleConfirmHeavyTask = () => {
    const taskToRun = confirmModal.task;
    setConfirmModal({ open: false, task: null });
    if (taskToRun) {
      executeTask(taskToRun);
    }
  };

  // Abrir modal de detalles de error
  const handleOpenErrorModal = (task) => {
    setErrorModal({
      open: true,
      task,
      copied: false,
    });
  };

  // Copiar traza de error al portapapeles
  const handleCopyErrorTrace = () => {
    if (!errorModal.task?.last_error_message) return;
    navigator.clipboard.writeText(errorModal.task.last_error_message);
    setErrorModal((prev) => ({ ...prev, copied: true }));
    setTimeout(() => {
      setErrorModal((prev) => ({ ...prev, copied: false }));
    }, 2500);
  };

  return (
    <div className="space-y-6 pb-12 select-none">
      {/* 1. Header con Título, Descripción y Botón Global de Actualización */}
      <div className="bg-[#181818] border border-zinc-800 rounded-2xl p-6 shadow-lg relative overflow-hidden transition-all duration-200">
        <div className="absolute top-0 right-0 w-96 h-96 bg-red-600/5 rounded-full blur-3xl pointer-events-none -mr-20 -mt-20" />

        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6 relative z-10">
          <div className="space-y-1.5">
            <div className="flex items-center gap-3">
              <div className="p-2.5 bg-red-600/10 border border-red-600/20 rounded-xl text-[#E50914] shadow-inner">
                <CalendarClock className="w-6 h-6" />
              </div>
              <div>
                <h1 className="text-xl md:text-2xl font-black tracking-wide text-zinc-100 flex items-center gap-2">
                  Tareas Programadas
                  <span className="text-[11px] font-mono uppercase bg-zinc-900 text-zinc-400 px-2 py-0.5 rounded border border-zinc-800 tracking-wider">
                    FlixHN Scheduler
                  </span>
                </h1>
                <p className="text-xs md:text-sm text-zinc-400 max-w-3xl leading-relaxed mt-0.5">
                  Programador de tareas del servidor FlixHN para sincronización del catálogo de medios,
                  optimización de la base de datos, purga de registros y mantenimiento periódico del ISP.
                </p>
              </div>
            </div>
          </div>

          <div className="flex items-center gap-3 shrink-0">
            <button
              onClick={() => fetchTasks(false)}
              disabled={refreshing}
              className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-zinc-900 hover:bg-zinc-800 text-zinc-200 border border-zinc-700/80 hover:border-zinc-600 text-xs md:text-sm font-semibold transition-all shadow-sm active:scale-95 disabled:opacity-60 cursor-pointer"
              title="Consultar y sincronizar estados actuales"
            >
              <RefreshCw className={`w-4 h-4 text-zinc-400 ${refreshing ? 'animate-spin text-red-500' : ''}`} />
              <span>{refreshing ? 'Actualizando...' : 'Actualizar Estados'}</span>
            </button>
          </div>
        </div>

        {/* 2. Tarjetas de Resumen KPI */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-6 mt-6 border-t border-zinc-800/80">
          <div className="bg-[#141414]/90 border border-zinc-800/80 rounded-xl p-3.5 flex items-center justify-between">
            <div>
              <p className="text-[11px] font-medium text-zinc-400 uppercase tracking-wider">Total Tareas</p>
              <p className="text-xl font-bold text-zinc-100 mt-0.5">{summary.total}</p>
            </div>
            <div className="p-2 bg-zinc-800/60 rounded-lg text-zinc-400">
              <Layers className="w-4 h-4" />
            </div>
          </div>

          <div className="bg-[#141414]/90 border border-zinc-800/80 rounded-xl p-3.5 flex items-center justify-between">
            <div>
              <p className="text-[11px] font-medium text-zinc-400 uppercase tracking-wider">En Ejecución</p>
              <p className="text-xl font-bold text-amber-400 mt-0.5 flex items-center gap-2">
                {summary.running}
                {summary.running > 0 && <span className="w-2 h-2 rounded-full bg-amber-400 animate-ping" />}
              </p>
            </div>
            <div className="p-2 bg-amber-500/10 rounded-lg text-amber-400">
              <Activity className="w-4 h-4" />
            </div>
          </div>

          <div className="bg-[#141414]/90 border border-zinc-800/80 rounded-xl p-3.5 flex items-center justify-between">
            <div>
              <p className="text-[11px] font-medium text-zinc-400 uppercase tracking-wider">Completadas</p>
              <p className="text-xl font-bold text-emerald-400 mt-0.5">{summary.completed}</p>
            </div>
            <div className="p-2 bg-emerald-500/10 rounded-lg text-emerald-400">
              <CheckCircle2 className="w-4 h-4" />
            </div>
          </div>

          <div className="bg-[#141414]/90 border border-zinc-800/80 rounded-xl p-3.5 flex items-center justify-between">
            <div>
              <p className="text-[11px] font-medium text-zinc-400 uppercase tracking-wider">Fallidas</p>
              <p className="text-xl font-bold text-red-400 mt-0.5">{summary.failed}</p>
            </div>
            <div className="p-2 bg-red-500/10 rounded-lg text-red-400">
              <AlertCircle className="w-4 h-4" />
            </div>
          </div>
        </div>
      </div>

      {/* 3. Contenido Agrupado por Categorías */}
      {loading ? (
        <div className="space-y-6">
          {[1, 2, 3].map((i) => (
            <div key={i} className="bg-[#181818] border border-zinc-800 rounded-2xl p-6 space-y-4 animate-pulse">
              <div className="h-6 w-48 bg-zinc-800 rounded-md" />
              <div className="space-y-3">
                <div className="h-16 bg-zinc-900/60 rounded-xl" />
                <div className="h-16 bg-zinc-900/60 rounded-xl" />
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="space-y-6">
          {Object.entries(grouped).map(([categoryKey, categoryData]) => {
            const meta = categoryData.meta || {};
            const categoryTasks = categoryData.tasks || [];
            const IconComponent = CATEGORY_ICONS[categoryKey] || Server;

            return (
              <section
                key={categoryKey}
                className="bg-[#181818] border border-zinc-800/90 rounded-2xl shadow-md overflow-hidden transition-all duration-200"
              >
                {/* Cabecera de la Categoría */}
                <div className="px-6 py-4 bg-[#1b1b1b] border-b border-zinc-800/90 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                  <div className="flex items-center gap-3">
                    <div className="p-2 bg-zinc-800/80 text-zinc-300 rounded-lg border border-zinc-700/60">
                      <IconComponent className="w-5 h-5 text-red-500" />
                    </div>
                    <div>
                      <h2 className="text-base font-bold text-zinc-100 flex items-center gap-2">
                        {meta.label || meta.name}
                        <span className="text-xs font-normal text-zinc-400">
                          ({categoryTasks.length} {categoryTasks.length === 1 ? 'tarea' : 'tareas'})
                        </span>
                      </h2>
                      <p className="text-xs text-zinc-400 mt-0.5">
                        {meta.description}
                      </p>
                    </div>
                  </div>
                </div>

                {/* Lista de Filas de Tareas */}
                <div className="p-4 sm:p-6 divide-y divide-zinc-800/60 space-y-3 sm:space-y-0">
                  {categoryTasks.map((task) => {
                    const isRunning = task.status === 'running' || runningTaskKeys.has(task.key);
                    const isFailed = task.status === 'failed';
                    const isCompleted = task.status === 'completed';

                    return (
                      <div
                        key={task.key}
                        className="py-3.5 sm:px-3 first:pt-0 last:pb-0 flex flex-col md:flex-row md:items-center justify-between gap-4 rounded-xl hover:bg-zinc-900/40 transition-colors"
                      >
                        {/* Detalle de Tarea (Izquierda) */}
                        <div className="space-y-1.5 flex-1 min-w-0 pr-2">
                          <div className="flex flex-wrap items-center gap-2.5">
                            <h3 className="text-sm md:text-base font-bold text-zinc-100 tracking-wide">
                              {task.name}
                            </h3>

                            {/* Badge de Estado Dinámico */}
                            {isRunning && (
                              <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-400 border border-amber-500/30 animate-pulse">
                                <Loader2 className="w-3 h-3 animate-spin" />
                                <span>En ejecución...</span>
                              </span>
                            )}

                            {isCompleted && (
                              <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                                <CheckCircle2 className="w-3 h-3" />
                                <span>Completada</span>
                              </span>
                            )}

                            {isFailed && (
                              <button
                                onClick={() => handleOpenErrorModal(task)}
                                className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/40 transition-colors cursor-pointer group/badge"
                                title="Haga clic para ver el registro completo del error"
                              >
                                <AlertCircle className="w-3 h-3 text-red-400 group-hover/badge:scale-110 transition-transform" />
                                <span>(Failed)</span>
                                <span className="underline ml-1 text-[11px] text-red-300">Ver detalles</span>
                              </button>
                            )}

                            {!isRunning && !isCompleted && !isFailed && (
                              <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-zinc-800/80 text-zinc-400 border border-zinc-700/60">
                                Inactiva
                              </span>
                            )}
                          </div>

                          <p className="text-xs md:text-sm text-zinc-400 leading-relaxed">
                            {task.description}
                          </p>

                          {/* Metadatos de última ejecución */}
                          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-400 pt-0.5">
                            <span className="inline-flex items-center gap-1.5">
                              <Clock className="w-3.5 h-3.5 text-zinc-400" />
                              <span>Última ejecución:</span>
                              <span className="text-zinc-200 font-medium">
                                {task.last_run_diff || 'Nunca'}
                              </span>
                              {task.last_run_formatted && task.last_run_formatted !== 'Nunca' && (
                                <span className="text-zinc-400 text-[11px]">
                                  ({task.last_run_formatted})
                                </span>
                              )}
                            </span>

                            <span className="text-zinc-700 hidden sm:inline">•</span>

                            <span className="text-zinc-400">
                              Duración:{' '}
                              <span className="text-zinc-200 font-medium">
                                {task.formatted_duration || `${task.last_duration_seconds} s`}
                              </span>
                            </span>
                          </div>
                        </div>

                        {/* Botón de Acción a la Derecha */}
                        <div className="flex items-center gap-2 self-start md:self-center shrink-0">
                          {isRunning ? (
                            <button
                              disabled
                              className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-zinc-900 border border-zinc-800 text-zinc-400 text-xs font-semibold cursor-not-allowed opacity-80"
                            >
                              <Loader2 className="w-4 h-4 animate-spin text-amber-400" />
                              <span>Ejecutando...</span>
                            </button>
                          ) : (
                            <button
                              onClick={() => handlePlayClick(task)}
                              className="group/btn inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-zinc-900 hover:bg-[#E50914] text-zinc-200 hover:text-white border border-zinc-700/80 hover:border-red-600 text-xs font-bold transition-all duration-200 shadow-sm hover:shadow-red-600/20 active:scale-95 cursor-pointer"
                              title={`Ejecutar ${task.name} ahora`}
                            >
                              <Play className="w-3.5 h-3.5 fill-current transition-transform group-hover/btn:scale-110" />
                              <span>Ejecutar</span>
                            </button>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </section>
            );
          })}
        </div>
      )}

      {/* 4. Modal Personalizado de Confirmación para Tareas Pesadas */}
      {confirmModal.open && confirmModal.task && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fade-in">
          <div className="bg-[#181818] border border-zinc-800 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-5 relative">
            <div className="flex items-start gap-4">
              <div className="p-3 bg-amber-500/10 border border-amber-500/30 text-amber-400 rounded-xl shrink-0">
                <AlertTriangle className="w-6 h-6" />
              </div>
              <div className="space-y-1 flex-1">
                <h3 className="text-lg font-bold text-zinc-100">
                  Confirmar Tarea Pesada
                </h3>
                <p className="text-xs text-zinc-400">
                  Esta acción consume recursos considerables de entrada/salida y procesador.
                </p>
              </div>
              <button
                onClick={() => setConfirmModal({ open: false, task: null })}
                className="text-zinc-400 hover:text-zinc-100 p-1 rounded-lg hover:bg-zinc-800 transition-colors"
                title="Cerrar"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="bg-[#121212] border border-zinc-800/80 rounded-xl p-3.5 space-y-1">
              <p className="text-sm font-semibold text-zinc-200">
                {confirmModal.task.name}
              </p>
              <p className="text-xs text-zinc-400">
                {confirmModal.task.description}
              </p>
            </div>

            <p className="text-xs text-zinc-400 leading-relaxed">
              ¿Deseas iniciar la ejecución en segundo plano de esta tarea ahora mismo?
            </p>

            <div className="flex items-center justify-end gap-3 pt-2">
              <button
                onClick={() => setConfirmModal({ open: false, task: null })}
                className="px-4 py-2 rounded-xl text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-800 hover:bg-zinc-700 transition-colors cursor-pointer"
              >
                Cancelar
              </button>
              <button
                onClick={handleConfirmHeavyTask}
                className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#b80710] shadow-md hover:shadow-red-600/30 transition-all cursor-pointer"
              >
                <Play className="w-3.5 h-3.5 fill-current" />
                <span>Sí, ejecutar tarea</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 5. Modal de Detalles de Error */}
      {errorModal.open && errorModal.task && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fade-in">
          <div className="bg-[#181818] border border-red-900/40 rounded-2xl max-w-2xl w-full p-6 shadow-2xl flex flex-col max-h-[85vh] space-y-4">
            <div className="flex items-start justify-between gap-4 pb-3 border-b border-zinc-800">
              <div className="flex items-center gap-3">
                <div className="p-2.5 bg-red-600/10 border border-red-600/30 text-red-500 rounded-xl">
                  <AlertCircle className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-base font-bold text-zinc-100 flex items-center gap-2">
                    Diagnóstico de Error
                    <span className="text-[11px] font-mono text-red-400 bg-red-500/10 px-2 py-0.5 rounded border border-red-500/20">
                      Fallida
                    </span>
                  </h3>
                  <p className="text-xs text-zinc-400 mt-0.5">
                    Tarea: <span className="text-zinc-200 font-semibold">{errorModal.task.name}</span>
                  </p>
                </div>
              </div>

              <button
                onClick={() => setErrorModal({ open: false, task: null, copied: false })}
                className="text-zinc-400 hover:text-zinc-100 p-1 rounded-lg hover:bg-zinc-800 transition-colors cursor-pointer"
                title="Cerrar modal"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="flex items-center justify-between text-xs text-zinc-400 px-1">
              <span>
                Ocurrido: <strong className="text-zinc-300 font-medium">{errorModal.task.last_run_formatted || 'Desconocido'}</strong>
              </span>
              <button
                onClick={handleCopyErrorTrace}
                className="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-zinc-900 hover:bg-zinc-800 text-zinc-300 border border-zinc-700/80 hover:text-white transition-colors cursor-pointer text-xs"
              >
                {errorModal.copied ? (
                  <>
                    <Check className="w-3.5 h-3.5 text-emerald-400" />
                    <span className="text-emerald-400 font-medium">¡Copiado!</span>
                  </>
                ) : (
                  <>
                    <Copy className="w-3.5 h-3.5 text-zinc-400" />
                    <span>Copiar traza</span>
                  </>
                )}
              </button>
            </div>

            {/* Bloque con Stack Trace */}
            <div className="flex-1 overflow-y-auto bg-[#0d0d0d] border border-zinc-800 rounded-xl p-4 font-mono text-xs text-red-300/90 whitespace-pre-wrap leading-relaxed select-text shadow-inner">
              {errorModal.task.last_error_message || 'No se registró ningún mensaje de excepción detallado.'}
            </div>

            <div className="flex items-center justify-between gap-3 pt-3 border-t border-zinc-800">
              <span className="text-xs text-zinc-400">
                Identificador clave: <code className="text-zinc-300 font-mono">{errorModal.task.key}</code>
              </span>

              <div className="flex items-center gap-2">
                <button
                  onClick={() => setErrorModal({ open: false, task: null, copied: false })}
                  className="px-4 py-2 rounded-xl text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-800 hover:bg-zinc-700 transition-colors cursor-pointer"
                >
                  Cerrar
                </button>
                <button
                  onClick={() => {
                    const taskToReplay = errorModal.task;
                    setErrorModal({ open: false, task: null, copied: false });
                    if (taskToReplay) {
                      executeTask(taskToReplay);
                    }
                  }}
                  className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#b80710] shadow-md transition-all cursor-pointer"
                >
                  <Play className="w-3.5 h-3.5 fill-current" />
                  <span>Reintentar Tarea</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* 6. Sistema de Notificaciones Toast Flotantes (Cero alertas nativas) */}
      {toast && (
        <div className="fixed bottom-6 right-6 z-50 flex items-start gap-3 p-4 rounded-xl shadow-2xl max-w-md w-full border backdrop-blur-md animate-slide-up transition-all bg-[#181818]/95 border-zinc-700 text-zinc-100">
          <div className="shrink-0 mt-0.5">
            {toast.type === 'success' && <CheckCircle2 className="w-5 h-5 text-emerald-400" />}
            {toast.type === 'error' && <AlertCircle className="w-5 h-5 text-red-400" />}
            {toast.type === 'info' && <RefreshCw className="w-5 h-5 text-red-500 animate-spin" />}
          </div>

          <div className="flex-1 min-w-0">
            {toast.title && (
              <h4 className="text-xs font-bold text-zinc-100 uppercase tracking-wide">
                {toast.title}
              </h4>
            )}
            <p className="text-xs text-zinc-300 mt-0.5 leading-relaxed">
              {toast.message}
            </p>
          </div>

          <button
            onClick={() => setToast(null)}
            className="text-zinc-400 hover:text-zinc-200 p-0.5 rounded transition-colors"
            title="Cerrar notificación"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}
    </div>
  );
}
