import React, { useState, useEffect, useCallback, useMemo } from 'react';
import api from '../../services/api';
import {
  Folder,
  FolderOpen,
  FolderPlus,
  ArrowUp,
  RefreshCw,
  HardDrive,
  Check,
  X,
  Server,
  ChevronRight,
  AlertCircle,
  Plus,
  CheckCircle2,
  Lock,
  CornerDownRight,
} from 'lucide-react';

/**
 * Modal Explorador de Carpetas del Servidor (Server Directory Picker)
 * Permite explorar interactivamente los directorios del host o contenedor de FlixHN,
 * crear nuevas carpetas y seleccionar rutas de destino para ingesta y sincronización OTT.
 */
export default function FolderPickerModal({
  isOpen,
  onClose,
  onSelect,
  initialPath = '/storage/media',
  title = 'Explorador de Carpetas del Servidor',
}) {
  const [currentPath, setCurrentPath] = useState(initialPath || '/storage/media');
  const [parentPath, setParentPath] = useState(null);
  const [directories, setDirectories] = useState([]);
  const [canCreateFolder, setCanCreateFolder] = useState(true);
  const [selectedPath, setSelectedPath] = useState(initialPath || '/storage/media');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  // Estado para creación interactiva de subcarpetas (Dark UI - Cero window.prompt)
  const [isCreatingFolder, setIsCreatingFolder] = useState(false);
  const [newFolderName, setNewFolderName] = useState('');
  const [creatingLoading, setCreatingLoading] = useState(false);
  const [actionFeedback, setActionFeedback] = useState(null); // { type: 'success' | 'error', message: string }

  // Cargar lista de directorios desde el backend
  const loadDirectory = useCallback(async (targetPath) => {
    setLoading(true);
    setError(null);
    setActionFeedback(null);
    try {
      const res = await api.post('/admin/filesystem/browse-directories', {
        current_path: targetPath,
      });

      if (res.data) {
        const pathData = res.data.current_path || targetPath;
        setCurrentPath(pathData);
        setParentPath(res.data.parent_path !== undefined ? res.data.parent_path : null);
        setDirectories(Array.isArray(res.data.directories) ? res.data.directories : []);
        setCanCreateFolder(Boolean(res.data.can_create_folder));
        setSelectedPath(pathData);
      }
    } catch (err) {
      console.error('Error al explorar directorios del servidor FlixHN:', err);
      const msg = err.response?.data?.message || 'No fue posible acceder a la ruta especificada en el servidor.';
      setError(msg);
    } finally {
      setLoading(false);
    }
  }, []);

  // Cargar al abrir o al cambiar de initialPath
  useEffect(() => {
    if (isOpen) {
      loadDirectory(initialPath || '/storage/media');
      setIsCreatingFolder(false);
      setNewFolderName('');
      setActionFeedback(null);
    }
  }, [isOpen, initialPath, loadDirectory]);

  // Limpiar feedback tras 4 segundos
  useEffect(() => {
    if (!actionFeedback) return;
    const t = setTimeout(() => setActionFeedback(null), 4000);
    return () => clearTimeout(t);
  }, [actionFeedback]);

  // Generador de Breadcrumbs interactivos desde la ruta actual
  const breadcrumbs = useMemo(() => {
    if (!currentPath) return [];
    const segments = currentPath.split('/').filter(Boolean);
    const crumbs = [{ name: 'storage', path: currentPath.startsWith('/') ? '/' : '' }];

    let acc = '';
    segments.forEach((seg) => {
      acc += '/' + seg;
      crumbs.push({ name: seg, path: acc });
    });

    return crumbs;
  }, [currentPath]);

  // Crear nueva subcarpeta directamente en el servidor
  const handleCreateFolder = async (e) => {
    if (e) e.preventDefault();
    const trimmed = newFolderName.trim();
    if (!trimmed) {
      setActionFeedback({ type: 'error', message: 'Ingresa un nombre para la nueva carpeta.' });
      return;
    }

    setCreatingLoading(true);
    setActionFeedback(null);
    try {
      const res = await api.post('/admin/filesystem/make-directory', {
        path: currentPath,
        name: trimmed,
      });

      if (res.data?.success) {
        setActionFeedback({
          type: 'success',
          message: res.data.message || `Carpeta '${trimmed}' creada exitosamente.`,
        });
        setNewFolderName('');
        setIsCreatingFolder(false);
        // Recargar la carpeta actual y preseleccionar la nueva
        await loadDirectory(currentPath);
        if (res.data.directory?.path) {
          setSelectedPath(res.data.directory.path);
        }
      } else {
        setActionFeedback({
          type: 'error',
          message: res.data?.message || 'No se pudo crear la carpeta.',
        });
      }
    } catch (err) {
      const msg = err.response?.data?.message || 'Error al comunicarse con el servidor para crear la carpeta.';
      setActionFeedback({ type: 'error', message: msg });
    } finally {
      setCreatingLoading(false);
    }
  };

  // Confirmar selección
  const handleConfirm = () => {
    if (onSelect) {
      onSelect(selectedPath || currentPath);
    }
    if (onClose) {
      onClose();
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-black/85 backdrop-blur-md animate-in fade-in duration-200">
      <div className="relative w-full max-w-2xl bg-[#161616] border border-zinc-800 rounded-2xl shadow-2xl flex flex-col max-h-[88vh] overflow-hidden">
        {/* Línea decorativa superior */}
        <div className="h-1.5 w-full bg-gradient-to-r from-red-600 via-amber-500 to-red-600" />

        {/* Encabezado del Modal */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-zinc-800/80 bg-[#141414]">
          <div className="flex items-center gap-3">
            <div className="p-2.5 bg-amber-500/10 border border-amber-500/30 rounded-xl text-amber-400">
              <Server className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-base sm:text-lg font-bold text-white tracking-wide">
                {title}
              </h3>
              <p className="text-xs text-zinc-400 flex items-center gap-1.5 mt-0.5">
                <span>Rutas del Servidor FlixHN</span>
                <span className="text-zinc-600">•</span>
                <span className="text-emerald-400 font-mono flex items-center gap-1 text-[11px]">
                  <HardDrive className="w-3 h-3 inline" /> En Línea (On-Net)
                </span>
              </p>
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="text-zinc-400 hover:text-white p-1.5 rounded-lg hover:bg-zinc-800 transition-colors cursor-pointer"
            title="Cerrar explorador"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Barra de Navegación y Breadcrumb */}
        <div className="p-4 bg-zinc-950/70 border-b border-zinc-800/80 space-y-3">
          <div className="flex items-center gap-2">
            {/* Botón Subir un Nivel (..) */}
            <button
              type="button"
              disabled={!parentPath || loading}
              onClick={() => parentPath && loadDirectory(parentPath)}
              title={parentPath ? `Subir a ${parentPath}` : 'Ya estás en la raíz de almacenamiento'}
              className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-zinc-900 border border-zinc-700/80 text-zinc-300 hover:text-white hover:bg-zinc-800 disabled:opacity-40 disabled:pointer-events-none transition-all cursor-pointer shrink-0"
            >
              <ArrowUp className="w-3.5 h-3.5 text-amber-400" />
              <span>Subir nivel</span>
            </button>

            {/* Breadcrumb Interactivo */}
            <div className="flex-1 min-w-0 bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-1.5 flex items-center gap-1 overflow-x-auto scrollbar-thin">
              <HardDrive className="w-3.5 h-3.5 text-zinc-500 shrink-0 mr-1" />
              {breadcrumbs.map((crumb, idx) => (
                <React.Fragment key={crumb.path + idx}>
                  {idx > 0 && <ChevronRight className="w-3 h-3 text-zinc-600 shrink-0" />}
                  <button
                    type="button"
                    onClick={() => loadDirectory(crumb.path === '/' ? '/storage' : crumb.path)}
                    className={`text-xs font-mono px-1.5 py-0.5 rounded hover:bg-zinc-800 transition-colors truncate shrink-0 cursor-pointer ${
                      idx === breadcrumbs.length - 1
                        ? 'font-bold text-amber-400 bg-amber-500/10 border border-amber-500/20'
                        : 'text-zinc-300 hover:text-white'
                    }`}
                  >
                    {crumb.name}
                  </button>
                </React.Fragment>
              ))}
            </div>

            {/* Botón Refrescar */}
            <button
              type="button"
              disabled={loading}
              onClick={() => loadDirectory(currentPath)}
              title="Refrescar directorio"
              className="p-2 text-zinc-400 hover:text-white bg-zinc-900 border border-zinc-800 rounded-lg hover:bg-zinc-800 transition-all cursor-pointer shrink-0"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin text-amber-400' : ''}`} />
            </button>
          </div>

          {/* Barra de Acciones: Contador de carpetas y Botón + Nueva Carpeta */}
          <div className="flex items-center justify-between text-xs pt-1">
            <span className="text-zinc-400">
              {loading ? (
                'Explorando servidor...'
              ) : (
                <>
                  <span className="font-semibold text-zinc-200">{directories.length}</span>{' '}
                  {directories.length === 1 ? 'carpeta encontrada' : 'carpetas encontradas'}
                </>
              )}
            </span>

            {canCreateFolder && (
              <button
                type="button"
                onClick={() => {
                  setIsCreatingFolder(!isCreatingFolder);
                  setActionFeedback(null);
                }}
                className={`flex items-center gap-1.5 px-3 py-1 text-xs font-semibold rounded-lg border transition-all cursor-pointer ${
                  isCreatingFolder
                    ? 'bg-amber-500/20 text-amber-300 border-amber-500/40'
                    : 'bg-zinc-900 text-zinc-300 border-zinc-700 hover:text-white hover:bg-zinc-800'
                }`}
              >
                <FolderPlus className="w-3.5 h-3.5 text-amber-400" />
                <span>+ Nueva Carpeta</span>
              </button>
            )}
          </div>

          {/* Formulario Inline para "+ Nueva Carpeta" (Cero prompt() nativo) */}
          {isCreatingFolder && (
            <form
              onSubmit={handleCreateFolder}
              className="p-3 bg-zinc-900/90 border border-amber-500/30 rounded-xl space-y-2 animate-in fade-in duration-150"
            >
              <div className="flex items-center justify-between">
                <label className="text-[11px] font-semibold text-amber-300 flex items-center gap-1.5">
                  <CornerDownRight className="w-3.5 h-3.5" />
                  Crear subcarpeta dentro de:{' '}
                  <span className="font-mono text-zinc-300 truncate max-w-[280px]">
                    {currentPath}
                  </span>
                </label>
                <button
                  type="button"
                  onClick={() => setIsCreatingFolder(false)}
                  className="text-zinc-500 hover:text-zinc-300 text-xs"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              </div>

              <div className="flex items-center gap-2">
                <input
                  type="text"
                  autoFocus
                  required
                  placeholder="ej: netflix_movies, 4k_series, documentales..."
                  value={newFolderName}
                  onChange={(e) => setNewFolderName(e.target.value)}
                  className="flex-1 bg-zinc-950 border border-zinc-700 rounded-lg px-3 py-1.5 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-amber-400 font-mono"
                />
                <button
                  type="submit"
                  disabled={creatingLoading || !newFolderName.trim()}
                  className="flex items-center gap-1.5 px-4 py-1.5 bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-black font-bold text-xs rounded-lg transition-all cursor-pointer shadow-md"
                >
                  {creatingLoading ? (
                    <>
                      <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                      <span>Creando...</span>
                    </>
                  ) : (
                    <>
                      <Plus className="w-3.5 h-3.5" />
                      <span>Crear</span>
                    </>
                  )}
                </button>
                <button
                  type="button"
                  onClick={() => setIsCreatingFolder(false)}
                  className="px-2.5 py-1.5 text-xs text-zinc-400 hover:text-white transition-colors"
                >
                  Cancelar
                </button>
              </div>
            </form>
          )}

          {/* Feedback de acciones (Éxito o Error) */}
          {actionFeedback && (
            <div
              className={`p-2.5 rounded-lg text-xs flex items-center gap-2 border animate-in fade-in duration-150 ${
                actionFeedback.type === 'success'
                  ? 'bg-emerald-950/40 text-emerald-300 border-emerald-800/50'
                  : 'bg-red-950/40 text-red-300 border-red-800/50'
              }`}
            >
              {actionFeedback.type === 'success' ? (
                <CheckCircle2 className="w-4 h-4 shrink-0 text-emerald-400" />
              ) : (
                <AlertCircle className="w-4 h-4 shrink-0 text-red-400" />
              )}
              <span className="flex-1">{actionFeedback.message}</span>
            </div>
          )}
        </div>

        {/* Lista de Carpetas / Contenido */}
        <div className="flex-1 overflow-y-auto p-4 min-h-[260px] max-h-[420px] bg-[#121212] scrollbar-thin">
          {loading ? (
            <div className="flex flex-col items-center justify-center py-16 text-zinc-400 space-y-3">
              <RefreshCw className="w-8 h-8 animate-spin text-amber-400" />
              <p className="text-xs font-medium">Explorando rutas en disco del servidor...</p>
            </div>
          ) : error ? (
            <div className="flex flex-col items-center justify-center py-12 px-4 text-center space-y-3 bg-red-950/20 border border-red-900/40 rounded-xl my-4">
              <AlertCircle className="w-8 h-8 text-red-400" />
              <div>
                <h4 className="text-sm font-bold text-red-200">Error al leer la ruta</h4>
                <p className="text-xs text-zinc-400 mt-1 max-w-md">{error}</p>
              </div>
              <button
                type="button"
                onClick={() => loadDirectory('/storage/media')}
                className="px-4 py-1.5 bg-zinc-800 hover:bg-zinc-700 text-xs text-white rounded-lg transition-colors cursor-pointer"
              >
                Volver a /storage/media
              </button>
            </div>
          ) : directories.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-14 text-zinc-500 space-y-3 border-2 border-dashed border-zinc-800/80 rounded-xl">
              <div className="p-3 bg-zinc-900 rounded-full text-zinc-600">
                <FolderOpen className="w-8 h-8" />
              </div>
              <div className="text-center">
                <p className="text-xs font-semibold text-zinc-300">
                  Esta carpeta no contiene subdirectorios
                </p>
                <p className="text-[11px] text-zinc-500 mt-0.5">
                  Puedes seleccionar esta ruta actual o crear una nueva subcarpeta arriba.
                </p>
              </div>
            </div>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
              {directories.map((dir) => {
                const isSelected = selectedPath === dir.path;
                return (
                  <div
                    key={dir.path}
                    onClick={() => setSelectedPath(dir.path)}
                    onDoubleClick={() => loadDirectory(dir.path)}
                    className={`group flex items-center justify-between p-3 rounded-xl border transition-all cursor-pointer select-none ${
                      isSelected
                        ? 'bg-amber-500/10 border-amber-500/60 shadow-lg shadow-amber-950/30'
                        : 'bg-zinc-900/60 border-zinc-800/80 hover:bg-zinc-800/70 hover:border-zinc-700'
                    }`}
                  >
                    <div className="flex items-center gap-3 min-w-0 flex-1">
                      <div
                        className={`p-2 rounded-lg transition-colors ${
                          isSelected
                            ? 'bg-amber-500 text-black'
                            : 'bg-zinc-800/80 text-amber-400 group-hover:text-amber-300'
                        }`}
                      >
                        <Folder className="w-5 h-5 fill-current" />
                      </div>

                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-1.5">
                          <span
                            className={`text-xs font-semibold truncate ${
                              isSelected ? 'text-white font-bold' : 'text-zinc-200 group-hover:text-white'
                            }`}
                            title={dir.name}
                          >
                            {dir.name}
                          </span>
                        </div>
                        <div className="flex items-center gap-2 mt-0.5 text-[10px] text-zinc-500">
                          <span>
                            {dir.item_count === 0
                              ? 'Vacía'
                              : `${dir.item_count} ${dir.item_count === 1 ? 'elemento' : 'elementos'}`}
                          </span>
                          {!dir.is_writable && (
                            <span className="text-red-400 flex items-center gap-0.5">
                              <Lock className="w-2.5 h-2.5 inline" /> Solo lectura
                            </span>
                          )}
                        </div>
                      </div>
                    </div>

                    <div className="flex items-center gap-1 pl-2">
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          loadDirectory(dir.path);
                        }}
                        title={`Entrar a ${dir.name}`}
                        className="p-1.5 text-zinc-500 hover:text-amber-400 hover:bg-zinc-800 rounded-lg transition-colors cursor-pointer"
                      >
                        <ChevronRight className="w-4 h-4" />
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>

        {/* Pie del Modal con Ruta Seleccionada y Botones de Acción */}
        <div className="p-4 bg-[#141414] border-t border-zinc-800/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          {/* Ruta seleccionada actual */}
          <div className="min-w-0 flex-1">
            <span className="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold block mb-0.5">
              Ruta destino a asignar:
            </span>
            <div className="flex items-center gap-2 bg-zinc-950 border border-zinc-800 rounded-lg px-2.5 py-1.5">
              <HardDrive className="w-3.5 h-3.5 text-amber-400 shrink-0" />
              <span className="font-mono text-xs text-zinc-200 truncate" title={selectedPath || currentPath}>
                {selectedPath || currentPath}
              </span>
            </div>
          </div>

          {/* Botones de acción inferiores */}
          <div className="flex items-center justify-end gap-2 shrink-0">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 text-xs font-semibold text-zinc-400 hover:text-white transition-colors cursor-pointer"
            >
              Cancelar
            </button>
            <button
              type="button"
              onClick={handleConfirm}
              className="flex items-center gap-1.5 px-5 py-2 bg-[#E50914] hover:bg-red-700 text-white text-xs font-bold rounded-xl shadow-lg transition-all active:scale-95 cursor-pointer"
            >
              <Check className="w-4 h-4" />
              <span>Seleccionar esta carpeta</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
