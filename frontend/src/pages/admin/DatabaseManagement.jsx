import React, { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';
import {
  Database,
  Archive,
  HardDrive,
  Sliders,
  Play,
  RotateCcw,
  Trash2,
  Download,
  AlertTriangle,
  CheckCircle2,
  AlertCircle,
  X,
  Loader2,
  Clock,
  Calendar,
  Layers,
  Cpu,
  Info,
  Server,
  Sparkles,
  FileText,
  ShieldAlert,
} from 'lucide-react';

export default function DatabaseManagement({ initialTab = 'performance' }) {
  const [activeSubTab, setActiveSubTab] = useState(initialTab); // 'performance' | 'backups'

  // Configuración de la base de datos
  const [settings, setSettings] = useState({
    db_cache_size_mb: 96,
    optimize_on_shutdown: false,
    analysis_row_limit: 400,
    vacuum_on_next_startup: false,
    auto_backup_enabled: true,
    auto_backup_frequency: 'daily',
    auto_backup_retention_days: 7,
  });

  // Estadísticas del clúster de base de datos
  const [stats, setStats] = useState(null);

  // Lista de respaldos
  const [backups, setBackups] = useState([]);

  // Estados de carga
  const [loadingSettings, setLoadingSettings] = useState(true);
  const [loadingBackups, setLoadingBackups] = useState(false);
  const [savingSettings, setSavingSettings] = useState(false);
  const [creatingBackup, setCreatingBackup] = useState(false);
  const [optimizing, setOptimizing] = useState(false);
  const [downloadingFile, setDownloadingFile] = useState(null);

  // Sistema de Notificaciones Toast Flotantes (Cero alertas nativas)
  const [toast, setToast] = useState(null); // { type: 'success' | 'error' | 'info', message: string }

  const showToast = useCallback((type, message) => {
    setToast({ type, message });
  }, []);

  useEffect(() => {
    if (!toast) return;
    const timer = setTimeout(() => {
      setToast(null);
    }, 4500);
    return () => clearTimeout(timer);
  }, [toast]);

  // Modales personalizados
  const [restoreModal, setRestoreModal] = useState({
    open: false,
    backup: null,
    confirmationText: '',
    loading: false,
  });

  const [deleteModal, setDeleteModal] = useState({
    open: false,
    backup: null,
    loading: false,
  });

  const [optimizeModal, setOptimizeModal] = useState({
    open: false,
    loading: false,
  });

  const [optimizeResults, setOptimizeResults] = useState(null);

  // Cargar Ajustes y Estadísticas
  const fetchSettingsAndStats = useCallback(async () => {
    setLoadingSettings(true);
    try {
      const res = await api.get('/admin/database/settings');
      if (res.data?.data) {
        if (res.data.data.settings) {
          setSettings(res.data.data.settings);
        }
        if (res.data.data.stats) {
          setStats(res.data.data.stats);
        }
      }
    } catch (err) {
      console.error('Error al cargar configuración de base de datos:', err);
      showToast('error', 'No se pudo cargar la configuración de la base de datos.');
    } finally {
      setLoadingSettings(false);
    }
  }, [showToast]);

  // Cargar Lista de Respaldos
  const fetchBackups = useCallback(async () => {
    setLoadingBackups(true);
    try {
      const res = await api.get('/admin/database/backups');
      if (res.data?.data) {
        setBackups(res.data.data);
      }
    } catch (err) {
      console.error('Error al cargar lista de respaldos:', err);
      showToast('error', 'Error al obtener las copias de seguridad existentes.');
    } finally {
      setLoadingBackups(false);
    }
  }, [showToast]);

  useEffect(() => {
    fetchSettingsAndStats();
    fetchBackups();
  }, [fetchSettingsAndStats, fetchBackups]);

  // Guardar Ajustes de Rendimiento y Políticas de Respaldo
  const handleSaveSettings = async (e) => {
    if (e) e.preventDefault();
    setSavingSettings(true);
    try {
      const res = await api.post('/admin/database/settings', settings);
      if (res.data?.status === 'success') {
        showToast('success', res.data.message || 'Configuración guardada correctamente.');
        if (res.data.data) {
          setSettings(res.data.data);
        }
      }
    } catch (err) {
      console.error('Error al guardar configuración:', err);
      const errMsg = err.response?.data?.message || 'Error al guardar los ajustes de la base de datos.';
      showToast('error', errMsg);
    } finally {
      setSavingSettings(false);
    }
  };

  // Crear Respaldo Manual de Inmediato
  const handleCreateManualBackup = async () => {
    setCreatingBackup(true);
    showToast('info', 'Generando respaldo comprimido de la base de datos...');
    try {
      const res = await api.post('/admin/database/backups/create');
      if (res.data?.status === 'success') {
        showToast('success', res.data.message || 'Copia de seguridad generada con éxito.');
        await fetchBackups();
      }
    } catch (err) {
      console.error('Error creando copia de seguridad:', err);
      const errMsg = err.response?.data?.message || 'Error al generar la copia de seguridad.';
      showToast('error', errMsg);
    } finally {
      setCreatingBackup(false);
    }
  };

  // Descarga Segura de Respaldo con Token Sanctum
  const handleDownloadBackup = async (filename) => {
    setDownloadingFile(filename);
    try {
      const response = await api.get(`/admin/database/backups/download/${filename}`, {
        responseType: 'blob',
      });
      const blobUrl = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement('a');
      link.href = blobUrl;
      link.setAttribute('download', filename);
      document.body.appendChild(link);
      link.click();
      link.parentNode?.removeChild(link);
      window.URL.revokeObjectURL(blobUrl);
      showToast('success', `Descarga de '${filename}' completada.`);
    } catch (err) {
      console.error('Error al descargar archivo:', err);
      showToast('error', `No se pudo descargar el archivo '${filename}'.`);
    } finally {
      setDownloadingFile(null);
    }
  };

  // Confirmar y Ejecutar Restauración
  const handleConfirmRestore = async () => {
    const filename = restoreModal.backup?.filename;
    if (!filename) return;

    setRestoreModal((prev) => ({ ...prev, loading: true }));
    showToast('info', `Restaurando base de datos desde '${filename}'...`);

    try {
      const res = await api.post(`/admin/database/backups/restore/${filename}`);
      if (res.data?.status === 'success') {
        showToast('success', res.data.message || 'Base de datos restaurada correctamente.');
        setRestoreModal({ open: false, backup: null, confirmationText: '', loading: false });
        await fetchSettingsAndStats();
        await fetchBackups();
      }
    } catch (err) {
      console.error('Error restaurando base de datos:', err);
      const errMsg = err.response?.data?.message || 'Error crítico durante la restauración de la base de datos.';
      showToast('error', errMsg);
      setRestoreModal((prev) => ({ ...prev, loading: false }));
    }
  };

  // Confirmar y Ejecutar Eliminación de Backup
  const handleConfirmDelete = async () => {
    const filename = deleteModal.backup?.filename;
    if (!filename) return;

    setDeleteModal((prev) => ({ ...prev, loading: true }));
    try {
      const res = await api.delete(`/admin/database/backups/${filename}`);
      if (res.data?.status === 'success') {
        showToast('success', res.data.message || 'Copia de seguridad eliminada.');
        setDeleteModal({ open: false, backup: null, loading: false });
        await fetchBackups();
      }
    } catch (err) {
      console.error('Error eliminando respaldo:', err);
      const errMsg = err.response?.data?.message || 'Error al eliminar el archivo de respaldo.';
      showToast('error', errMsg);
      setDeleteModal((prev) => ({ ...prev, loading: false }));
    }
  };

  // Disparar Optimización de Tablas
  const handleExecuteOptimization = async () => {
    setOptimizeModal({ open: false, loading: false });
    setOptimizing(true);
    showToast('info', 'Ejecutando optimización y análisis de tablas del sistema...');
    try {
      const res = await api.post('/admin/database/optimize');
      if (res.data?.status === 'success') {
        showToast('success', res.data.message || 'Optimización completada con éxito.');
        setOptimizeResults(res.data.data);
        await fetchSettingsAndStats();
      }
    } catch (err) {
      console.error('Error optimizando base de datos:', err);
      const errMsg = err.response?.data?.message || 'Error al optimizar las tablas.';
      showToast('error', errMsg);
    } finally {
      setOptimizing(false);
    }
  };

  return (
    <div className="space-y-6 max-w-7xl mx-auto pb-12 animate-fadeIn select-none">
      {/* 1. Toast Flotante de Notificaciones */}
      {toast && (
        <div className="fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-2xl backdrop-blur-md border border-zinc-700/80 bg-[#1e1e1e]/95 text-white animate-slideUp">
          {toast.type === 'success' && <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0" />}
          {toast.type === 'error' && <AlertCircle className="w-5 h-5 text-red-500 shrink-0" />}
          {toast.type === 'info' && <Loader2 className="w-5 h-5 text-sky-400 animate-spin shrink-0" />}
          <span className="text-sm font-medium">{toast.message}</span>
          <button
            onClick={() => setToast(null)}
            className="p-1 text-zinc-400 hover:text-white rounded-lg hover:bg-zinc-800 transition-colors ml-2"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* 2. Cabecera del Módulo */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-zinc-800 pb-5">
        <div>
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-red-600/10 border border-red-500/20 flex items-center justify-center text-[#E50914]">
              <Database className="w-5 h-5" />
            </div>
            <div>
              <h1 className="text-2xl font-bold text-white tracking-wide flex items-center gap-2">
                Administración y Mantenimiento de Base de Datos
              </h1>
              <p className="text-xs text-zinc-400 mt-0.5">
                Rendimiento de caché, compactación de almacenamiento y copias de seguridad locales
              </p>
            </div>
          </div>
        </div>

        {/* Pestañas Principales Estilo FlixHN */}
        <div className="flex bg-[#1a1a1a] p-1 rounded-xl border border-zinc-800 shrink-0 self-start md:self-auto">
          <button
            type="button"
            onClick={() => setActiveSubTab('performance')}
            className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all ${
              activeSubTab === 'performance'
                ? 'bg-[#E50914] text-white shadow-md'
                : 'text-zinc-400 hover:text-white hover:bg-zinc-800/50'
            }`}
          >
            <Sliders className="w-4 h-4" />
            <span>Configuración y Rendimiento</span>
          </button>
          <button
            type="button"
            onClick={() => setActiveSubTab('backups')}
            className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all ${
              activeSubTab === 'backups'
                ? 'bg-[#E50914] text-white shadow-md'
                : 'text-zinc-400 hover:text-white hover:bg-zinc-800/50'
            }`}
          >
            <Archive className="w-4 h-4" />
            <span>Copias de Seguridad</span>
            {backups.length > 0 && (
              <span className="text-[11px] bg-black/40 px-1.5 py-0.5 rounded-full font-mono text-zinc-200">
                {backups.length}
              </span>
            )}
          </button>
        </div>
      </div>

      {/* 3. Tarjetas de Métricas de Base de Datos en Tiempo Real */}
      {stats && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div className="bg-[#181818] border border-zinc-800/80 rounded-xl p-4 shadow-sm">
            <div className="flex items-center justify-between text-zinc-400 text-xs mb-1.5">
              <span>Tamaño Total</span>
              <HardDrive className="w-4 h-4 text-zinc-500" />
            </div>
            <div className="text-xl font-bold text-white font-mono">
              {stats.total_size_mb} <span className="text-xs text-zinc-400 font-normal">MB</span>
            </div>
            <div className="text-[11px] text-zinc-500 mt-1">
              Datos: {stats.data_size_mb} MB · Índices: {stats.index_size_mb} MB
            </div>
          </div>

          <div className="bg-[#181818] border border-zinc-800/80 rounded-xl p-4 shadow-sm">
            <div className="flex items-center justify-between text-zinc-400 text-xs mb-1.5">
              <span>Tablas del Sistema</span>
              <Layers className="w-4 h-4 text-zinc-500" />
            </div>
            <div className="text-xl font-bold text-white font-mono">
              {stats.tables_count} <span className="text-xs text-zinc-400 font-normal">tablas</span>
            </div>
            <div className="text-[11px] text-zinc-500 mt-1">
              Esquema: {stats.database}
            </div>
          </div>

          <div className="bg-[#181818] border border-zinc-800/80 rounded-xl p-4 shadow-sm">
            <div className="flex items-center justify-between text-zinc-400 text-xs mb-1.5">
              <span>Motor / Versión</span>
              <Server className="w-4 h-4 text-zinc-500" />
            </div>
            <div className="text-xl font-bold text-emerald-400 font-mono truncate" title={stats.mysql_version}>
              {stats.mysql_version?.split('-')[0] || 'MySQL 8.0'}
            </div>
            <div className="text-[11px] text-zinc-500 mt-1 truncate">
              {stats.charset} ({stats.collation})
            </div>
          </div>

          <div className="bg-[#181818] border border-zinc-800/80 rounded-xl p-4 shadow-sm">
            <div className="flex items-center justify-between text-zinc-400 text-xs mb-1.5">
              <span>Registros Totales</span>
              <Cpu className="w-4 h-4 text-zinc-500" />
            </div>
            <div className="text-xl font-bold text-white font-mono">
              {stats.total_rows.toLocaleString()}
            </div>
            <div className="text-[11px] text-zinc-500 mt-1">
              Estimación de tuplas en clúster
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* PESTAÑA 1: CONFIGURACIÓN Y RENDIMIENTO                                     */}
      {/* ========================================================================= */}
      {activeSubTab === 'performance' && (
        <div className="space-y-6">
          {/* Banner de advertencia estilizado */}
          <div className="p-4 rounded-xl border border-amber-500/30 bg-amber-500/10 flex items-start gap-3 text-amber-200">
            <AlertTriangle className="w-5 h-5 text-amber-400 shrink-0 mt-0.5" />
            <div className="text-xs md:text-sm leading-relaxed">
              <span className="font-semibold text-amber-300">Nota para administradores de infraestructura: </span>
              Estas son opciones avanzadas de la base de datos y se recomienda mantener los valores por defecto a menos que necesites ajustar el rendimiento del servidor para catálogos masivos o nodos de alta concurrencia.
            </div>
          </div>

          <form onSubmit={handleSaveSettings} className="space-y-6">
            {/* Panel 1: Parámetros de Caché y Límites de Optimización */}
            <div className="bg-[#181818] border border-zinc-800 rounded-xl p-5 md:p-6 space-y-6 shadow-lg">
              <div className="flex items-center justify-between border-b border-zinc-800 pb-4">
                <div>
                  <h2 className="text-lg font-bold text-white flex items-center gap-2">
                    <Cpu className="w-5 h-5 text-[#E50914]" />
                    Parámetros de Rendimiento y Memoria
                  </h2>
                  <p className="text-xs text-zinc-400 mt-0.5">
                    Ajuste fino de asignación de memoria para consultas frecuentes y planes de ejecución
                  </p>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                {/* Database cache size (MB) */}
                <div className="space-y-2">
                  <label className="block text-sm font-semibold text-zinc-200">
                    Database cache size (MB)
                  </label>
                  <input
                    type="number"
                    min={16}
                    max={16384}
                    value={settings.db_cache_size_mb}
                    onChange={(e) =>
                      setSettings({ ...settings, db_cache_size_mb: parseInt(e.target.value) || 16 })
                    }
                    className="w-full bg-[#121212] border border-zinc-700/80 rounded-lg px-4 py-2.5 text-white font-mono text-sm focus:outline-none focus:border-[#E50914] focus:ring-1 focus:ring-[#E50914] transition-all"
                    placeholder="96"
                  />
                  <p className="text-xs text-zinc-400 leading-relaxed">
                    Cantidad de memoria asignada en megabytes al búfer interno de consultas y pool de páginas. Un valor mayor acelera la lectura de metadatos en bibliotecas extensas a cambio de mayor consumo de RAM. (Por defecto: 96 MB).
                  </p>
                </div>

                {/* Analysis row limit */}
                <div className="space-y-2">
                  <label className="block text-sm font-semibold text-zinc-200">
                    Analysis row limit
                  </label>
                  <input
                    type="number"
                    min={10}
                    max={100000}
                    value={settings.analysis_row_limit}
                    onChange={(e) =>
                      setSettings({ ...settings, analysis_row_limit: parseInt(e.target.value) || 400 })
                    }
                    className="w-full bg-[#121212] border border-zinc-700/80 rounded-lg px-4 py-2.5 text-white font-mono text-sm focus:outline-none focus:border-[#E50914] focus:ring-1 focus:ring-[#E50914] transition-all"
                    placeholder="400"
                  />
                  <p className="text-xs text-zinc-400 leading-relaxed">
                    Límite máximo de filas analizadas por índice para calcular la cardinalidad durante los chequeos de consulta. Valores elevados proveen estadísticas más precisas pero toman mayor tiempo de CPU. (Por defecto: 400).
                  </p>
                </div>
              </div>

              {/* Toggles de Optimización */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-zinc-800">
                {/* Optimize on shutdown */}
                <div className="flex items-start justify-between gap-4 p-4 rounded-xl bg-[#141414] border border-zinc-800/80">
                  <div className="space-y-1">
                    <span className="text-sm font-semibold text-zinc-100 block">
                      Attempt to optimize the database on server shutdowns
                    </span>
                    <p className="text-xs text-zinc-400 leading-relaxed">
                      Ejecuta automáticamente la rutina de análisis de índices (<code className="text-[#E50914] font-mono">ANALYZE TABLE</code>) cuando el servicio FlixHN recibe la señal de apagado ordenado.
                    </p>
                  </div>
                  <button
                    type="button"
                    role="switch"
                    aria-checked={settings.optimize_on_shutdown}
                    onClick={() =>
                      setSettings({ ...settings, optimize_on_shutdown: !settings.optimize_on_shutdown })
                    }
                    className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${
                      settings.optimize_on_shutdown ? 'bg-[#E50914]' : 'bg-zinc-700'
                    }`}
                  >
                    <span
                      className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-lg ring-0 transition duration-200 ease-in-out ${
                        settings.optimize_on_shutdown ? 'translate-x-5' : 'translate-x-0'
                      }`}
                    />
                  </button>
                </div>

                {/* Vacuum / Reindex on next startup */}
                <div className="flex items-start justify-between gap-4 p-4 rounded-xl bg-[#141414] border border-zinc-800/80">
                  <div className="space-y-1">
                    <span className="text-sm font-semibold text-zinc-100 block">
                      Vacuum / Reindex on next startup
                    </span>
                    <p className="text-xs text-zinc-400 leading-relaxed">
                      Reconstruye y compacta el almacenamiento en disco de las tablas en el siguiente reinicio del servidor. Útil tras depuraciones masivas de catálogos o historial.
                    </p>
                  </div>
                  <button
                    type="button"
                    role="switch"
                    aria-checked={settings.vacuum_on_next_startup}
                    onClick={() =>
                      setSettings({ ...settings, vacuum_on_next_startup: !settings.vacuum_on_next_startup })
                    }
                    className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${
                      settings.vacuum_on_next_startup ? 'bg-[#E50914]' : 'bg-zinc-700'
                    }`}
                  >
                    <span
                      className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-lg ring-0 transition duration-200 ease-in-out ${
                        settings.vacuum_on_next_startup ? 'translate-x-5' : 'translate-x-0'
                      }`}
                    />
                  </button>
                </div>
              </div>
            </div>

            {/* Barra de Acciones de la Pestaña de Rendimiento */}
            <div className="flex flex-col sm:flex-row items-center justify-between gap-4 bg-[#181818] border border-zinc-800 rounded-xl p-4">
              <button
                type="button"
                onClick={() => setOptimizeModal({ open: true, loading: false })}
                disabled={optimizing}
                className="w-full sm:w-auto flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg border border-zinc-700 text-zinc-200 hover:text-white hover:bg-zinc-800 transition-colors text-sm font-medium disabled:opacity-50"
              >
                {optimizing ? (
                  <Loader2 className="w-4 h-4 animate-spin text-zinc-400" />
                ) : (
                  <Sparkles className="w-4 h-4 text-amber-400" />
                )}
                <span>Optimizar Tablas Ahora</span>
              </button>

              <button
                type="submit"
                disabled={savingSettings || loadingSettings}
                className="w-full sm:w-auto flex items-center justify-center gap-2 px-6 py-2.5 rounded-lg bg-[#E50914] hover:bg-red-700 text-white font-semibold text-sm shadow-md transition-all disabled:opacity-50"
              >
                {savingSettings ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin" />
                    <span>Guardando Ajustes...</span>
                  </>
                ) : (
                  <>
                    <CheckCircle2 className="w-4 h-4" />
                    <span>Guardar Ajustes</span>
                  </>
                )}
              </button>
            </div>
          </form>

          {/* Modal de Resultados de Optimización */}
          {optimizeResults && (
            <div className="bg-[#181818] border border-zinc-800 rounded-xl p-5 space-y-4 animate-fadeIn">
              <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
                <div className="flex items-center gap-2">
                  <CheckCircle2 className="w-5 h-5 text-emerald-400" />
                  <h3 className="text-base font-bold text-white">
                    Resultados de la Última Optimización de Tablas
                  </h3>
                </div>
                <button
                  onClick={() => setOptimizeResults(null)}
                  className="text-zinc-400 hover:text-white p-1 rounded-lg hover:bg-zinc-800"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>

              <div className="text-xs text-zinc-400">
                Se optimizaron con éxito <span className="text-white font-semibold">{optimizeResults.total_tables}</span> tablas del sistema MySQL.
              </div>

              <div className="max-h-60 overflow-y-auto rounded-lg border border-zinc-800 bg-[#121212]">
                <table className="w-full text-left text-xs">
                  <thead className="bg-[#1a1a1a] text-zinc-400 border-b border-zinc-800">
                    <tr>
                      <th className="px-4 py-2 font-semibold">Tabla</th>
                      <th className="px-4 py-2 font-semibold">Análisis</th>
                      <th className="px-4 py-2 font-semibold">Desfragmentación</th>
                      <th className="px-4 py-2 font-semibold text-right">Estado</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-800/60 font-mono">
                    {optimizeResults.tables?.map((t, idx) => (
                      <tr key={idx} className="hover:bg-zinc-800/30">
                        <td className="px-4 py-2 text-zinc-200">{t.table}</td>
                        <td className="px-4 py-2 text-zinc-400">{t.analyze_status}</td>
                        <td className="px-4 py-2 text-zinc-400 truncate max-w-[200px]">{t.optimize_status}</td>
                        <td className="px-4 py-2 text-right">
                          <span className="text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded text-[10px]">
                            OK
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      )}

      {/* ========================================================================= */}
      {/* PESTAÑA 2: COPIAS DE SEGURIDAD (BACKUPS)                                   */}
      {/* ========================================================================= */}
      {activeSubTab === 'backups' && (
        <div className="space-y-6">
          {/* Panel de Configuración de Respaldos Automáticos */}
          <div className="bg-[#181818] border border-zinc-800 rounded-xl p-5 md:p-6 space-y-6 shadow-lg">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-4">
              <div>
                <h2 className="text-lg font-bold text-white flex items-center gap-2">
                  <Clock className="w-5 h-5 text-[#E50914]" />
                  Directivas de Copias de Seguridad Automáticas
                </h2>
                <p className="text-xs text-zinc-400 mt-0.5">
                  Programación de respaldos cíclicos en almacenamiento persistente con rotación por antigüedad
                </p>
              </div>

              {/* Botón Guardar Políticas Automáticas */}
              <button
                type="button"
                onClick={handleSaveSettings}
                disabled={savingSettings}
                className="flex items-center gap-2 px-4 py-2 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-200 hover:text-white text-xs font-semibold border border-zinc-700 transition-colors"
              >
                {savingSettings ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <CheckCircle2 className="w-3.5 h-3.5" />}
                <span>Guardar Políticas</span>
              </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {/* Switch Activar/Desactivar Respaldo Automático */}
              <div className="flex items-center justify-between gap-4 p-4 rounded-xl bg-[#141414] border border-zinc-800/80">
                <div className="space-y-1">
                  <span className="text-sm font-semibold text-zinc-100 block">
                    Respaldos Automáticos
                  </span>
                  <p className="text-xs text-zinc-400">
                    Activar generación desatendida programada en segundo plano.
                  </p>
                </div>
                <button
                  type="button"
                  role="switch"
                  aria-checked={settings.auto_backup_enabled}
                  onClick={() =>
                    setSettings({ ...settings, auto_backup_enabled: !settings.auto_backup_enabled })
                  }
                  className={`relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${
                    settings.auto_backup_enabled ? 'bg-emerald-500' : 'bg-zinc-700'
                  }`}
                >
                  <span
                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-lg ring-0 transition duration-200 ease-in-out ${
                      settings.auto_backup_enabled ? 'translate-x-5' : 'translate-x-0'
                    }`}
                  />
                </button>
              </div>

              {/* Frecuencia de Respaldo */}
              <div className="space-y-2 p-4 rounded-xl bg-[#141414] border border-zinc-800/80">
                <label className="block text-sm font-semibold text-zinc-200">
                  Frecuencia de Ejecución
                </label>
                <select
                  value={settings.auto_backup_frequency}
                  disabled={!settings.auto_backup_enabled}
                  onChange={(e) =>
                    setSettings({ ...settings, auto_backup_frequency: e.target.value })
                  }
                  className="w-full bg-[#181818] border border-zinc-700/80 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-[#E50914] disabled:opacity-40"
                >
                  <option value="daily">Diario (Todas las noches a las 03:00 AM)</option>
                  <option value="weekly">Semanal (Domingos a las 03:00 AM)</option>
                </select>
                <p className="text-[11px] text-zinc-400">
                  El planificador de Laravel (Scheduler) dispara el volcado a bajo tráfico.
                </p>
              </div>

              {/* Días de Retención */}
              <div className="space-y-2 p-4 rounded-xl bg-[#141414] border border-zinc-800/80">
                <label className="block text-sm font-semibold text-zinc-200">
                  Retención de Respaldos (Días)
                </label>
                <div className="flex items-center gap-2">
                  <input
                    type="number"
                    min={1}
                    max={365}
                    value={settings.auto_backup_retention_days}
                    disabled={!settings.auto_backup_enabled}
                    onChange={(e) =>
                      setSettings({
                        ...settings,
                        auto_backup_retention_days: parseInt(e.target.value) || 1,
                      })
                    }
                    className="w-full bg-[#181818] border border-zinc-700/80 rounded-lg px-3 py-2 text-white font-mono text-sm focus:outline-none focus:border-[#E50914] disabled:opacity-40"
                  />
                  <span className="text-xs text-zinc-400 font-medium whitespace-nowrap">días</span>
                </div>
                <p className="text-[11px] text-zinc-400">
                  Los respaldos automáticos con antigüedad superior serán purgados.
                </p>
              </div>
            </div>
          </div>

          {/* Barra de Gestión de Respaldos y Botón Manual */}
          <div className="flex flex-col sm:flex-row items-center justify-between gap-4 bg-[#181818] border border-zinc-800 rounded-xl p-4">
            <div>
              <h3 className="text-base font-bold text-white flex items-center gap-2">
                <Archive className="w-5 h-5 text-emerald-400" />
                Copias de Seguridad Disponibles en Disco
              </h3>
              <p className="text-xs text-zinc-400 mt-0.5">
                Archivos comprimidos en formato Gzip almacenados localmente en <code className="text-zinc-300 font-mono">storage/app/backups/</code>
              </p>
            </div>

            <div className="flex items-center gap-3 w-full sm:w-auto">
              <button
                type="button"
                onClick={fetchBackups}
                disabled={loadingBackups}
                className="p-2.5 rounded-lg border border-zinc-700 text-zinc-300 hover:text-white hover:bg-zinc-800 transition-colors"
                title="Actualizar lista de archivos"
              >
                <RotateCcw className={`w-4 h-4 ${loadingBackups ? 'animate-spin' : ''}`} />
              </button>

              <button
                type="button"
                onClick={handleCreateManualBackup}
                disabled={creatingBackup}
                className="flex-1 sm:flex-initial flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm shadow-md transition-all disabled:opacity-50"
              >
                {creatingBackup ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin" />
                    <span>Generando Respaldo...</span>
                  </>
                ) : (
                  <>
                    <Play className="w-4 h-4" />
                    <span>Crear Respaldo Manual Ahora</span>
                  </>
                )}
              </button>
            </div>
          </div>

          {/* Tabla de Respaldos Disponibles */}
          <div className="bg-[#181818] border border-zinc-800 rounded-xl overflow-hidden shadow-lg">
            {loadingBackups ? (
              <div className="p-12 flex flex-col items-center justify-center space-y-3 text-zinc-400">
                <Loader2 className="w-8 h-8 text-[#E50914] animate-spin" />
                <span className="text-sm font-medium">Leyendo archivos de respaldo en almacenamiento...</span>
              </div>
            ) : backups.length === 0 ? (
              <div className="p-12 flex flex-col items-center justify-center text-center space-y-3">
                <div className="w-14 h-14 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center text-zinc-500">
                  <Archive className="w-7 h-7" />
                </div>
                <div className="text-base font-semibold text-zinc-200">
                  No hay copias de seguridad registradas
                </div>
                <p className="text-xs text-zinc-400 max-w-md">
                  Aún no se han generado respaldos de la base de datos. Haz clic en "Crear Respaldo Manual Ahora" para generar la primera copia de seguridad.
                </p>
                <button
                  type="button"
                  onClick={handleCreateManualBackup}
                  disabled={creatingBackup}
                  className="mt-2 flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition-all"
                >
                  <Play className="w-3.5 h-3.5" />
                  <span>Crear Primera Copia</span>
                </button>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="bg-[#141414] text-zinc-400 border-b border-zinc-800 text-xs uppercase tracking-wider font-semibold">
                    <tr>
                      <th className="px-5 py-3.5">Nombre del Archivo</th>
                      <th className="px-5 py-3.5">Tamaño</th>
                      <th className="px-5 py-3.5">Fecha de Creación</th>
                      <th className="px-5 py-3.5">Origen / Tipo</th>
                      <th className="px-5 py-3.5 text-right">Acciones</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-zinc-800/80">
                    {backups.map((b) => (
                      <tr key={b.filename} className="hover:bg-zinc-800/30 transition-colors">
                        {/* Nombre del archivo */}
                        <td className="px-5 py-4">
                          <div className="flex items-center gap-3">
                            <div className="w-8 h-8 rounded-lg bg-zinc-900 border border-zinc-800 flex items-center justify-center text-zinc-400 shrink-0">
                              <FileText className="w-4 h-4 text-emerald-400" />
                            </div>
                            <div className="min-w-0">
                              <span className="font-mono text-zinc-100 font-medium block truncate max-w-xs md:max-w-md" title={b.filename}>
                                {b.filename}
                              </span>
                              <span className="text-[11px] text-zinc-500 font-mono">
                                {b.is_compressed ? 'Gzip Compressed (.sql.gz)' : 'SQL Plain (.sql)'}
                              </span>
                            </div>
                          </div>
                        </td>

                        {/* Tamaño */}
                        <td className="px-5 py-4 font-mono text-zinc-300 whitespace-nowrap">
                          {b.human_size}
                        </td>

                        {/* Fecha */}
                        <td className="px-5 py-4 whitespace-nowrap">
                          <div className="text-zinc-200 text-xs">
                            {new Date(b.created_at).toLocaleString()}
                          </div>
                          <div className="text-[11px] text-zinc-500">
                            {b.created_at_human}
                          </div>
                        </td>

                        {/* Origen / Tipo */}
                        <td className="px-5 py-4 whitespace-nowrap">
                          {b.type === 'auto' ? (
                            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-purple-500/10 text-purple-400 border border-purple-500/20">
                              <Clock className="w-3 h-3" />
                              Automático
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                              <CheckCircle2 className="w-3 h-3" />
                              Manual
                            </span>
                          )}
                        </td>

                        {/* Acciones */}
                        <td className="px-5 py-4 text-right whitespace-nowrap">
                          <div className="flex items-center justify-end gap-1.5">
                            {/* Descargar */}
                            <button
                              type="button"
                              onClick={() => handleDownloadBackup(b.filename)}
                              disabled={downloadingFile === b.filename}
                              className="p-2 text-zinc-400 hover:text-white rounded-lg hover:bg-zinc-800 transition-colors"
                              title="Descargar archivo al equipo"
                            >
                              {downloadingFile === b.filename ? (
                                <Loader2 className="w-4 h-4 animate-spin text-sky-400" />
                              ) : (
                                <Download className="w-4 h-4" />
                              )}
                            </button>

                            {/* Restaurar */}
                            <button
                              type="button"
                              onClick={() =>
                                setRestoreModal({
                                  open: true,
                                  backup: b,
                                  confirmationText: '',
                                  loading: false,
                                })
                              }
                              className="px-2.5 py-1.5 text-xs font-semibold rounded-lg bg-amber-500/10 text-amber-400 hover:bg-amber-500/20 border border-amber-500/30 transition-colors flex items-center gap-1.5"
                              title="Restaurar base de datos con esta copia"
                            >
                              <RotateCcw className="w-3.5 h-3.5" />
                              <span>Restaurar</span>
                            </button>

                            {/* Eliminar */}
                            <button
                              type="button"
                              onClick={() =>
                                setDeleteModal({
                                  open: true,
                                  backup: b,
                                  loading: false,
                                })
                              }
                              className="p-2 text-zinc-400 hover:text-red-400 rounded-lg hover:bg-red-500/10 transition-colors"
                              title="Eliminar este archivo de respaldo"
                            >
                              <Trash2 className="w-4 h-4" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL 1: RESTAURACIÓN CRÍTICA (Zero Native Alerts)                         */}
      {/* ========================================================================= */}
      {restoreModal.open && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fadeIn">
          <div className="relative w-full max-w-lg bg-[#181818] border border-red-500/40 rounded-2xl shadow-2xl p-6 space-y-5 animate-scaleUp">
            {/* Header del modal */}
            <div className="flex items-start gap-4">
              <div className="w-12 h-12 rounded-xl bg-red-500/10 border border-red-500/30 flex items-center justify-center text-red-500 shrink-0">
                <ShieldAlert className="w-6 h-6 animate-pulse" />
              </div>
              <div className="space-y-1">
                <h3 className="text-lg font-bold text-white flex items-center gap-2">
                  ¿Confirmar Restauración de Base de Datos?
                </h3>
                <p className="text-xs text-red-400 font-semibold uppercase tracking-wider">
                  Acción de Alto Riesgo e Irreversible
                </p>
              </div>
            </div>

            {/* Mensaje explicativo */}
            <div className="p-4 rounded-xl bg-red-950/20 border border-red-900/40 space-y-2 text-xs md:text-sm text-zinc-300 leading-relaxed">
              <p>
                La restauración <strong className="text-white">sobreescribirá por completo</strong> todos los datos, tablas, usuarios, historial de reproducción y catálogos actuales con los contenidos de la copia seleccionada:
              </p>
              <div className="p-2.5 rounded-lg bg-[#121212] border border-zinc-800 font-mono text-xs text-amber-300 break-all">
                {restoreModal.backup?.filename} ({restoreModal.backup?.human_size})
              </div>
              <p className="text-red-300 font-medium">
                Esta acción NO se puede deshacer. Las sesiones activas de streaming podrían verse interrumpidas momentáneamente.
              </p>
            </div>

            {/* Input de confirmación explícita */}
            <div className="space-y-2">
              <label className="block text-xs font-semibold text-zinc-300">
                Para confirmar la restauración, escribe <span className="text-red-400 font-mono font-bold">RESTAURAR</span> a continuación:
              </label>
              <input
                type="text"
                autoFocus
                value={restoreModal.confirmationText}
                onChange={(e) =>
                  setRestoreModal({ ...restoreModal, confirmationText: e.target.value })
                }
                placeholder="RESTAURAR"
                className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-4 py-2.5 text-white font-mono text-sm tracking-wider focus:outline-none focus:border-red-500 focus:ring-1 focus:ring-red-500"
              />
            </div>

            {/* Botones de acción */}
            <div className="flex items-center justify-end gap-3 pt-2">
              <button
                type="button"
                disabled={restoreModal.loading}
                onClick={() =>
                  setRestoreModal({ open: false, backup: null, confirmationText: '', loading: false })
                }
                className="px-4 py-2.5 rounded-lg text-sm font-medium text-zinc-300 hover:text-white hover:bg-zinc-800 transition-colors disabled:opacity-50"
              >
                Cancelar
              </button>

              <button
                type="button"
                disabled={
                  restoreModal.loading ||
                  restoreModal.confirmationText.trim().toUpperCase() !== 'RESTAURAR'
                }
                onClick={handleConfirmRestore}
                className="flex items-center gap-2 px-5 py-2.5 rounded-lg bg-red-600 hover:bg-red-700 text-white font-semibold text-sm shadow-lg shadow-red-950/50 transition-all disabled:opacity-40 disabled:cursor-not-allowed"
              >
                {restoreModal.loading ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin" />
                    <span>Restaurando Datos...</span>
                  </>
                ) : (
                  <>
                    <RotateCcw className="w-4 h-4" />
                    <span>Confirmar y Restaurar</span>
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL 2: ELIMINACIÓN DE BACKUP (Zero Native Alerts)                       */}
      {/* ========================================================================= */}
      {deleteModal.open && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fadeIn">
          <div className="relative w-full max-w-md bg-[#181818] border border-zinc-800 rounded-2xl shadow-2xl p-6 space-y-5 animate-scaleUp">
            <div className="flex items-start gap-3">
              <div className="w-10 h-10 rounded-xl bg-red-500/10 border border-red-500/20 flex items-center justify-center text-red-500 shrink-0">
                <Trash2 className="w-5 h-5" />
              </div>
              <div>
                <h3 className="text-base font-bold text-white">
                  ¿Eliminar Copia de Seguridad?
                </h3>
                <p className="text-xs text-zinc-400 mt-0.5">
                  Esta acción eliminará permanentemente el archivo del disco del servidor.
                </p>
              </div>
            </div>

            <div className="p-3 rounded-lg bg-[#121212] border border-zinc-800 font-mono text-xs text-zinc-300 break-all">
              {deleteModal.backup?.filename}
              <span className="block text-[11px] text-zinc-500 mt-1">
                Tamaño: {deleteModal.backup?.human_size}
              </span>
            </div>

            <div className="flex items-center justify-end gap-3 pt-2">
              <button
                type="button"
                disabled={deleteModal.loading}
                onClick={() => setDeleteModal({ open: false, backup: null, loading: false })}
                className="px-4 py-2 rounded-lg text-sm font-medium text-zinc-300 hover:text-white hover:bg-zinc-800 transition-colors"
              >
                Cancelar
              </button>

              <button
                type="button"
                disabled={deleteModal.loading}
                onClick={handleConfirmDelete}
                className="flex items-center gap-2 px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white font-semibold text-sm shadow-md transition-all disabled:opacity-50"
              >
                {deleteModal.loading ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : (
                  <Trash2 className="w-4 h-4" />
                )}
                <span>Eliminar Definitivamente</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL 3: CONFIRMAR OPTIMIZACIÓN DE TABLAS                                */}
      {/* ========================================================================= */}
      {optimizeModal.open && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fadeIn">
          <div className="relative w-full max-w-md bg-[#181818] border border-zinc-800 rounded-2xl shadow-2xl p-6 space-y-5 animate-scaleUp">
            <div className="flex items-start gap-3">
              <div className="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400 shrink-0">
                <Sparkles className="w-5 h-5" />
              </div>
              <div>
                <h3 className="text-base font-bold text-white">
                  Optimizar y Desfragmentar Clúster
                </h3>
                <p className="text-xs text-zinc-400 mt-0.5">
                  Mantenimiento de tablas MySQL en vivo
                </p>
              </div>
            </div>

            <p className="text-xs text-zinc-300 leading-relaxed">
              Se ejecutarán las sentencias <code className="text-amber-300 font-mono">ANALYZE TABLE</code> y <code className="text-amber-300 font-mono">OPTIMIZE TABLE</code> en todas las tablas del esquema FlixHN. Esto reorganiza las páginas de almacenamiento y actualiza las estadísticas del optimizador de consultas.
            </p>

            <div className="flex items-center justify-end gap-3 pt-2">
              <button
                type="button"
                onClick={() => setOptimizeModal({ open: false, loading: false })}
                className="px-4 py-2 rounded-lg text-sm font-medium text-zinc-300 hover:text-white hover:bg-zinc-800 transition-colors"
              >
                Cancelar
              </button>

              <button
                type="button"
                onClick={handleExecuteOptimization}
                className="flex items-center gap-2 px-5 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-black font-semibold text-sm shadow-md transition-all"
              >
                <Sparkles className="w-4 h-4" />
                <span>Ejecutar Optimización Ahora</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
