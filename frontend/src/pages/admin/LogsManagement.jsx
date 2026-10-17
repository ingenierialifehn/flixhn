import React, { useState, useEffect, useCallback, useRef } from 'react';
import api from '../../services/api';
import {
  ScrollText,
  Terminal,
  RefreshCw,
  Download,
  Trash2,
  Filter,
  Search,
  CheckCircle2,
  AlertTriangle,
  AlertCircle,
  Info,
  Bug,
  Clock,
  HardDrive,
  Copy,
  Check,
  ChevronDown,
  ChevronUp,
  X,
  Play,
  Pause,
  ArrowDownCircle,
  Maximize2,
  FileText,
  RotateCcw,
  Sparkles,
  Layers,
  ShieldAlert,
} from 'lucide-react';

export default function LogsManagement() {
  // ==========================================
  // ESTADO DE CONFIGURACIÓN (Enable debug logging)
  // ==========================================
  const [debugMode, setDebugMode] = useState('no');
  const [debugOptions, setDebugOptions] = useState([
    { value: 'no', label: 'No' },
    { value: 'yes', label: 'Yes' },
    { value: 'until_restart', label: 'Yes, until the next server restart' },
    { value: 'until_rotation', label: 'Yes, until the next log file rotation' },
  ]);
  const [helpText, setHelpText] = useState(
    'El registro de depuración solo debe habilitarse según sea necesario para solucionar problemas. El mayor acceso al sistema de archivos puede afectar el rendimiento en algunos entornos.'
  );
  const [isDebugActive, setIsDebugActive] = useState(false);
  const [isSavingSettings, setIsSavingSettings] = useState(false);

  // ==========================================
  // ESTADO DE EXPLORADOR DE ARCHIVOS
  // ==========================================
  const [files, setFiles] = useState([]);
  const [selectedFilename, setSelectedFilename] = useState('laravel.log');
  const [loadingFiles, setLoadingFiles] = useState(true);

  // ==========================================
  // ESTADO DEL VISOR DE REGISTROS
  // ==========================================
  const [logEntries, setLogEntries] = useState([]);
  const [rawContent, setRawContent] = useState('');
  const [fileStats, setFileStats] = useState({
    filename: 'laravel.log',
    file_size_formatted: '0 B',
    file_size_bytes: 0,
    modified_at: '',
    modified_diff: '',
    total_entries_parsed: 0,
    filtered_entries_count: 0,
    counts_by_level: { ALL: 0, ERROR: 0, WARNING: 0, INFO: 0, DEBUG: 0 },
  });
  const [loadingContent, setLoadingContent] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  // Filtros y Visualización
  const [selectedLevel, setSelectedLevel] = useState('ALL');
  const [searchQuery, setSearchQuery] = useState('');
  const [linesLimit, setLinesLimit] = useState(400);
  const [viewMode, setViewMode] = useState('terminal'); // 'terminal' | 'structured'
  const [autoScroll, setAutoScroll] = useState(true);
  const [copiedAll, setCopiedAll] = useState(false);
  const [copiedEntryId, setCopiedEntryId] = useState(null);

  // Polling automático en tiempo real
  const [isLivePolling, setIsLivePolling] = useState(false);
  const pollTimerRef = useRef(null);

  // Referencias DOM para auto-scroll del visor
  const terminalScrollRef = useRef(null);

  // ==========================================
  // SISTEMA DE TOASTS (Cero alertas nativas)
  // ==========================================
  const [toast, setToast] = useState(null); // { type: 'success' | 'error' | 'info', title: string, message: string }

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

  // ==========================================
  // MODAL DE CONFIRMACIÓN (Vaciar / Rotar)
  // ==========================================
  const [clearModal, setClearModal] = useState({
    open: false,
    action: 'clear', // 'clear' | 'rotate'
    filename: 'laravel.log',
    isProcessing: false,
  });

  // ==========================================
  // 1. CARGA DE CONFIGURACIÓN DE DEPURACIÓN
  // ==========================================
  const fetchSettings = useCallback(async () => {
    try {
      const res = await api.get('/admin/logs/settings');
      if (res.data?.status === 'success' && res.data?.data) {
        const { debug_logging_mode, options, help_text, is_debug_active } = res.data.data;
        setDebugMode(debug_logging_mode || 'no');
        setIsDebugActive(Boolean(is_debug_active));
        if (options && options.length > 0) setDebugOptions(options);
        if (help_text) setHelpText(help_text);
      }
    } catch (err) {
      console.error('Error al cargar configuración de logs:', err);
      showToast('error', 'No fue posible obtener los ajustes de depuración.', 'Error');
    }
  }, [showToast]);

  const handleSaveSettings = async () => {
    setIsSavingSettings(true);
    try {
      const res = await api.post('/admin/logs/settings', {
        debug_logging_mode: debugMode,
      });

      if (res.data?.status === 'success') {
        setIsDebugActive(res.data.data?.is_debug_active);
        showToast('success', res.data.message || 'Configuración guardada correctamente.', 'Ajustes de Registro');
      }
    } catch (err) {
      console.error('Error al guardar ajustes de depuración:', err);
      const msg = err.response?.data?.message || 'Error al comunicarse con el servidor.';
      showToast('error', msg, 'Error al Guardar');
    } finally {
      setIsSavingSettings(false);
    }
  };

  // ==========================================
  // 2. CARGA DE ARCHIVOS DE REGISTRO
  // ==========================================
  const fetchFiles = useCallback(async () => {
    try {
      setLoadingFiles(true);
      const res = await api.get('/admin/logs/files');
      if (res.data?.status === 'success' && res.data?.data) {
        const fileList = res.data.data.files || [];
        setFiles(fileList);

        // Si el archivo seleccionado ya no existe en la lista, seleccionar el activo o el primero
        const exists = fileList.some((f) => f.name === selectedFilename);
        if (!exists && fileList.length > 0) {
          const defaultFile = fileList.find((f) => f.is_active) || fileList[0];
          setSelectedFilename(defaultFile.name);
        }
      }
    } catch (err) {
      console.error('Error al listar archivos de logs:', err);
      showToast('error', 'No fue posible listar los archivos en storage/logs/', 'Error');
    } finally {
      setLoadingFiles(false);
    }
  }, [selectedFilename, showToast]);

  // ==========================================
  // 3. CARGA DE CONTENIDO DEL ARCHIVO SELECCIONADO
  // ==========================================
  const fetchContent = useCallback(
    async (isSilent = false) => {
      if (!selectedFilename) return;
      if (!isSilent) setRefreshing(true);

      try {
        const params = {
          filename: selectedFilename,
          lines: linesLimit,
          level: selectedLevel,
          search: searchQuery.trim(),
        };

        const res = await api.get('/admin/logs/content', { params });
        if (res.data?.status === 'success' && res.data?.data) {
          const data = res.data.data;
          setLogEntries(data.entries || []);
          setRawContent(data.raw_tail || '');
          setFileStats({
            filename: data.filename || selectedFilename,
            file_size_formatted: data.file_size_formatted || '0 B',
            file_size_bytes: data.file_size_bytes || 0,
            modified_at: data.modified_at || '',
            modified_diff: data.modified_diff || '',
            total_entries_parsed: data.total_entries_parsed || 0,
            filtered_entries_count: data.filtered_entries_count || 0,
            counts_by_level: data.counts_by_level || { ALL: 0, ERROR: 0, WARNING: 0, INFO: 0, DEBUG: 0 },
          });
        }
      } catch (err) {
        console.error('Error al cargar contenido de logs:', err);
        if (!isSilent) {
          showToast('error', 'No se pudo leer el contenido del archivo de registro.', 'Error');
        }
      } finally {
        setLoadingContent(false);
        setRefreshing(false);
      }
    },
    [selectedFilename, linesLimit, selectedLevel, searchQuery, showToast]
  );

  // Carga inicial
  useEffect(() => {
    fetchSettings();
    fetchFiles();
  }, [fetchSettings, fetchFiles]);

  // Recargar contenido cuando cambie el archivo, nivel, límite o búsqueda
  useEffect(() => {
    fetchContent();
  }, [fetchContent]);

  // Auto-scroll al final cuando se cargan nuevas líneas si autoScroll está activo
  useEffect(() => {
    if (autoScroll && terminalScrollRef.current) {
      terminalScrollRef.current.scrollTop = terminalScrollRef.current.scrollHeight;
    }
  }, [logEntries, rawContent, autoScroll]);

  // Polling automático en tiempo real
  useEffect(() => {
    if (isLivePolling) {
      pollTimerRef.current = setInterval(() => {
        fetchContent(true);
      }, 3000);
    } else {
      if (pollTimerRef.current) {
        clearInterval(pollTimerRef.current);
        pollTimerRef.current = null;
      }
    }

    return () => {
      if (pollTimerRef.current) {
        clearInterval(pollTimerRef.current);
        pollTimerRef.current = null;
      }
    };
  }, [isLivePolling, fetchContent]);

  // ==========================================
  // ACCIONES: DESCARGAR, COPIAR, VACIAR / ROTAR
  // ==========================================
  const handleDownloadFile = async () => {
    try {
      showToast('info', `Iniciando descarga de ${selectedFilename}...`, 'Descarga');
      const response = await api.get(`/admin/logs/download/${selectedFilename}`, {
        responseType: 'blob',
      });

      const blob = new Blob([response.data], { type: 'text/plain;charset=utf-8' });
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', selectedFilename);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);

      showToast('success', `Archivo '${selectedFilename}' descargado correctamente.`, 'Descarga Completa');
    } catch (err) {
      console.error('Error al descargar archivo de log:', err);
      showToast('error', 'No fue posible descargar el archivo de registro.', 'Error');
    }
  };

  const handleCopyAll = () => {
    if (!rawContent && logEntries.length === 0) return;
    const textToCopy = rawContent || logEntries.map((e) => e.raw).join('\n');
    navigator.clipboard.writeText(textToCopy).then(() => {
      setCopiedAll(true);
      showToast('success', 'Contenido copiado al portapapeles.', 'Copiado');
      setTimeout(() => setCopiedAll(false), 2500);
    });
  };

  const handleCopySingleEntry = (entry) => {
    const text = entry.raw || entry.message;
    navigator.clipboard.writeText(text).then(() => {
      setCopiedEntryId(entry.id);
      setTimeout(() => setCopiedEntryId(null), 2000);
    });
  };

  const handleConfirmClearOrRotate = async () => {
    setClearModal((prev) => ({ ...prev, isProcessing: true }));
    try {
      const res = await api.post('/admin/logs/clear', {
        filename: clearModal.filename,
        action: clearModal.action,
      });

      if (res.data?.status === 'success') {
        showToast('success', res.data.message, 'Operación Completada');

        // Si se reseteó el modo de depuración a 'no', actualizar estado local
        if (res.data.data?.debug_reset_to_no) {
          setDebugMode('no');
          setIsDebugActive(false);
        }

        setClearModal({ open: false, action: 'clear', filename: 'laravel.log', isProcessing: false });
        await fetchFiles();
        await fetchContent();
      }
    } catch (err) {
      console.error('Error al vaciar/rotar archivo de registro:', err);
      const msg = err.response?.data?.message || 'Error al procesar la acción en el servidor.';
      showToast('error', msg, 'Error');
      setClearModal((prev) => ({ ...prev, isProcessing: false }));
    }
  };

  // ==========================================
  // HELPERS DE RENDERIZADO VISUAL
  // ==========================================
  const getLevelBadge = (level) => {
    const lvl = (level || '').toUpperCase();
    if (['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(lvl)) {
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-red-950/70 text-red-400 border border-red-700/60 shadow-xs">
          <AlertCircle className="w-3 h-3 text-red-400" />
          {lvl}
        </span>
      );
    }
    if (lvl === 'WARNING') {
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-amber-950/70 text-amber-300 border border-amber-700/60 shadow-xs">
          <AlertTriangle className="w-3 h-3 text-amber-300" />
          {lvl}
        </span>
      );
    }
    if (['INFO', 'NOTICE'].includes(lvl)) {
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-sky-950/70 text-sky-300 border border-sky-700/60 shadow-xs">
          <Info className="w-3 h-3 text-sky-400" />
          {lvl}
        </span>
      );
    }
    if (lvl === 'DEBUG') {
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-purple-950/70 text-purple-300 border border-purple-700/60 shadow-xs">
          <Bug className="w-3 h-3 text-purple-400" />
          {lvl}
        </span>
      );
    }
    return (
      <span className="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono uppercase bg-zinc-800 text-zinc-400 border border-zinc-700">
        {lvl || 'LOG'}
      </span>
    );
  };

  return (
    <div className="space-y-6 pb-12">
      {/* ========================================================
          1. ENCABEZADO DEL MÓDULO
      ======================================================== */}
      <div className="bg-[#181818] border border-zinc-800/90 rounded-2xl p-6 shadow-md relative overflow-hidden transition-all">
        <div className="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-[#E50914] via-red-600 to-red-900" />

        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-5">
          <div className="flex items-start gap-4">
            <div className="p-3 bg-red-600/10 border border-red-600/30 text-[#E50914] rounded-2xl shadow-inner shrink-0 mt-0.5">
              <ScrollText className="w-7 h-7" />
            </div>
            <div>
              <div className="flex items-center gap-3 flex-wrap">
                <h1 className="text-xl md:text-2xl font-bold text-zinc-100 tracking-tight">
                  Gestión y Visualización de Logs
                </h1>
                <span className="text-[11px] font-mono uppercase px-2.5 py-0.5 rounded-full bg-zinc-900 text-zinc-400 border border-zinc-800">
                  FlixHN Core Telemetry
                </span>
                {isDebugActive && (
                  <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-500/15 text-red-400 border border-red-500/30 animate-pulse">
                    <span className="w-2 h-2 rounded-full bg-red-500" />
                    Depuración Activa
                  </span>
                )}
              </div>
              <p className="text-xs md:text-sm text-zinc-400 mt-1 leading-relaxed max-w-3xl">
                Supervisa eventos del servidor, trazas de errores de Laravel API y registros de acceso en tiempo real con filtrado de severidad y rotación segura.
              </p>
            </div>
          </div>

          {/* Acciones Rápidas del Encabezado */}
          <div className="flex items-center gap-2 self-start lg:self-center shrink-0">
            <button
              onClick={() => {
                fetchFiles();
                fetchContent();
              }}
              disabled={refreshing}
              className="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-zinc-900 hover:bg-zinc-800 text-zinc-200 border border-zinc-700/80 hover:border-zinc-600 text-xs font-semibold transition-all shadow-xs cursor-pointer disabled:opacity-50"
              title="Refrescar estado y registros"
            >
              <RefreshCw className={`w-3.5 h-3.5 text-zinc-300 ${refreshing ? 'animate-spin text-[#E50914]' : ''}`} />
              <span>Refrescar</span>
            </button>
          </div>
        </div>
      </div>

      {/* ========================================================
          2. TARJETA SUPERIOR: CONFIGURACIÓN (Enable debug logging)
      ======================================================== */}
      <div className="bg-[#1a1a1a] border border-[#2a2a2a] rounded-2xl p-6 shadow-md space-y-5">
        <div className="flex items-center justify-between pb-4 border-b border-zinc-800/80">
          <div className="flex items-center gap-3">
            <div className="p-2.5 bg-zinc-900 text-zinc-200 rounded-xl border border-zinc-800">
              <ShieldAlert className="w-5 h-5 text-red-500" />
            </div>
            <div>
              <h2 className="text-base font-bold text-zinc-100 flex items-center gap-2">
                Ajuste de Nivel de Depuración
                <span className="text-xs font-mono text-zinc-500">(Enable debug logging)</span>
              </h2>
              <p className="text-xs text-zinc-400">
                Controla la verbosidad de los eventos del sistema de archivos y trazas del núcleo de FlixHN.
              </p>
            </div>
          </div>

          <div className="hidden sm:flex items-center gap-2">
            <span className="text-xs text-zinc-400">Estado actual:</span>
            <span
              className={`text-xs font-semibold px-2.5 py-1 rounded-lg border ${
                isDebugActive
                  ? 'bg-red-500/10 text-red-400 border-red-500/30'
                  : 'bg-zinc-900 text-zinc-400 border-zinc-800'
              }`}
            >
              {isDebugActive ? 'Depuración Habilitada' : 'Modo Estándar (No)'}
            </span>
          </div>
        </div>

        {/* Formulario Estilizado de "Enable debug logging" */}
        <div className="grid grid-cols-1 md:grid-cols-12 gap-6 items-end">
          <div className="md:col-span-8 space-y-2">
            <label className="block text-xs font-bold uppercase tracking-wider text-zinc-300">
              Enable debug logging
            </label>

            <div className="relative">
              <select
                id="debug_logging_mode_select"
                value={debugMode}
                onChange={(e) => setDebugMode(e.target.value)}
                className="w-full bg-[#121212] border border-zinc-700/80 hover:border-zinc-600 focus:border-[#E50914] focus:ring-1 focus:ring-[#E50914] text-zinc-200 rounded-xl px-4 py-2.5 text-xs md:text-sm appearance-none outline-none transition-colors cursor-pointer pr-10 font-medium"
              >
                {debugOptions.map((opt) => (
                  <option key={opt.value} value={opt.value} className="bg-[#181818] text-zinc-200 py-1">
                    {opt.label}
                  </option>
                ))}
              </select>

              <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-zinc-400">
                <ChevronDown className="w-4 h-4" />
              </div>
            </div>

            {/* Texto de Ayuda Contextual Requerido */}
            <p className="text-xs text-zinc-400 leading-relaxed pt-1 flex items-start gap-1.5">
              <Info className="w-4 h-4 text-zinc-500 shrink-0 mt-0.5" />
              <span>{helpText}</span>
            </p>
          </div>

          <div className="md:col-span-4 flex md:justify-end">
            <button
              onClick={handleSaveSettings}
              disabled={isSavingSettings}
              className="w-full md:w-auto inline-flex items-center justify-center gap-2 px-6 py-2.5 rounded-xl bg-[#E50914] hover:bg-[#b80710] text-white text-xs md:text-sm font-bold shadow-md hover:shadow-red-600/20 active:scale-95 transition-all cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
            >
              {isSavingSettings ? (
                <>
                  <RefreshCw className="w-4 h-4 animate-spin" />
                  <span>Guardando...</span>
                </>
              ) : (
                <>
                  <Check className="w-4 h-4" />
                  <span>Guardar Ajustes</span>
                </>
              )}
            </button>
          </div>
        </div>
      </div>

      {/* ========================================================
          3. TARJETA INFERIOR: EXPLORADOR Y VISOR DE REGISTROS
      ======================================================== */}
      <div className="bg-[#1a1a1a] border border-[#2a2a2a] rounded-2xl p-6 shadow-md space-y-5">
        {/* Barra de Herramientas y Filtros */}
        <div className="space-y-4 pb-4 border-b border-zinc-800">
          {/* Fila 1: Selector de Archivo, Severidad y Búsqueda */}
          <div className="grid grid-cols-1 md:grid-cols-12 gap-3 items-center">
            {/* Selector de Archivo de Log */}
            <div className="md:col-span-4 relative">
              <label className="block text-[11px] font-semibold text-zinc-400 mb-1">
                Archivo de Registro (storage/logs/)
              </label>
              <div className="relative">
                <select
                  value={selectedFilename}
                  onChange={(e) => setSelectedFilename(e.target.value)}
                  disabled={loadingFiles}
                  className="w-full bg-[#121212] border border-zinc-700/80 hover:border-zinc-600 focus:border-[#E50914] focus:ring-1 focus:ring-[#E50914] text-zinc-200 rounded-xl px-3 py-2 text-xs appearance-none outline-none cursor-pointer pr-8 font-mono"
                >
                  {files.map((f) => (
                    <option key={f.name} value={f.name} className="bg-[#181818] py-1">
                      {f.name} ({f.size_formatted}) {f.is_active ? '• [Activo]' : ''}
                    </option>
                  ))}
                  {files.length === 0 && (
                    <option value="laravel.log">laravel.log (Cargando...)</option>
                  )}
                </select>
                <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2.5 text-zinc-400">
                  <ChevronDown className="w-4 h-4" />
                </div>
              </div>
            </div>

            {/* Selector de Nivel de Severidad */}
            <div className="md:col-span-4">
              <label className="block text-[11px] font-semibold text-zinc-400 mb-1">
                Filtro por Severidad
              </label>
              <div className="flex items-center gap-1 bg-[#121212] p-1 rounded-xl border border-zinc-700/80">
                {['ALL', 'ERROR', 'WARNING', 'INFO', 'DEBUG'].map((level) => {
                  const count = fileStats.counts_by_level?.[level] ?? 0;
                  const isActive = selectedLevel === level;
                  return (
                    <button
                      key={level}
                      onClick={() => setSelectedLevel(level)}
                      className={`flex-1 py-1 px-1.5 rounded-lg text-[11px] font-bold transition-all text-center cursor-pointer ${
                        isActive
                          ? level === 'ERROR'
                            ? 'bg-red-600 text-white shadow-sm'
                            : level === 'WARNING'
                            ? 'bg-amber-600 text-white shadow-sm'
                            : level === 'INFO'
                            ? 'bg-sky-600 text-white shadow-sm'
                            : level === 'DEBUG'
                            ? 'bg-purple-600 text-white shadow-sm'
                            : 'bg-[#E50914] text-white shadow-sm'
                          : 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800/60'
                      }`}
                      title={`Filtrar por ${level} (${count} registros)`}
                    >
                      <span>{level}</span>
                      {count > 0 && level !== 'ALL' && (
                        <span className="ml-1 text-[9px] opacity-80">({count})</span>
                      )}
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Campo de Búsqueda Rápida */}
            <div className="md:col-span-4">
              <label className="block text-[11px] font-semibold text-zinc-400 mb-1">
                Búsqueda en Registros
              </label>
              <div className="relative">
                <Search className="w-4 h-4 text-zinc-500 absolute left-3 top-2.5 pointer-events-none" />
                <input
                  type="text"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder="Buscar texto, excepción o traza..."
                  className="w-full bg-[#121212] border border-zinc-700/80 focus:border-[#E50914] focus:ring-1 focus:ring-[#E50914] text-zinc-200 rounded-xl pl-9 pr-8 py-2 text-xs outline-none transition-colors"
                />
                {searchQuery && (
                  <button
                    onClick={() => setSearchQuery('')}
                    className="absolute right-2.5 top-2.5 text-zinc-500 hover:text-zinc-300"
                    title="Limpiar búsqueda"
                  >
                    <X className="w-3.5 h-3.5" />
                  </button>
                )}
              </div>
            </div>
          </div>

          {/* Fila 2: Barra de Botones de Control y Metadatos */}
          <div className="flex flex-wrap items-center justify-between gap-3 pt-2">
            {/* Metadatos del Archivo Seleccionado */}
            <div className="flex flex-wrap items-center gap-3 text-xs text-zinc-400">
              <span className="inline-flex items-center gap-1.5 font-mono text-zinc-300">
                <FileText className="w-3.5 h-3.5 text-red-500" />
                <strong>{selectedFilename}</strong>
              </span>
              <span className="text-zinc-600">•</span>
              <span className="inline-flex items-center gap-1">
                <HardDrive className="w-3.5 h-3.5 text-zinc-500" />
                <span>{fileStats.file_size_formatted}</span>
              </span>
              <span className="text-zinc-600">•</span>
              <span className="inline-flex items-center gap-1">
                <Clock className="w-3.5 h-3.5 text-zinc-500" />
                <span>Modificado: {fileStats.modified_diff || 'Reciente'}</span>
              </span>
              <span className="text-zinc-600">•</span>
              <span className="text-zinc-400">
                Mostrando <strong className="text-zinc-200">{logEntries.length}</strong> eventos
              </span>
            </div>

            {/* Botonera de Acción */}
            <div className="flex flex-wrap items-center gap-2">
              {/* Límite de Líneas */}
              <select
                value={linesLimit}
                onChange={(e) => setLinesLimit(Number(e.target.value))}
                className="bg-[#121212] border border-zinc-700/80 text-zinc-300 rounded-lg px-2.5 py-1.5 text-xs outline-none cursor-pointer"
                title="Límite de líneas a leer desde el final"
              >
                <option value={100}>Últimas 100 líneas</option>
                <option value={250}>Últimas 250 líneas</option>
                <option value={400}>Últimas 400 líneas</option>
                <option value={800}>Últimas 800 líneas</option>
              </select>

              {/* Botón Polling en Tiempo Real */}
              <button
                onClick={() => setIsLivePolling((prev) => !prev)}
                className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border transition-all cursor-pointer ${
                  isLivePolling
                    ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
                    : 'bg-zinc-900 text-zinc-400 border-zinc-800 hover:text-zinc-200'
                }`}
                title={isLivePolling ? 'Detener monitoreo en vivo' : 'Iniciar monitoreo en vivo (3s)'}
              >
                {isLivePolling ? (
                  <>
                    <span className="w-2 h-2 rounded-full bg-emerald-400 animate-ping" />
                    <span>En Vivo (3s)</span>
                  </>
                ) : (
                  <>
                    <Play className="w-3 h-3" />
                    <span>Auto-Polling</span>
                  </>
                )}
              </button>

              {/* Refresco Manual */}
              <button
                onClick={() => fetchContent()}
                disabled={refreshing}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 text-zinc-200 border border-zinc-700/80 text-xs font-medium transition-colors cursor-pointer disabled:opacity-50"
                title="Refrescar contenido ahora"
              >
                <RefreshCw className={`w-3.5 h-3.5 text-zinc-400 ${refreshing ? 'animate-spin text-red-500' : ''}`} />
                <span className="hidden sm:inline">Actualizar</span>
              </button>

              {/* Descargar Log */}
              <button
                onClick={handleDownloadFile}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 text-zinc-200 border border-zinc-700/80 text-xs font-medium transition-colors cursor-pointer"
                title="Descargar archivo de log completo"
              >
                <Download className="w-3.5 h-3.5 text-sky-400" />
                <span className="hidden sm:inline">Descargar</span>
              </button>

              {/* Copiar Contenido */}
              <button
                onClick={handleCopyAll}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 text-zinc-200 border border-zinc-700/80 text-xs font-medium transition-colors cursor-pointer"
                title="Copiar contenido visible al portapapeles"
              >
                {copiedAll ? (
                  <>
                    <Check className="w-3.5 h-3.5 text-emerald-400" />
                    <span className="text-emerald-400 font-semibold hidden sm:inline">¡Copiado!</span>
                  </>
                ) : (
                  <>
                    <Copy className="w-3.5 h-3.5 text-zinc-400" />
                    <span className="hidden sm:inline">Copiar</span>
                  </>
                )}
              </button>

              {/* Botón Vaciar o Rotar Log */}
              <button
                onClick={() =>
                  setClearModal({
                    open: true,
                    action: 'clear',
                    filename: selectedFilename,
                    isProcessing: false,
                  })
                }
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-red-950/40 hover:bg-[#E50914] text-red-300 hover:text-white border border-red-800/60 hover:border-red-600 text-xs font-semibold transition-all cursor-pointer shadow-xs"
                title="Vaciar o rotar este archivo de log"
              >
                <Trash2 className="w-3.5 h-3.5" />
                <span>Limpiar Log</span>
              </button>
            </div>
          </div>
        </div>

        {/* ========================================================
            VENTANA DE CONSOLA / TERMINAL ESTILO FLIXHN
        ======================================================== */}
        <div className="rounded-xl overflow-hidden border border-[#2a2a2a] bg-[#0d1117] shadow-2xl flex flex-col">
          {/* Barra de Título de la Consola */}
          <div className="bg-[#161b22] px-4 py-2.5 border-b border-zinc-800/80 flex items-center justify-between text-xs font-mono select-none">
            <div className="flex items-center gap-2">
              <div className="flex items-center gap-1.5">
                <span className="w-3 h-3 rounded-full bg-[#ff5f56] inline-block shadow-xs" />
                <span className="w-3 h-3 rounded-full bg-[#ffbd2e] inline-block shadow-xs" />
                <span className="w-3 h-3 rounded-full bg-[#27c93f] inline-block shadow-xs" />
              </div>
              <span className="text-zinc-500 ml-2 hidden sm:inline">terminal:</span>
              <span className="text-zinc-300 font-semibold">
                storage/logs/{selectedFilename}
              </span>
            </div>

            <div className="flex items-center gap-3">
              {/* Toggle de Auto-Scroll */}
              <button
                onClick={() => setAutoScroll((prev) => !prev)}
                className={`flex items-center gap-1 text-[11px] px-2 py-0.5 rounded transition-colors ${
                  autoScroll ? 'text-emerald-400 bg-emerald-500/10' : 'text-zinc-500 hover:text-zinc-400'
                }`}
                title="Desplazarse automáticamente con nuevas líneas"
              >
                <ArrowDownCircle className="w-3 h-3" />
                <span>Auto-scroll</span>
              </button>

              {/* Toggle Modo Vista */}
              <div className="flex items-center bg-zinc-900 rounded p-0.5 border border-zinc-800 text-[10px]">
                <button
                  onClick={() => setViewMode('terminal')}
                  className={`px-2 py-0.5 rounded transition-all cursor-pointer ${
                    viewMode === 'terminal' ? 'bg-zinc-700 text-white font-bold' : 'text-zinc-400 hover:text-zinc-200'
                  }`}
                >
                  Terminal
                </button>
                <button
                  onClick={() => setViewMode('structured')}
                  className={`px-2 py-0.5 rounded transition-all cursor-pointer ${
                    viewMode === 'structured' ? 'bg-zinc-700 text-white font-bold' : 'text-zinc-400 hover:text-zinc-200'
                  }`}
                >
                  Tarjetas
                </button>
              </div>
            </div>
          </div>

          {/* Cuerpo de la Consola con Scroll Personalizado */}
          <div
            ref={terminalScrollRef}
            className="p-4 overflow-y-auto max-h-[580px] min-h-[380px] font-mono text-xs md:text-[13px] leading-relaxed text-zinc-300 space-y-1 selection:bg-red-900/60 selection:text-white"
          >
            {loadingContent ? (
              <div className="flex flex-col items-center justify-center py-24 space-y-3 text-zinc-500">
                <RefreshCw className="w-7 h-7 animate-spin text-[#E50914]" />
                <p className="text-xs font-sans font-medium text-zinc-400">
                  Leyendo registros desde el almacenamiento local...
                </p>
              </div>
            ) : logEntries.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-24 text-zinc-500 space-y-2">
                <Terminal className="w-8 h-8 text-zinc-600" />
                <p className="font-sans text-sm text-zinc-400">
                  {searchQuery || selectedLevel !== 'ALL'
                    ? 'No se encontraron registros que coincidan con los filtros aplicados.'
                    : 'El archivo de registro está vacío o no contiene entradas legibles.'}
                </p>
                {(searchQuery || selectedLevel !== 'ALL') && (
                  <button
                    onClick={() => {
                      setSearchQuery('');
                      setSelectedLevel('ALL');
                    }}
                    className="text-xs text-red-400 underline hover:text-red-300 mt-2 cursor-pointer font-sans"
                  >
                    Restablecer filtros de búsqueda
                  </button>
                )}
              </div>
            ) : viewMode === 'terminal' ? (
              /* MODO 1: VISOR TIPO TERMINAL MONOSPACE */
              logEntries.map((entry) => {
                const isCopied = copiedEntryId === entry.id;
                const isError = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(entry.level);
                const isWarning = entry.level === 'WARNING';

                return (
                  <div
                    key={entry.id}
                    className={`group/line flex items-start gap-2.5 py-1 px-2 rounded hover:bg-zinc-800/40 transition-colors border-l-2 ${
                      isError
                        ? 'border-red-500/80 bg-red-950/10'
                        : isWarning
                        ? 'border-amber-500/80 bg-amber-950/10'
                        : 'border-transparent'
                    }`}
                  >
                    {/* Timestamp */}
                    {entry.timestamp && (
                      <span className="text-zinc-500 shrink-0 select-none text-[11px]">
                        [{entry.timestamp}]
                      </span>
                    )}

                    {/* Badge de Severidad */}
                    <div className="shrink-0 select-none">
                      {getLevelBadge(entry.level)}
                    </div>

                    {/* Mensaje Principal y Stacktrace */}
                    <div className="flex-1 min-w-0 break-words">
                      <span
                        className={`${
                          isError
                            ? 'text-red-300 font-semibold'
                            : isWarning
                            ? 'text-amber-200'
                            : 'text-zinc-200'
                        }`}
                      >
                        {entry.message}
                      </span>

                      {/* Stacktrace si existe */}
                      {entry.stacktrace_text && (
                        <div className="mt-1.5 p-2 rounded bg-black/60 border border-zinc-800/80 text-[11px] text-zinc-400 whitespace-pre-wrap leading-normal font-mono select-text">
                          {entry.stacktrace_text}
                        </div>
                      )}
                    </div>

                    {/* Botón copiar línea individual */}
                    <button
                      onClick={() => handleCopySingleEntry(entry)}
                      className="opacity-0 group-hover/line:opacity-100 p-1 text-zinc-500 hover:text-zinc-200 transition-opacity shrink-0 cursor-pointer"
                      title="Copiar esta entrada"
                    >
                      {isCopied ? (
                        <Check className="w-3.5 h-3.5 text-emerald-400" />
                      ) : (
                        <Copy className="w-3.5 h-3.5" />
                      )}
                    </button>
                  </div>
                );
              })
            ) : (
              /* MODO 2: VISOR ESTRUCTURADO (TARJETAS) */
              <div className="space-y-2 font-sans">
                {logEntries.map((entry) => {
                  const isError = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(entry.level);
                  const isWarning = entry.level === 'WARNING';
                  const isCopied = copiedEntryId === entry.id;

                  return (
                    <div
                      key={entry.id}
                      className="bg-[#12161f] border border-zinc-800/90 rounded-xl p-3.5 space-y-2 hover:border-zinc-700 transition-all shadow-xs"
                    >
                      <div className="flex items-center justify-between gap-3 flex-wrap">
                        <div className="flex items-center gap-2">
                          {getLevelBadge(entry.level)}
                          {entry.env && (
                            <span className="text-[10px] font-mono text-zinc-400 bg-zinc-900 px-1.5 py-0.5 rounded border border-zinc-800">
                              env:{entry.env}
                            </span>
                          )}
                          <span className="text-xs font-mono text-zinc-400">
                            {entry.timestamp || 'Sin marca de tiempo'}
                          </span>
                        </div>

                        <button
                          onClick={() => handleCopySingleEntry(entry)}
                          className="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-zinc-900 hover:bg-zinc-800 text-zinc-300 border border-zinc-800 text-[11px] transition-colors cursor-pointer"
                        >
                          {isCopied ? (
                            <>
                              <Check className="w-3 h-3 text-emerald-400" />
                              <span className="text-emerald-400 font-semibold">Copiado</span>
                            </>
                          ) : (
                            <>
                              <Copy className="w-3 h-3 text-zinc-400" />
                              <span>Copiar</span>
                            </>
                          )}
                        </button>
                      </div>

                      <p
                        className={`text-xs md:text-sm font-mono leading-relaxed break-words ${
                          isError ? 'text-red-300 font-medium' : isWarning ? 'text-amber-200' : 'text-zinc-200'
                        }`}
                      >
                        {entry.message}
                      </p>

                      {entry.stacktrace_text && (
                        <details className="mt-2 text-xs">
                          <summary className="cursor-pointer text-zinc-400 hover:text-zinc-200 select-none font-medium flex items-center gap-1.5 py-1">
                            <span>Ver traza completa de la excepción (Stacktrace)</span>
                          </summary>
                          <div className="mt-1.5 p-3 rounded-lg bg-[#080b0f] border border-red-950/60 font-mono text-[11px] text-red-300/80 whitespace-pre-wrap leading-relaxed max-h-60 overflow-y-auto">
                            {entry.stacktrace_text}
                          </div>
                        </details>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </div>

          {/* Pie de la Consola con Estadísticas */}
          <div className="bg-[#161b22] px-4 py-2 border-t border-zinc-800 flex items-center justify-between text-xs text-zinc-400 font-mono select-none">
            <div className="flex items-center gap-4">
              <span>Líneas analizadas: {fileStats.total_entries_parsed}</span>
              <span className="hidden sm:inline">•</span>
              <span className="hidden sm:inline">Filtro: {selectedLevel}</span>
            </div>

            <button
              onClick={() => {
                if (terminalScrollRef.current) {
                  terminalScrollRef.current.scrollTop = terminalScrollRef.current.scrollHeight;
                }
              }}
              className="text-zinc-400 hover:text-white inline-flex items-center gap-1 text-[11px] cursor-pointer"
            >
              <ArrowDownCircle className="w-3.5 h-3.5" />
              <span>Bajar al final</span>
            </button>
          </div>
        </div>
      </div>

      {/* ========================================================
          4. MODAL DE CONFIRMACIÓN (VACIAR O ROTAR LOG)
      ======================================================== */}
      {clearModal.open && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fade-in">
          <div className="bg-[#181818] border border-zinc-800 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-5 relative">
            <div className="flex items-start gap-4">
              <div className="p-3 bg-red-600/10 border border-red-600/30 text-[#E50914] rounded-xl shrink-0">
                <AlertTriangle className="w-6 h-6" />
              </div>
              <div className="space-y-1 flex-1">
                <h3 className="text-lg font-bold text-zinc-100">
                  Confirmar Limpieza o Rotación de Registro
                </h3>
                <p className="text-xs text-zinc-400">
                  Archivo objetivo:{' '}
                  <code className="text-zinc-200 font-mono font-bold">{clearModal.filename}</code>
                </p>
              </div>
              <button
                onClick={() => setClearModal((prev) => ({ ...prev, open: false }))}
                className="text-zinc-400 hover:text-zinc-100 p-1 rounded-lg hover:bg-zinc-800 transition-colors"
                title="Cerrar modal"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Selector de Acción: Truncate vs Rotate */}
            <div className="space-y-3">
              <label className="block text-xs font-bold text-zinc-300 uppercase tracking-wide">
                Selecciona la acción a realizar:
              </label>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <button
                  type="button"
                  onClick={() => setClearModal((prev) => ({ ...prev, action: 'clear' }))}
                  className={`p-3.5 rounded-xl border text-left transition-all cursor-pointer ${
                    clearModal.action === 'clear'
                      ? 'bg-red-500/10 border-[#E50914] text-white shadow-xs'
                      : 'bg-[#121212] border-zinc-800 text-zinc-400 hover:text-zinc-200 hover:border-zinc-700'
                  }`}
                >
                  <div className="flex items-center gap-2 font-semibold text-xs text-zinc-200">
                    <Trash2 className="w-4 h-4 text-red-500" />
                    <span>Vaciar Archivo</span>
                  </div>
                  <p className="text-[11px] text-zinc-400 mt-1">
                    Trunca el contenido a 0 bytes manteniendo el nombre original activo.
                  </p>
                </button>

                <button
                  type="button"
                  onClick={() => setClearModal((prev) => ({ ...prev, action: 'rotate' }))}
                  className={`p-3.5 rounded-xl border text-left transition-all cursor-pointer ${
                    clearModal.action === 'rotate'
                      ? 'bg-red-500/10 border-[#E50914] text-white shadow-xs'
                      : 'bg-[#121212] border-zinc-800 text-zinc-400 hover:text-zinc-200 hover:border-zinc-700'
                  }`}
                >
                  <div className="flex items-center gap-2 font-semibold text-xs text-zinc-200">
                    <RotateCcw className="w-4 h-4 text-amber-500" />
                    <span>Rotar y Respaldar</span>
                  </div>
                  <p className="text-[11px] text-zinc-400 mt-1">
                    Copia el archivo con marca de tiempo actual y vacía el archivo activo.
                  </p>
                </button>
              </div>
            </div>

            {/* Aviso especial de "until_rotation" */}
            {debugMode === 'until_rotation' && (
              <div className="bg-amber-500/10 border border-amber-500/30 rounded-xl p-3 flex items-start gap-2 text-xs text-amber-300">
                <Info className="w-4 h-4 shrink-0 mt-0.5" />
                <span>
                  <strong>Aviso de Depuración:</strong> Tu configuración actual es{' '}
                  <em>"Yes, until the next log file rotation"</em>. Al completar esta acción, el modo de depuración se restablecerá automáticamente a <strong>No</strong>.
                </span>
              </div>
            )}

            <p className="text-xs text-zinc-400 leading-relaxed">
              ¿Estás seguro de que deseas proceder con esta acción sobre el archivo de registro del servidor? Esta acción no se puede deshacer.
            </p>

            <div className="flex items-center justify-end gap-3 pt-2">
              <button
                type="button"
                onClick={() => setClearModal((prev) => ({ ...prev, open: false }))}
                className="px-4 py-2 rounded-xl text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-800 hover:bg-zinc-700 transition-colors cursor-pointer"
              >
                Cancelar
              </button>
              <button
                type="button"
                onClick={handleConfirmClearOrRotate}
                disabled={clearModal.isProcessing}
                className="inline-flex items-center gap-2 px-5 py-2 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#b80710] shadow-md hover:shadow-red-600/30 transition-all cursor-pointer disabled:opacity-60"
              >
                {clearModal.isProcessing ? (
                  <>
                    <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                    <span>Procesando...</span>
                  </>
                ) : (
                  <>
                    <Trash2 className="w-3.5 h-3.5" />
                    <span>
                      {clearModal.action === 'rotate' ? 'Confirmar Rotación' : 'Confirmar Vaciado'}
                    </span>
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================
          5. SISTEMA DE TOASTS FLOTANTES (Cero alertas nativas)
      ======================================================== */}
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
            <p className="text-xs text-zinc-300 mt-0.5 leading-relaxed font-sans">
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
