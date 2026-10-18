import React, { useState, useEffect, useCallback, useRef } from 'react';
import api from '../../services/api';
import {
  Film,
  Tv,
  Radio,
  Folder,
  FolderOpen,
  ChevronRight,
  ChevronDown,
  RefreshCw,
  Search,
  Lock,
  Unlock,
  Trash2,
  Edit3,
  Sparkles,
  ExternalLink,
  Copy,
  Plus,
  X,
  Save,
  CheckCircle2,
  AlertCircle,
  AlertTriangle,
  Loader2,
  Tag,
  Star,
  Calendar,
  Layers,
  Users,
  Building2,
  Globe,
  Sliders,
  Maximize2,
  Minimize2,
  ChevronsUpDown,
} from 'lucide-react';

const COMMON_GENRES = [
  'Acción',
  'Aventura',
  'Animación',
  'Comedia',
  'Crimen',
  'Documental',
  'Drama',
  'Fantasía',
  'Familia',
  'Terror',
  'Misterio',
  'Romance',
  'Ciencia Ficción',
  'Suspense',
  'Bélica',
  'Western',
  'Música',
  'Historia',
];

const COMMON_STUDIOS = [
  'Warner Bros. Pictures',
  'Universal Pictures',
  'Paramount Pictures',
  'Columbia Pictures',
  'Sony Pictures',
  'Walt Disney Pictures',
  '20th Century Studios',
  'Marvel Studios',
  'Netflix',
  'HBO',
  'Amazon Studios',
  'A24',
  'Legendary Pictures',
  'Lionsgate',
];

const PARENTAL_RATINGS = [
  'G',
  'PG',
  'PG-13',
  'R',
  'NC-17',
  'TV-Y',
  'TV-Y7',
  'TV-G',
  'TV-PG',
  'TV-14',
  'TV-MA',
  'NR',
];

const LANGUAGES = [
  { code: 'es-ES', label: 'Español (España) - es-ES' },
  { code: 'es-MX', label: 'Español Latino (México) - es-MX' },
  { code: 'en-US', label: 'Inglés (EE.UU.) - en-US' },
  { code: 'pt-BR', label: 'Portugués (Brasil) - pt-BR' },
  { code: 'fr-FR', label: 'Francés (Francia) - fr-FR' },
];

const VIDEO_FORMATS_3D = [
  'None',
  'Half-SBS (Side by Side)',
  'Full-SBS',
  'Half-OU (Top-Bottom)',
  'Full-OU',
  'Anaglyph (Red-Cyan)',
];

export default function MetadataManager() {
  // --- Estados del Árbol de Medios ---
  const [treeNodes, setTreeNodes] = useState([]);
  const [expandedNodes, setExpandedNodes] = useState(new Set());
  const [childrenCache, setChildrenCache] = useState({}); // { [nodeKey]: [nodes] }
  const [loadingNodes, setLoadingNodes] = useState(new Set());
  const [treeLoading, setTreeLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState(null);
  const [isSearching, setIsSearching] = useState(false);

  // --- Elemento Seleccionado y Formulario ---
  const [selectedNode, setSelectedNode] = useState(null); // { id, real_id, type }
  const [itemData, setItemData] = useState(null);
  const [originalItemData, setOriginalItemData] = useState(null);
  const [loadingItem, setLoadingItem] = useState(false);
  const [savingItem, setSavingItem] = useState(false);
  const [hasChanges, setHasChanges] = useState(false);

  // --- Menú Contextual (Clic Derecho) ---
  const [contextMenu, setContextMenu] = useState(null); // { x, y, node }
  const contextMenuRef = useRef(null);

  // --- Modales ---
  const [deleteModal, setDeleteModal] = useState(null); // node to delete
  const [isDeleting, setIsDeleting] = useState(false);

  const [externalSearchModal, setExternalSearchModal] = useState(false);
  const [externalSearchQuery, setExternalSearchQuery] = useState('');
  const [externalSearchType, setExternalSearchType] = useState('all');
  const [externalResults, setExternalResults] = useState([]);
  const [searchingExternal, setSearchingExternal] = useState(false);

  // Modal para agregar persona al cast
  const [addPersonModal, setAddPersonModal] = useState(false);
  const [newPersonName, setNewPersonName] = useState('');
  const [newPersonRole, setNewPersonRole] = useState('Actor');

  // Input desplegable para agregar taxonomías
  const [addingGenre, setAddingGenre] = useState(false);
  const [genreInputValue, setGenreInputValue] = useState('');
  const [addingStudio, setAddingStudio] = useState(false);
  const [studioInputValue, setStudioInputValue] = useState('');
  const [addingTag, setAddingTag] = useState(false);
  const [tagInputValue, setTagInputValue] = useState('');

  // --- Notificaciones Toast Flotantes (Cero alertas nativas) ---
  const [toast, setToast] = useState(null); // { type: 'success' | 'error' | 'info', message: string, title?: string }

  const showToast = useCallback((type, message, title = '') => {
    setToast({ type, message, title });
  }, []);

  useEffect(() => {
    if (!toast) return;
    const timer = setTimeout(() => setToast(null), 4500);
    return () => clearTimeout(timer);
  }, [toast]);

  // Cerrar menú contextual al hacer clic en cualquier parte
  useEffect(() => {
    const handleDocumentClick = (e) => {
      if (contextMenuRef.current && !contextMenuRef.current.contains(e.target)) {
        setContextMenu(null);
      }
    };
    window.addEventListener('click', handleDocumentClick);
    window.addEventListener('contextmenu', handleDocumentClick);
    return () => {
      window.removeEventListener('click', handleDocumentClick);
      window.removeEventListener('contextmenu', handleDocumentClick);
    };
  }, []);

  // --- 1. Cargar Nivel Raíz del Árbol ---
  const loadRootTree = useCallback(async () => {
    setTreeLoading(true);
    try {
      const res = await api.get('/admin/metadata/tree');
      if (res.data?.status === 'success') {
        setTreeNodes(res.data.nodes || []);
      }
    } catch (err) {
      console.error('Error cargando árbol de metadatos:', err);
      showToast('error', 'No fue posible cargar el árbol de bibliotecas.', 'Error de Conexión');
    } finally {
      setTreeLoading(false);
    }
  }, [showToast]);

  useEffect(() => {
    loadRootTree();
  }, [loadRootTree]);

  // --- 2. Búsqueda Reactiva en el Árbol ---
  const handleSearchSubmit = async (e) => {
    if (e) e.preventDefault();
    const query = searchQuery.trim();
    if (!query) {
      setSearchResults(null);
      return;
    }

    setIsSearching(true);
    try {
      const res = await api.get('/admin/metadata/tree', {
        params: { search: query },
      });
      if (res.data?.status === 'success') {
        setSearchResults(res.data.nodes || []);
      }
    } catch (err) {
      console.error('Error en búsqueda de árbol:', err);
      showToast('error', 'Error al buscar en el catálogo.', 'Búsqueda');
    } finally {
      setIsSearching(false);
    }
  };

  const handleClearSearch = () => {
    setSearchQuery('');
    setSearchResults(null);
  };

  // --- 3. Expandir / Colapsar Nodo (Lazy Loading) ---
  const toggleNodeExpansion = async (node) => {
    const nodeKey = node.node_key || node.id;
    const isExpanded = expandedNodes.has(nodeKey);

    if (isExpanded) {
      setExpandedNodes((prev) => {
        const next = new Set(prev);
        next.delete(nodeKey);
        return next;
      });
      return;
    }

    // Si aún no se han cargado los hijos de este nodo en caché
    if (!childrenCache[nodeKey] && node.has_children) {
      setLoadingNodes((prev) => new Set(prev).add(nodeKey));
      try {
        const res = await api.get('/admin/metadata/tree', {
          params: {
            parent_type: node.type,
            parent_id: node.real_id || node.id,
          },
        });

        if (res.data?.status === 'success') {
          const children = res.data.children || [];
          setChildrenCache((prev) => ({
            ...prev,
            [nodeKey]: children,
          }));
        }
      } catch (err) {
        console.error('Error cargando hijos de nodo:', err);
        showToast('error', `No fue posible cargar el contenido de ${node.name}.`);
      } finally {
        setLoadingNodes((prev) => {
          const next = new Set(prev);
          next.delete(nodeKey);
          return next;
        });
      }
    }

    setExpandedNodes((prev) => new Set(prev).add(nodeKey));
  };

  const handleExpandAll = () => {
    const allRootKeys = new Set(treeNodes.map((n) => n.node_key || n.id));
    setExpandedNodes(allRootKeys);
  };

  const handleCollapseAll = () => {
    setExpandedNodes(new Set());
  };

  // --- 4. Cargar Detalle de un Elemento en el Editor ---
  const loadItemDetails = useCallback(async (node) => {
    if (!node || node.type === 'category' || node.type === 'livetv_category') {
      return;
    }

    setSelectedNode(node);
    setLoadingItem(true);
    setHasChanges(false);

    try {
      const type = node.type === 'channel' ? 'channel' : (node.type === 'episode' ? 'episode' : (node.type === 'season' ? 'season' : 'title'));
      const id = node.real_id || node.id.replace(/^[a-z]+-/, '');

      const res = await api.get(`/admin/metadata/item/${type}/${id}`);
      if (res.data?.status === 'success') {
        const item = res.data.item;
        setItemData(item);
        setOriginalItemData(JSON.parse(JSON.stringify(item)));
      }
    } catch (err) {
      console.error('Error cargando metadatos de elemento:', err);
      showToast('error', `Error al cargar la información de ${node.name}.`, 'Fallo de Carga');
    } finally {
      setLoadingItem(false);
    }
  }, [showToast]);

  // Selección al hacer clic en un nodo
  const handleNodeClick = (node, e) => {
    if (node.has_children && (node.type === 'category' || node.type === 'livetv_category')) {
      toggleNodeExpansion(node);
    } else {
      loadItemDetails(node);
      if (node.has_children) {
        toggleNodeExpansion(node);
      }
    }
  };

  // Doble clic para forzar expansión/colapso o enfocar
  const handleNodeDoubleClick = (node) => {
    if (node.has_children) {
      toggleNodeExpansion(node);
    }
    loadItemDetails(node);
  };

  // --- 5. Manejo del Menú Contextual (Clic Derecho) ---
  const handleContextMenu = (e, node) => {
    e.preventDefault();
    e.stopPropagation();

    // Posicionamiento inteligente para evitar salirse de la ventana
    const menuWidth = 240;
    const menuHeight = 200;
    const x = e.clientX + menuWidth > window.innerWidth ? window.innerWidth - menuWidth - 10 : e.clientX;
    const y = e.clientY + menuHeight > window.innerHeight ? window.innerHeight - menuHeight - 10 : e.clientY;

    setContextMenu({ x, y, node });
  };

  // --- 6. Acciones del Menú Contextual ---
  const handleContextRefresh = async (node) => {
    setContextMenu(null);
    if (!node || node.type === 'category') return;

    if (node.is_locked) {
      showToast('error', 'Este elemento está bloqueado contra sobrescritura. Desactiva el candado para actualizar.', 'Protección Activa');
      return;
    }

    showToast('info', `Consultando TMDb/IMDb para "${node.name}"...`, 'Actualizando');
    try {
      const type = node.type === 'episode' ? 'episode' : (node.type === 'channel' ? 'channel' : 'title');
      const id = node.real_id || node.id.replace(/^[a-z]+-/, '');

      const res = await api.post(`/admin/metadata/item/${type}/${id}/refresh`);
      if (res.data?.status === 'success') {
        showToast('success', res.data.message || 'Metadatos actualizados desde TMDb.', 'Sincronizado');
        if (selectedNode?.id === node.id) {
          loadItemDetails(node);
        }
      } else {
        showToast('info', res.data?.message || 'Sin cambios desde fuentes externas.');
      }
    } catch (err) {
      const msg = err.response?.data?.message || 'Error al conectar con la API de metadatos.';
      showToast('error', msg, 'Fallo al Actualizar');
    }
  };

  const handleContextToggleLock = async (node) => {
    setContextMenu(null);
    if (!node || node.type === 'category') return;

    try {
      const type = node.type === 'episode' ? 'episode' : (node.type === 'channel' ? 'channel' : 'title');
      const id = node.real_id || node.id.replace(/^[a-z]+-/, '');

      const res = await api.post(`/admin/metadata/item/${type}/${id}/toggle-lock`);
      if (res.data?.status === 'success') {
        const newLock = res.data.is_locked;
        node.is_locked = newLock;

        // Actualizar en el estado si coincide con el seleccionado
        if (itemData && (itemData.id === node.real_id || `title-${itemData.id}` === node.id)) {
          setItemData((prev) => ({ ...prev, is_locked: newLock }));
        }

        showToast(newLock ? 'success' : 'info', res.data.message, newLock ? 'Elemento Protegido' : 'Elemento Desbloqueado');
      }
    } catch (err) {
      showToast('error', 'No fue posible cambiar el estado de bloqueo.');
    }
  };

  const handleCopyPhysicalPath = (path) => {
    setContextMenu(null);
    if (!path) return;
    navigator.clipboard?.writeText(path);
    showToast('info', `Ruta copiada al portapapeles: ${path}`, 'Ruta del Servidor');
  };

  const handleContextDeleteClick = (node) => {
    setContextMenu(null);
    setDeleteModal(node);
  };

  // --- 7. Confirmación de Eliminación ---
  const handleConfirmDelete = async () => {
    if (!deleteModal) return;
    setIsDeleting(true);

    try {
      const node = deleteModal;
      const type = node.type === 'channel' ? 'channel' : (node.type === 'episode' ? 'episode' : (node.type === 'season' ? 'season' : 'title'));
      const id = node.real_id || node.id.replace(/^[a-z]+-/, '');

      const res = await api.delete(`/admin/metadata/item/${type}/${id}`);
      if (res.data?.status === 'success') {
        showToast('success', res.data.message || `Elemento "${node.name}" eliminado correctamente.`, 'Eliminado');

        // Limpiar selección si era el elemento activo
        if (selectedNode?.id === node.id) {
          setSelectedNode(null);
          setItemData(null);
        }

        // Refrescar caché del árbol
        setChildrenCache({});
        loadRootTree();
      }
    } catch (err) {
      console.error('Error al eliminar elemento:', err);
      showToast('error', 'No fue posible eliminar el elemento.', 'Error de Eliminación');
    } finally {
      setIsDeleting(false);
      setDeleteModal(null);
    }
  };

  // --- 8. Manejo de Cambios en el Formulario del Editor ---
  const handleFieldChange = (field, value) => {
    setItemData((prev) => {
      if (!prev) return prev;
      return { ...prev, [field]: value };
    });
    setHasChanges(true);
  };

  // Alternar bloqueo de un campo específico (candado individual)
  const toggleFieldLock = (fieldName) => {
    if (!itemData) return;
    const currentLocked = itemData.locked_fields || [];
    const isLocked = currentLocked.includes(fieldName);
    const updated = isLocked
      ? currentLocked.filter((f) => f !== fieldName)
      : [...currentLocked, fieldName];

    setItemData((prev) => ({ ...prev, locked_fields: updated }));
    setHasChanges(true);
  };

  // --- 9. Guardar Cambios de Metadatos ---
  const handleSaveChanges = async () => {
    if (!itemData || !selectedNode) return;
    setSavingItem(true);

    try {
      const type = selectedNode.type === 'channel' ? 'channel' : (selectedNode.type === 'episode' ? 'episode' : (selectedNode.type === 'season' ? 'season' : 'title'));
      const id = itemData.id || selectedNode.real_id;

      const payload = {
        title: itemData.title || itemData.name,
        sort_title: itemData.sort_title,
        overview: itemData.overview,
        release_date: itemData.release_date,
        release_year: itemData.release_year,
        community_rating: itemData.community_rating,
        parental_rating: itemData.parental_rating,
        custom_rating: itemData.custom_rating,
        video_format_3d: itemData.video_format_3d,
        imdb_id: itemData.imdb_id,
        tmdb_id: itemData.tmdb_id,
        tvdb_id: itemData.tvdb_id,
        genres: itemData.genres || [],
        cast: itemData.cast || [],
        studios: itemData.studios || [],
        tags: itemData.tags || [],
        metadata_language: itemData.metadata_language || 'es-ES',
        country: itemData.country,
        is_locked: (bool) => itemData.is_locked,
        locked_fields: itemData.locked_fields || [],
        season_number: itemData.season_number,
        episode_number: itemData.episode_number,
      };

      const res = await api.put(`/admin/metadata/item/${type}/${id}`, payload);
      if (res.data?.status === 'success') {
        showToast('success', res.data.message || 'Cambios guardados con éxito en la base de datos.', 'Metadatos Guardados');
        const updated = res.data.item;
        setItemData(updated);
        setOriginalItemData(JSON.parse(JSON.stringify(updated)));
        setHasChanges(false);

        // Actualizar el nombre y bloqueo en el nodo actual del árbol si aplica
        if (selectedNode) {
          selectedNode.name = updated.title || updated.name;
          selectedNode.is_locked = updated.is_locked;
        }
      }
    } catch (err) {
      console.error('Error guardando metadatos:', err);
      const msg = err.response?.data?.message || 'Error de validación al guardar cambios.';
      showToast('error', msg, 'Fallo al Guardar');
    } finally {
      setSavingItem(false);
    }
  };

  // Descartar cambios
  const handleDiscardChanges = () => {
    if (originalItemData) {
      setItemData(JSON.parse(JSON.stringify(originalItemData)));
      setHasChanges(false);
      showToast('info', 'Los cambios no guardados han sido revertidos.');
    }
  };

  // --- 10. Taxonomías Dinámicas (Chips interactivos con Add y Remove) ---
  const handleRemoveGenre = (genre) => {
    setItemData((prev) => ({
      ...prev,
      genres: (prev.genres || []).filter((g) => g !== genre),
    }));
    setHasChanges(true);
  };

  const handleAddGenre = (newGenre) => {
    if (!newGenre || !newGenre.trim()) return;
    const clean = newGenre.trim();
    if (!itemData.genres?.includes(clean)) {
      setItemData((prev) => ({
        ...prev,
        genres: [...(prev.genres || []), clean],
      }));
      setHasChanges(true);
    }
    setGenreInputValue('');
    setAddingGenre(false);
  };

  const handleRemoveStudio = (studio) => {
    setItemData((prev) => ({
      ...prev,
      studios: (prev.studios || []).filter((s) => s !== studio),
    }));
    setHasChanges(true);
  };

  const handleAddStudio = (newStudio) => {
    if (!newStudio || !newStudio.trim()) return;
    const clean = newStudio.trim();
    if (!itemData.studios?.includes(clean)) {
      setItemData((prev) => ({
        ...prev,
        studios: [...(prev.studios || []), clean],
      }));
      setHasChanges(true);
    }
    setStudioInputValue('');
    setAddingStudio(false);
  };

  const handleRemoveTag = (tag) => {
    setItemData((prev) => ({
      ...prev,
      tags: (prev.tags || []).filter((t) => t !== tag),
    }));
    setHasChanges(true);
  };

  const handleAddTag = (newTag) => {
    if (!newTag || !newTag.trim()) return;
    const clean = newTag.trim();
    if (!itemData.tags?.includes(clean)) {
      setItemData((prev) => ({
        ...prev,
        tags: [...(prev.tags || []), clean],
      }));
      setHasChanges(true);
    }
    setTagInputValue('');
    setAddingTag(false);
  };

  const handleRemovePerson = (index) => {
    setItemData((prev) => ({
      ...prev,
      cast: (prev.cast || []).filter((_, i) => i !== index),
    }));
    setHasChanges(true);
  };

  const handleAddPersonSubmit = (e) => {
    if (e) e.preventDefault();
    if (!newPersonName.trim()) return;
    setItemData((prev) => ({
      ...prev,
      cast: [...(prev.cast || []), { name: newPersonName.trim(), role: newPersonRole }],
    }));
    setHasChanges(true);
    setNewPersonName('');
    setAddPersonModal(false);
  };

  // --- 11. Modal de Búsqueda Externa (TMDb / OMDb) ---
  const handleOpenExternalSearch = () => {
    const initialQuery = itemData?.title || itemData?.name || selectedNode?.name || '';
    setExternalSearchQuery(initialQuery);
    setExternalSearchType(selectedNode?.type === 'series' ? 'series' : 'movie');
    setExternalResults([]);
    setExternalSearchModal(true);

    if (initialQuery) {
      triggerExternalSearch(initialQuery);
    }
  };

  const triggerExternalSearch = async (query) => {
    if (!query || !query.trim()) return;
    setSearchingExternal(true);

    try {
      const res = await api.get('/admin/metadata/search-external', {
        params: {
          query: query.trim(),
          type: externalSearchType,
          year: itemData?.release_year || '',
        },
      });

      if (res.data?.status === 'success') {
        setExternalResults(res.data.results || []);
      }
    } catch (err) {
      console.error('Error buscando en TMDb:', err);
      showToast('error', 'Error al consultar la base de datos externa de TMDb.');
    } finally {
      setSearchingExternal(false);
    }
  };

  const handleApplyExternalMetadata = (match) => {
    if (!match) return;

    setItemData((prev) => {
      const next = { ...prev };
      if (match.title) next.title = match.title;
      if (match.original_title) next.sort_title = match.original_title;
      if (match.overview) next.overview = match.overview;
      if (match.year) next.release_year = match.year;
      if (match.release_date) next.release_date = match.release_date;
      if (match.vote_average) next.community_rating = match.vote_average;
      if (match.tmdb_id) next.tmdb_id = match.tmdb_id;
      if (match.imdb_id) next.imdb_id = match.imdb_id;
      if (match.poster_url) next.poster_url = match.poster_url;
      if (match.backdrop_url) next.backdrop_url = match.backdrop_url;
      if (match.genres && match.genres.length > 0) next.genres = match.genres;
      if (match.parental_rating) next.parental_rating = match.parental_rating;
      if (match.country) next.country = match.country;
      return next;
    });

    setHasChanges(true);
    setExternalSearchModal(false);
    showToast('success', `Metadatos de "${match.title}" aplicados al formulario. Pulsa "Guardar Cambios" para persistirlos.`, 'TMDb Autocompletado');
  };

  // --- Renderizado Recursivo de Nodos del Árbol ---
  const renderTreeNode = (node, depth = 0) => {
    const nodeKey = node.node_key || node.id;
    const isExpanded = expandedNodes.has(nodeKey);
    const isSelected = selectedNode && (selectedNode.id === node.id || selectedNode.real_id === node.real_id);
    const isLoading = loadingNodes.has(nodeKey);
    const children = childrenCache[nodeKey] || [];

    // Selección de Icono por tipo
    let IconComponent = Film;
    if (node.type === 'category') {
      IconComponent = isExpanded ? FolderOpen : Folder;
    } else if (node.type === 'livetv_category') {
      IconComponent = Radio;
    } else if (node.type === 'series') {
      IconComponent = Tv;
    } else if (node.type === 'season') {
      IconComponent = Layers;
    } else if (node.type === 'channel') {
      IconComponent = Radio;
    }

    return (
      <div key={nodeKey} className="select-none text-xs">
        {/* Fila del Nodo */}
        <div
          onClick={(e) => handleNodeClick(node, e)}
          onDoubleClick={() => handleNodeDoubleClick(node)}
          onContextMenu={(e) => handleContextMenu(e, node)}
          style={{ paddingLeft: `${Math.max(8, depth * 16)}px` }}
          className={`flex items-center gap-1.5 py-1.5 pr-2 rounded-md cursor-pointer transition-all duration-150 group ${
            isSelected
              ? 'bg-[#E50914]/15 text-white font-semibold border-l-2 border-[#E50914]'
              : 'text-zinc-300 hover:text-white hover:bg-zinc-800/60'
          }`}
          title={`${node.name} (Clic derecho para opciones)`}
        >
          {/* Flecha expandir/colapsar si tiene hijos */}
          {node.has_children ? (
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                toggleNodeExpansion(node);
              }}
              className="p-0.5 text-zinc-400 hover:text-white transition-colors cursor-pointer rounded"
            >
              {isLoading ? (
                <Loader2 className="w-3.5 h-3.5 animate-spin text-[#E50914]" />
              ) : isExpanded ? (
                <ChevronDown className="w-3.5 h-3.5" />
              ) : (
                <ChevronRight className="w-3.5 h-3.5" />
              )}
            </button>
          ) : (
            <span className="w-3.5 h-3.5 inline-block" />
          )}

          {/* Icono del nodo */}
          <IconComponent
            className={`w-3.5 h-3.5 shrink-0 transition-colors ${
              isSelected ? 'text-[#E50914]' : 'text-zinc-400 group-hover:text-zinc-200'
            }`}
          />

          {/* Nombre del nodo */}
          <span className="truncate flex-1">{node.name}</span>

          {/* Badges auxiliares (Año, contador de ítems, o episodio) */}
          {node.item_count !== undefined && node.item_count > 0 && (
            <span className="text-[10px] px-1.5 py-0.2 rounded bg-zinc-800 text-zinc-400 group-hover:text-zinc-300 font-mono">
              {node.item_count}
            </span>
          )}
          {node.year && (
            <span className="text-[10px] text-zinc-400 font-mono">
              {node.year}
            </span>
          )}

          {/* Indicador de Candado / Bloqueo */}
          {node.is_locked && (
            <Lock className="w-3 h-3 text-[#E50914] shrink-0" title="Bloqueado contra sobrescritura" />
          )}
        </div>

        {/* Hijos expandidos */}
        {isExpanded && node.has_children && (
          <div className="space-y-0.5 mt-0.5">
            {isLoading && children.length === 0 ? (
              <div
                style={{ paddingLeft: `${(depth + 1) * 16}px` }}
                className="py-1 text-[11px] text-zinc-400 flex items-center gap-2 italic"
              >
                <Loader2 className="w-3 h-3 animate-spin text-[#E50914]" />
                <span>Cargando elementos...</span>
              </div>
            ) : children.length === 0 ? (
              <div
                style={{ paddingLeft: `${(depth + 1) * 16}px` }}
                className="py-1 text-[11px] text-zinc-400 italic"
              >
                (Sin elementos en esta biblioteca)
              </div>
            ) : (
              children.map((child) => renderTreeNode(child, depth + 1))
            )}
          </div>
        )}
      </div>
    );
  };

  return (
    <div className="flex flex-col h-full bg-[#141414] text-zinc-100 select-none overflow-hidden relative font-sans">
      {/* 1. Header del Módulo con Guía y Acentos */}
      <div className="shrink-0 px-6 py-4 border-b border-zinc-800/80 bg-[#181818]/80 flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-lg bg-[#E50914]/15 border border-[#E50914]/30 flex items-center justify-center text-[#E50914]">
            <Tag className="w-5 h-5" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-base font-bold text-white tracking-wide">
                Gestor Avanzado de Metadatos
              </h1>
              <span className="text-[10px] font-mono uppercase bg-zinc-800 px-2 py-0.5 rounded text-zinc-400 border border-zinc-700">
                DirectPlay Core
              </span>
            </div>
            <p className="text-xs text-zinc-400">
              Explorador jerárquico de bibliotecas y edición detallada de metadatos On-Net con protección anti-sobrescritura.
            </p>
          </div>
        </div>

        {/* Botones de acción globales */}
        <div className="flex items-center gap-2 text-xs">
          {itemData && hasChanges && (
            <span className="flex items-center gap-1.5 px-2.5 py-1 rounded bg-amber-500/10 border border-amber-500/30 text-amber-400 font-medium">
              <span className="w-2 h-2 rounded-full bg-amber-400 animate-pulse" />
              Cambios sin guardar
            </span>
          )}
        </div>
      </div>

      {/* 2. Layout Principal de Doble Panel Interactivo */}
      <div className="flex-1 flex flex-col md:flex-row min-h-0 overflow-hidden">
        {/* PANEL IZQUIERDO: Media Tree Explorer */}
        <div className="w-full md:w-80 lg:w-96 shrink-0 border-r border-zinc-800 bg-[#161616] flex flex-col h-full overflow-hidden">
          {/* Barra de herramientas superior del árbol */}
          <div className="p-3 border-b border-zinc-800 space-y-2 bg-[#181818]">
            {/* Buscador reactivo */}
            <form onSubmit={handleSearchSubmit} className="relative flex items-center">
              <Search className="w-3.5 h-3.5 absolute left-2.5 text-zinc-400" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Buscar película, serie, canal..."
                className="w-full bg-[#121212] border border-zinc-700 text-xs rounded-md pl-8 pr-7 py-1.5 text-zinc-200 placeholder-zinc-500 focus:outline-none focus:border-[#E50914] transition-colors"
              />
              {searchQuery && (
                <button
                  type="button"
                  onClick={handleClearSearch}
                  className="absolute right-2 text-zinc-400 hover:text-white"
                  title="Limpiar búsqueda"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              )}
            </form>

            {/* Acciones de árbol: Expandir, Colapsar, Recargar */}
            <div className="flex items-center justify-between text-[11px] text-zinc-400">
              <div className="flex items-center gap-1">
                <button
                  type="button"
                  onClick={handleExpandAll}
                  className="px-2 py-1 rounded hover:bg-zinc-800 hover:text-zinc-200 transition-colors cursor-pointer"
                  title="Expandir todas las bibliotecas"
                >
                  Expandir todo
                </button>
                <span>•</span>
                <button
                  type="button"
                  onClick={handleCollapseAll}
                  className="px-2 py-1 rounded hover:bg-zinc-800 hover:text-zinc-200 transition-colors cursor-pointer"
                  title="Colapsar todo"
                >
                  Colapsar todo
                </button>
              </div>

              <button
                type="button"
                onClick={() => {
                  setChildrenCache({});
                  loadRootTree();
                }}
                disabled={treeLoading}
                className="p-1 rounded hover:bg-zinc-800 hover:text-white transition-colors cursor-pointer"
                title="Recargar árbol de medios"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${treeLoading ? 'animate-spin text-[#E50914]' : ''}`} />
              </button>
            </div>
          </div>

          {/* Contenedor del Árbol con Scroll Suave */}
          <div className="flex-1 overflow-y-auto p-2 space-y-0.5">
            {treeLoading ? (
              <div className="py-12 flex flex-col items-center justify-center space-y-3 text-zinc-500 text-xs">
                <Loader2 className="w-6 h-6 animate-spin text-[#E50914]" />
                <span>Explorando almacenamiento de medios...</span>
              </div>
            ) : searchResults !== null ? (
              /* Vista de Resultados de Búsqueda */
              <div className="space-y-1">
                <div className="px-2 py-1 flex items-center justify-between text-[11px] text-zinc-400 border-b border-zinc-800">
                  <span>Coincidencias encontradas ({searchResults.length})</span>
                  <button
                    onClick={handleClearSearch}
                    className="text-[#E50914] hover:underline cursor-pointer"
                  >
                    Cerrar búsqueda
                  </button>
                </div>
                {searchResults.length === 0 ? (
                  <p className="py-6 text-center text-xs text-zinc-500">
                    No se encontraron medios para "{searchQuery}".
                  </p>
                ) : (
                  searchResults.map((item) => (
                    <div
                      key={item.id}
                      onClick={() => loadItemDetails(item)}
                      onContextMenu={(e) => handleContextMenu(e, item)}
                      className={`flex items-center gap-2 p-2 rounded-md cursor-pointer text-xs transition-colors ${
                        selectedNode?.id === item.id
                          ? 'bg-[#E50914]/15 text-white font-medium border-l-2 border-[#E50914]'
                          : 'text-zinc-300 hover:bg-zinc-800/60 hover:text-white'
                      }`}
                    >
                      {item.type === 'channel' ? (
                        <Radio className="w-3.5 h-3.5 text-zinc-400 shrink-0" />
                      ) : item.type === 'series' ? (
                        <Tv className="w-3.5 h-3.5 text-zinc-400 shrink-0" />
                      ) : (
                        <Film className="w-3.5 h-3.5 text-zinc-400 shrink-0" />
                      )}
                      <div className="flex-1 min-w-0">
                        <p className="truncate font-medium">{item.name}</p>
                        <p className="text-[10px] text-zinc-400 truncate">
                          {item.category_name} {item.year ? `• ${item.year}` : ''}
                        </p>
                      </div>
                      {item.is_locked && <Lock className="w-3 h-3 text-[#E50914]" />}
                    </div>
                  ))
                )}
              </div>
            ) : (
              /* Árbol Jerárquico Regular */
              treeNodes.map((catNode) => renderTreeNode(catNode, 0))
            )}
          </div>

          {/* Pie del Árbol con Estado */}
          <div className="p-2.5 border-t border-zinc-800 text-[11px] text-zinc-400 flex items-center justify-between bg-[#141414]">
            <span className="flex items-center gap-1.5">
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
              Almacenamiento Local On-Net
            </span>
            <span className="font-mono text-[10px] text-zinc-400">/var/media</span>
          </div>
        </div>

        {/* PANEL DERECHO: Editor de Metadatos CRUD Completo */}
        <div className="flex-1 bg-[#141414] flex flex-col h-full overflow-hidden">
          {loadingItem ? (
            <div className="flex-1 flex flex-col items-center justify-center space-y-3 text-xs text-zinc-400">
              <Loader2 className="w-8 h-8 animate-spin text-[#E50914]" />
              <span>Cargando metadatos del elemento seleccionado...</span>
            </div>
          ) : !itemData ? (
            /* Estado Vacío: Cuando ningún ítem ha sido seleccionado */
            <div className="flex-1 flex flex-col items-center justify-center text-center p-8 space-y-4 text-zinc-400">
              <div className="w-16 h-16 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center text-zinc-600 shadow-inner">
                <Tag className="w-8 h-8" />
              </div>
              <div className="space-y-1.5 max-w-md">
                <h3 className="text-base font-bold text-zinc-200">
                  Selecciona un elemento para editar sus metadatos
                </h3>
                <p className="text-xs text-zinc-400 leading-relaxed">
                  Haz clic en cualquier película, episodio, temporada o canal del árbol izquierdo para visualizar y modificar su título, sinopsis, póster, géneros, calificaciones y candado de protección.
                </p>
              </div>
              <div className="text-[11px] text-zinc-400 flex items-center gap-4 pt-2">
                <span className="flex items-center gap-1.5">
                  <span className="px-1.5 py-0.5 rounded bg-zinc-800 border border-zinc-700 font-mono text-[10px]">Doble clic</span> Abrir carpeta
                </span>
                <span className="flex items-center gap-1.5">
                  <span className="px-1.5 py-0.5 rounded bg-zinc-800 border border-zinc-700 font-mono text-[10px]">Clic derecho</span> Menú contextual
                </span>
              </div>
            </div>
          ) : (
            /* Formulario Completo de Edición */
            <div className="flex-1 flex flex-col h-full overflow-hidden">
              {/* Encabezado del Editor */}
              <div className="shrink-0 p-5 border-b border-zinc-800 bg-[#161616] space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="flex items-center gap-2.5 min-w-0">
                    <span className="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-[#E50914]/15 text-[#E50914] border border-[#E50914]/30 shrink-0">
                      {itemData.type === 'channel' ? 'Canal TV' : (itemData.type === 'episode' ? 'Episodio' : (itemData.type === 'season' ? 'Temporada' : (itemData.type === 'series' ? 'Serie' : 'Película')))}
                    </span>
                    <h2 className="text-lg font-bold text-white truncate">
                      {itemData.title || itemData.name}
                    </h2>
                    {itemData.release_year && (
                      <span className="text-xs text-zinc-400 font-mono">
                        ({itemData.release_year})
                      </span>
                    )}
                  </div>

                  {/* Switch Principal: Bloquear contra sobrescritura */}
                  <button
                    type="button"
                    onClick={() => handleFieldChange('is_locked', !itemData.is_locked)}
                    className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all cursor-pointer border ${
                      itemData.is_locked
                        ? 'bg-red-500/15 text-red-400 border-red-500/40 shadow-sm'
                        : 'bg-zinc-800 text-zinc-300 border-zinc-700 hover:bg-zinc-700'
                    }`}
                    title="Alternar candado contra sobrescritura del escáner"
                  >
                    {itemData.is_locked ? (
                      <>
                        <Lock className="w-3.5 h-3.5 text-[#E50914]" />
                        <span>Protegido contra escáner</span>
                      </>
                    ) : (
                      <>
                        <Unlock className="w-3.5 h-3.5 text-zinc-400" />
                        <span>Desbloqueado</span>
                      </>
                    )}
                  </button>
                </div>

                {/* Ruta física en el servidor */}
                <div className="flex items-center gap-2 bg-[#121212] border border-zinc-800 rounded-md px-3 py-1.5 text-xs text-zinc-400">
                  <span className="text-zinc-400 font-semibold uppercase text-[10px] tracking-wider shrink-0">
                    Ruta física:
                  </span>
                  <span className="font-mono text-zinc-300 truncate flex-1 text-[11px]">
                    {itemData.physical_path || '/media/local'}
                  </span>
                  <button
                    type="button"
                    onClick={() => handleCopyPhysicalPath(itemData.physical_path)}
                    className="p-1 text-zinc-400 hover:text-white transition-colors cursor-pointer"
                    title="Copiar ruta"
                  >
                    <Copy className="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>

              {/* Formulario con Scroll Vertical */}
              <div className="flex-1 overflow-y-auto p-6 space-y-6">
                {/* SECCIÓN 1: Información General */}
                <div className="bg-[#181818] border border-zinc-800 rounded-xl p-5 space-y-4">
                  <div className="flex items-center justify-between border-b border-zinc-800 pb-2.5">
                    <h3 className="text-xs font-bold text-white uppercase tracking-wider flex items-center gap-2">
                      <Sliders className="w-4 h-4 text-[#E50914]" />
                      Información General
                    </h3>
                    <span className="text-[11px] text-zinc-400">
                      Metadatos primarios de visualización
                    </span>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Title */}
                    <div className="space-y-1.5">
                      <div className="flex items-center justify-between">
                        <label className="text-xs font-medium text-zinc-300">
                          Título (Title)
                        </label>
                        <button
                          type="button"
                          onClick={() => toggleFieldLock('title')}
                          className="text-[11px] text-zinc-400 hover:text-white flex items-center gap-1 cursor-pointer"
                          title="Bloquear título"
                        >
                          {itemData.locked_fields?.includes('title') ? (
                            <Lock className="w-3 h-3 text-[#E50914]" />
                          ) : (
                            <Unlock className="w-3 h-3 text-zinc-400" />
                          )}
                        </button>
                      </div>
                      <input
                        type="text"
                        value={itemData.title || ''}
                        onChange={(e) => handleFieldChange('title', e.target.value)}
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors"
                      />
                    </div>

                    {/* Sort title */}
                    <div className="space-y-1.5">
                      <label className="text-xs font-medium text-zinc-300">
                        Título de ordenación (Sort title)
                      </label>
                      <input
                        type="text"
                        value={itemData.sort_title || ''}
                        onChange={(e) => handleFieldChange('sort_title', e.target.value)}
                        placeholder="Ej. Matrix, The"
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors"
                      />
                    </div>

                    {/* Date added */}
                    <div className="space-y-1.5">
                      <label className="text-xs font-medium text-zinc-300">
                        Fecha de adición (Date added)
                      </label>
                      <input
                        type="date"
                        value={itemData.date_added || ''}
                        onChange={(e) => handleFieldChange('date_added', e.target.value)}
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors"
                      />
                    </div>

                    {/* Community rating */}
                    <div className="space-y-1.5">
                      <div className="flex items-center justify-between">
                        <label className="text-xs font-medium text-zinc-300">
                          Calificación comunitaria (Community rating 0-10)
                        </label>
                        <span className="text-xs font-bold text-amber-400 flex items-center gap-1">
                          <Star className="w-3 h-3 fill-amber-400 text-amber-400" />
                          {itemData.community_rating || 8.0}
                        </span>
                      </div>
                      <input
                        type="number"
                        step="0.1"
                        min="0"
                        max="10"
                        value={itemData.community_rating ?? 8.0}
                        onChange={(e) => handleFieldChange('community_rating', parseFloat(e.target.value) || 0)}
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors"
                      />
                    </div>

                    {/* Season / Episode Contextual */}
                    {(itemData.type === 'episode' || itemData.season_number !== null) && (
                      <div className="space-y-1.5">
                        <label className="text-xs font-medium text-zinc-300">
                          Número de Temporada (Season number)
                        </label>
                        <input
                          type="number"
                          value={itemData.season_number ?? 1}
                          onChange={(e) => handleFieldChange('season_number', parseInt(e.target.value) || 1)}
                          className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors"
                        />
                      </div>
                    )}

                    {itemData.type === 'episode' && (
                      <div className="space-y-1.5">
                        <label className="text-xs font-medium text-zinc-300">
                          Número de Episodio (Episode number)
                        </label>
                        <input
                          type="number"
                          value={itemData.episode_number ?? 1}
                          onChange={(e) => handleFieldChange('episode_number', parseInt(e.target.value) || 1)}
                          className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors"
                        />
                      </div>
                    )}

                    {/* Release date */}
                    <div className="space-y-1.5">
                      <label className="text-xs font-medium text-zinc-300">
                        Fecha de estreno (Release date)
                      </label>
                      <input
                        type="date"
                        value={itemData.release_date || ''}
                        onChange={(e) => {
                          handleFieldChange('release_date', e.target.value);
                          if (e.target.value) {
                            handleFieldChange('release_year', parseInt(e.target.value.substring(0, 4)) || itemData.release_year);
                          }
                        }}
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors"
                      />
                    </div>

                    {/* Release year */}
                    <div className="space-y-1.5">
                      <label className="text-xs font-medium text-zinc-300">
                        Año de producción (Year)
                      </label>
                      <input
                        type="number"
                        value={itemData.release_year || ''}
                        onChange={(e) => handleFieldChange('release_year', parseInt(e.target.value) || null)}
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors"
                      />
                    </div>

                    {/* Parental rating */}
                    <div className="space-y-1.5">
                      <label className="text-xs font-medium text-zinc-300">
                        Clasificación por edades (Parental rating)
                      </label>
                      <select
                        value={itemData.parental_rating || 'PG-13'}
                        onChange={(e) => handleFieldChange('parental_rating', e.target.value)}
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors"
                      >
                        {PARENTAL_RATINGS.map((rate) => (
                          <option key={rate} value={rate}>
                            {rate}
                          </option>
                        ))}
                      </select>
                    </div>

                    {/* Custom rating */}
                    <div className="space-y-1.5">
                      <label className="text-xs font-medium text-zinc-300">
                        Clasificación personalizada (Custom rating)
                      </label>
                      <input
                        type="text"
                        value={itemData.custom_rating || ''}
                        onChange={(e) => handleFieldChange('custom_rating', e.target.value)}
                        placeholder="Ej. B15 / Mayores de 12"
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors"
                      />
                    </div>

                    {/* 3D format */}
                    <div className="space-y-1.5 md:col-span-2">
                      <label className="text-xs font-medium text-zinc-300">
                        Formato de Video 3D (3D format)
                      </label>
                      <select
                        value={itemData.video_format_3d || 'None'}
                        onChange={(e) => handleFieldChange('video_format_3d', e.target.value)}
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors"
                      >
                        {VIDEO_FORMATS_3D.map((fmt) => (
                          <option key={fmt} value={fmt}>
                            {fmt}
                          </option>
                        ))}
                      </select>
                    </div>

                    {/* Overview / Sinopsis */}
                    <div className="space-y-1.5 md:col-span-2">
                      <div className="flex items-center justify-between">
                        <label className="text-xs font-medium text-zinc-300">
                          Sinopsis / Descripción (Overview)
                        </label>
                        <button
                          type="button"
                          onClick={() => toggleFieldLock('overview')}
                          className="text-[11px] text-zinc-400 hover:text-white flex items-center gap-1 cursor-pointer"
                          title="Bloquear sinopsis"
                        >
                          {itemData.locked_fields?.includes('overview') ? (
                            <Lock className="w-3 h-3 text-[#E50914]" />
                          ) : (
                            <Unlock className="w-3 h-3 text-zinc-400" />
                          )}
                        </button>
                      </div>
                      <textarea
                        rows={4}
                        value={itemData.overview || ''}
                        onChange={(e) => handleFieldChange('overview', e.target.value)}
                        placeholder="Sinopsis oficial del contenido multimedia..."
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg p-3 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors leading-relaxed"
                      />
                    </div>
                  </div>
                </div>

                {/* SECCIÓN 2: External IDs (TMDb / IMDb / TheTVDB) */}
                <div className="bg-[#181818] border border-zinc-800 rounded-xl p-5 space-y-4">
                  <div className="flex items-center justify-between border-b border-zinc-800 pb-2.5">
                    <h3 className="text-xs font-bold text-white uppercase tracking-wider flex items-center gap-2">
                      <Globe className="w-4 h-4 text-[#E50914]" />
                      Identificadores Externos (External IDs)
                    </h3>
                    <span className="text-[11px] text-zinc-400">
                      Vínculos con bases de datos mundiales
                    </span>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {/* IMDb Id */}
                    <div className="space-y-1.5">
                      <div className="flex items-center justify-between">
                        <label className="text-xs font-medium text-zinc-300">IMDb Id</label>
                        {itemData.imdb_id && (
                          <a
                            href={`https://www.imdb.com/title/${itemData.imdb_id}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-[11px] text-amber-400 hover:underline flex items-center gap-0.5"
                          >
                            Ver en IMDb <ExternalLink className="w-3 h-3" />
                          </a>
                        )}
                      </div>
                      <input
                        type="text"
                        value={itemData.imdb_id || ''}
                        onChange={(e) => handleFieldChange('imdb_id', e.target.value)}
                        placeholder="Ej. tt0133093"
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors font-mono"
                      />
                    </div>

                    {/* TMDb Id */}
                    <div className="space-y-1.5">
                      <div className="flex items-center justify-between">
                        <label className="text-xs font-medium text-zinc-300">TMDb Id</label>
                        {itemData.tmdb_id && (
                          <a
                            href={`https://www.themoviedb.org/movie/${itemData.tmdb_id}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-[11px] text-sky-400 hover:underline flex items-center gap-0.5"
                          >
                            Ver en TMDb <ExternalLink className="w-3 h-3" />
                          </a>
                        )}
                      </div>
                      <input
                        type="text"
                        value={itemData.tmdb_id || ''}
                        onChange={(e) => handleFieldChange('tmdb_id', e.target.value)}
                        placeholder="Ej. 603"
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors font-mono"
                      />
                    </div>

                    {/* TheTVDB Id */}
                    <div className="space-y-1.5">
                      <label className="text-xs font-medium text-zinc-300">TheTVDB Id</label>
                      <input
                        type="text"
                        value={itemData.tvdb_id || ''}
                        onChange={(e) => handleFieldChange('tvdb_id', e.target.value)}
                        placeholder="Ej. 75710"
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors font-mono"
                      />
                    </div>
                  </div>
                </div>

                {/* SECCIÓN 3: Taxonomías Dinámicas (Chips interactivos) */}
                <div className="bg-[#181818] border border-zinc-800 rounded-xl p-5 space-y-5">
                  <div className="flex items-center justify-between border-b border-zinc-800 pb-2.5">
                    <h3 className="text-xs font-bold text-white uppercase tracking-wider flex items-center gap-2">
                      <Layers className="w-4 h-4 text-[#E50914]" />
                      Taxonomías y Clasificaciones Dinámicas
                    </h3>
                    <span className="text-[11px] text-zinc-400">
                      Chips interactivos editables
                    </span>
                  </div>

                  {/* 3.1 GÉNEROS (Genres) */}
                  <div className="space-y-2">
                    <div className="flex items-center justify-between">
                      <label className="text-xs font-medium text-zinc-300">
                        Géneros (Genres)
                      </label>
                      <span className="text-[11px] text-zinc-400">
                        {(itemData.genres || []).length} seleccionados
                      </span>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                      {(itemData.genres || []).map((genre) => (
                        <span
                          key={genre}
                          className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-[#E50914]/15 text-[#E50914] border border-[#E50914]/30"
                        >
                          {genre}
                          <button
                            type="button"
                            onClick={() => handleRemoveGenre(genre)}
                            className="hover:text-white transition-colors"
                            title={`Quitar género ${genre}`}
                          >
                            <X className="w-3 h-3" />
                          </button>
                        </span>
                      ))}

                      {/* Botón "+ Add" para Género */}
                      {addingGenre ? (
                        <div className="flex items-center gap-1 bg-[#121212] border border-zinc-700 rounded-full px-2 py-0.5">
                          <input
                            type="text"
                            value={genreInputValue}
                            onChange={(e) => setGenreInputValue(e.target.value)}
                            onKeyDown={(e) => {
                              if (e.key === 'Enter') {
                                e.preventDefault();
                                handleAddGenre(genreInputValue);
                              } else if (e.key === 'Escape') {
                                setAddingGenre(false);
                              }
                            }}
                            placeholder="Nombre de género..."
                            autoFocus
                            className="bg-transparent text-xs text-white focus:outline-none w-32 px-1"
                          />
                          <button
                            type="button"
                            onClick={() => handleAddGenre(genreInputValue)}
                            className="text-xs text-emerald-400 hover:text-emerald-300"
                          >
                            Ok
                          </button>
                          <button
                            type="button"
                            onClick={() => setAddingGenre(false)}
                            className="text-xs text-zinc-400 hover:text-zinc-200"
                          >
                            <X className="w-3 h-3" />
                          </button>
                        </div>
                      ) : (
                        <div className="flex items-center gap-1">
                          <button
                            type="button"
                            onClick={() => setAddingGenre(true)}
                            className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white border border-zinc-700 transition-colors cursor-pointer"
                          >
                            <Plus className="w-3 h-3" /> Agregar Género
                          </button>

                          {/* Selector rápido de sugerencias */}
                          <select
                            onChange={(e) => {
                              if (e.target.value) {
                                handleAddGenre(e.target.value);
                                e.target.value = '';
                              }
                            }}
                            defaultValue=""
                            className="bg-zinc-800 border border-zinc-700 text-[11px] text-zinc-400 rounded-full px-2.5 py-1 focus:outline-none hover:text-zinc-200 cursor-pointer"
                          >
                            <option value="" disabled>
                              + Populares...
                            </option>
                            {COMMON_GENRES.filter((g) => !(itemData.genres || []).includes(g)).map((g) => (
                              <option key={g} value={g}>
                                {g}
                              </option>
                            ))}
                          </select>
                        </div>
                      )}
                    </div>
                  </div>

                  {/* 3.2 CAST / PERSONAS (People / Cast) */}
                  <div className="space-y-2 pt-2 border-t border-zinc-800">
                    <div className="flex items-center justify-between">
                      <label className="text-xs font-medium text-zinc-300 flex items-center gap-1.5">
                        <Users className="w-3.5 h-3.5 text-zinc-400" />
                        Elenco y Equipo Técnico (People / Cast)
                      </label>
                      <button
                        type="button"
                        onClick={() => setAddPersonModal(true)}
                        className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded text-xs font-medium bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white border border-zinc-700 transition-colors cursor-pointer"
                      >
                        <Plus className="w-3 h-3" /> Agregar Persona
                      </button>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                      {(itemData.cast || []).map((person, idx) => (
                        <div
                          key={idx}
                          className="flex items-center justify-between p-2 rounded-lg bg-[#121212] border border-zinc-800 text-xs"
                        >
                          <div className="min-w-0 pr-2">
                            <p className="font-semibold text-zinc-200 truncate">{person.name}</p>
                            <span className="text-[10px] text-zinc-400 uppercase tracking-wider font-mono">
                              {person.role || 'Actor'}
                            </span>
                          </div>
                          <button
                            type="button"
                            onClick={() => handleRemovePerson(idx)}
                            className="text-zinc-500 hover:text-red-400 transition-colors"
                            title="Eliminar del elenco"
                          >
                            <X className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      ))}
                      {(itemData.cast || []).length === 0 && (
                        <p className="text-xs text-zinc-500 italic col-span-3 py-1">
                          No se han especificado actores o directores para este título.
                        </p>
                      )}
                    </div>
                  </div>

                  {/* 3.3 ESTUDIOS (Studios) */}
                  <div className="space-y-2 pt-2 border-t border-zinc-800">
                    <div className="flex items-center justify-between">
                      <label className="text-xs font-medium text-zinc-300 flex items-center gap-1.5">
                        <Building2 className="w-3.5 h-3.5 text-zinc-400" />
                        Estudios de Producción (Studios)
                      </label>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                      {(itemData.studios || []).map((studio) => (
                        <span
                          key={studio}
                          className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-zinc-800 text-zinc-200 border border-zinc-700"
                        >
                          {studio}
                          <button
                            type="button"
                            onClick={() => handleRemoveStudio(studio)}
                            className="text-zinc-400 hover:text-white"
                          >
                            <X className="w-3 h-3" />
                          </button>
                        </span>
                      ))}

                      {addingStudio ? (
                        <div className="flex items-center gap-1 bg-[#121212] border border-zinc-700 rounded-full px-2 py-0.5">
                          <input
                            type="text"
                            value={studioInputValue}
                            onChange={(e) => setStudioInputValue(e.target.value)}
                            onKeyDown={(e) => {
                              if (e.key === 'Enter') {
                                e.preventDefault();
                                handleAddStudio(studioInputValue);
                              } else if (e.key === 'Escape') {
                                setAddingStudio(false);
                              }
                            }}
                            placeholder="Nombre de estudio..."
                            autoFocus
                            className="bg-transparent text-xs text-white focus:outline-none w-36 px-1"
                          />
                          <button
                            type="button"
                            onClick={() => handleAddStudio(studioInputValue)}
                            className="text-xs text-emerald-400"
                          >
                            Ok
                          </button>
                          <button
                            type="button"
                            onClick={() => setAddingStudio(false)}
                            className="text-xs text-zinc-400"
                          >
                            <X className="w-3 h-3" />
                          </button>
                        </div>
                      ) : (
                        <div className="flex items-center gap-1">
                          <button
                            type="button"
                            onClick={() => setAddingStudio(true)}
                            className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white border border-zinc-700 transition-colors cursor-pointer"
                          >
                            <Plus className="w-3 h-3" /> Agregar Estudio
                          </button>
                          <select
                            onChange={(e) => {
                              if (e.target.value) {
                                handleAddStudio(e.target.value);
                                e.target.value = '';
                              }
                            }}
                            defaultValue=""
                            className="bg-zinc-800 border border-zinc-700 text-[11px] text-zinc-400 rounded-full px-2.5 py-1 focus:outline-none hover:text-zinc-200 cursor-pointer"
                          >
                            <option value="" disabled>
                              + Estudios conocidos...
                            </option>
                            {COMMON_STUDIOS.filter((s) => !(itemData.studios || []).includes(s)).map((s) => (
                              <option key={s} value={s}>
                                {s}
                              </option>
                            ))}
                          </select>
                        </div>
                      )}
                    </div>
                  </div>

                  {/* 3.4 ETIQUETAS (Tags) */}
                  <div className="space-y-2 pt-2 border-t border-zinc-800">
                    <label className="text-xs font-medium text-zinc-300">
                      Etiquetas Personalizadas (Tags)
                    </label>
                    <div className="flex flex-wrap items-center gap-2">
                      {(itemData.tags || []).map((tag) => (
                        <span
                          key={tag}
                          className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-zinc-800/80 text-zinc-300 border border-zinc-700"
                        >
                          #{tag}
                          <button
                            type="button"
                            onClick={() => handleRemoveTag(tag)}
                            className="text-zinc-400 hover:text-white"
                          >
                            <X className="w-3 h-3" />
                          </button>
                        </span>
                      ))}

                      {addingTag ? (
                        <div className="flex items-center gap-1 bg-[#121212] border border-zinc-700 rounded-full px-2 py-0.5">
                          <input
                            type="text"
                            value={tagInputValue}
                            onChange={(e) => setTagInputValue(e.target.value)}
                            onKeyDown={(e) => {
                              if (e.key === 'Enter') {
                                e.preventDefault();
                                handleAddTag(tagInputValue);
                              } else if (e.key === 'Escape') {
                                setAddingTag(false);
                              }
                            }}
                            placeholder="Etiqueta..."
                            autoFocus
                            className="bg-transparent text-xs text-white focus:outline-none w-28 px-1"
                          />
                          <button
                            type="button"
                            onClick={() => handleAddTag(tagInputValue)}
                            className="text-xs text-emerald-400"
                          >
                            Ok
                          </button>
                          <button
                            type="button"
                            onClick={() => setAddingTag(false)}
                            className="text-xs text-zinc-400"
                          >
                            <X className="w-3 h-3" />
                          </button>
                        </div>
                      ) : (
                        <button
                          type="button"
                          onClick={() => setAddingTag(true)}
                          className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white border border-zinc-700 transition-colors cursor-pointer"
                        >
                          <Plus className="w-3 h-3" /> Agregar Etiqueta
                        </button>
                      )}
                    </div>
                  </div>
                </div>

                {/* SECCIÓN 4: Metadata Settings & Anti-Overwrite Lock */}
                <div className="bg-[#181818] border border-zinc-800 rounded-xl p-5 space-y-4">
                  <div className="flex items-center justify-between border-b border-zinc-800 pb-2.5">
                    <h3 className="text-xs font-bold text-white uppercase tracking-wider flex items-center gap-2">
                      <Lock className="w-4 h-4 text-[#E50914]" />
                      Configuración del Escáner y Protección
                    </h3>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Preferred metadata download language */}
                    <div className="space-y-1.5">
                      <label className="text-xs font-medium text-zinc-300">
                        Idioma preferido para metadatos
                      </label>
                      <select
                        value={itemData.metadata_language || 'es-ES'}
                        onChange={(e) => handleFieldChange('metadata_language', e.target.value)}
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors"
                      >
                        {LANGUAGES.map((l) => (
                          <option key={l.code} value={l.code}>
                            {l.label}
                          </option>
                        ))}
                      </select>
                    </div>

                    {/* Country */}
                    <div className="space-y-1.5">
                      <label className="text-xs font-medium text-zinc-300">
                        País de origen (Country)
                      </label>
                      <input
                        type="text"
                        value={itemData.country || ''}
                        onChange={(e) => handleFieldChange('country', e.target.value)}
                        placeholder="Ej. Estados Unidos, Honduras, España"
                        className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors"
                      />
                    </div>
                  </div>

                  {/* Switch/Checkbox Grande de Bloqueo */}
                  <div className="pt-3 border-t border-zinc-800/80">
                    <label className="flex items-start gap-3 p-3.5 rounded-xl bg-[#121212] border border-zinc-800 cursor-pointer hover:border-zinc-700 transition-colors">
                      <input
                        type="checkbox"
                        checked={Boolean(itemData.is_locked)}
                        onChange={(e) => handleFieldChange('is_locked', e.target.checked)}
                        className="mt-1 w-4 h-4 text-[#E50914] rounded border-zinc-700 focus:ring-[#E50914] bg-zinc-900"
                      />
                      <div className="space-y-1">
                        <span className="text-xs font-bold text-white flex items-center gap-1.5">
                          Bloquear este elemento contra cambios futuros (Lock this item)
                          {itemData.is_locked && <Lock className="w-3.5 h-3.5 text-[#E50914]" />}
                        </span>
                        <p className="text-[11px] text-zinc-400 leading-relaxed">
                          Al activar esta casilla, el escáner automático del servidor de medios (<code className="text-[#E50914]">flixhn:sync-media</code>) respetará de forma permanente todos los campos que hayas editado manualmente y no los sobreescribirá con datos genéricos.
                        </p>
                      </div>
                    </label>
                  </div>
                </div>
              </div>

              {/* BARRA DE ACCIONES INFERIOR (Sticky Footer) */}
              <div className="shrink-0 p-4 border-t border-zinc-800 bg-[#161616] flex flex-wrap items-center justify-between gap-3 shadow-2xl">
                <div className="flex items-center gap-2">
                  {/* Botón Buscar en TMDb */}
                  <button
                    type="button"
                    onClick={handleOpenExternalSearch}
                    className="flex items-center gap-1.5 px-4 py-2 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-200 hover:text-white text-xs font-semibold transition-colors border border-zinc-700 shadow-sm cursor-pointer"
                  >
                    <Sparkles className="w-3.5 h-3.5 text-amber-400" />
                    <span>Buscar en TMDb/OMDb</span>
                  </button>

                  {/* Botón Descartar cambios */}
                  {hasChanges && (
                    <button
                      type="button"
                      onClick={handleDiscardChanges}
                      className="px-3 py-2 rounded-lg bg-zinc-900 hover:bg-zinc-800 text-zinc-400 hover:text-zinc-200 text-xs font-medium transition-colors cursor-pointer"
                    >
                      Descartar
                    </button>
                  )}
                </div>

                {/* Botón Guardar Cambios */}
                <button
                  type="button"
                  onClick={handleSaveChanges}
                  disabled={savingItem}
                  className="flex items-center gap-2 px-6 py-2 rounded-lg bg-[#E50914] hover:bg-[#F40612] text-white text-xs font-bold transition-all shadow-lg shadow-red-950/40 active:scale-95 disabled:opacity-60 cursor-pointer"
                >
                  {savingItem ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin" />
                      <span>Guardando metadatos...</span>
                    </>
                  ) : (
                    <>
                      <Save className="w-4 h-4" />
                      <span>Guardar Cambios</span>
                    </>
                  )}
                </button>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* 3. MENÚ CONTEXTUAL PERSONALIZADO (Clic Derecho) */}
      {contextMenu && (
        <div
          ref={contextMenuRef}
          style={{ top: `${contextMenu.y}px`, left: `${contextMenu.x}px` }}
          className="fixed z-50 w-60 bg-[#1e1e1e] border border-zinc-700 rounded-xl shadow-2xl p-1.5 space-y-1 text-xs text-zinc-200 animate-in fade-in zoom-in-95 duration-100"
        >
          <div className="px-3 py-1.5 border-b border-zinc-800 text-[11px] font-bold text-zinc-400 truncate">
            {contextMenu.node.name}
          </div>

          <button
            type="button"
            onClick={() => {
              loadItemDetails(contextMenu.node);
              setContextMenu(null);
            }}
            className="w-full flex items-center gap-2.5 px-3 py-2 rounded-md hover:bg-zinc-800 text-left transition-colors cursor-pointer"
          >
            <Edit3 className="w-3.5 h-3.5 text-zinc-400" />
            <span>Editar Metadatos</span>
          </button>

          <button
            type="button"
            onClick={() => handleContextRefresh(contextMenu.node)}
            className="w-full flex items-center gap-2.5 px-3 py-2 rounded-md hover:bg-zinc-800 text-left transition-colors cursor-pointer"
          >
            <Sparkles className="w-3.5 h-3.5 text-amber-400" />
            <span>Actualizar desde TMDb</span>
          </button>

          <button
            type="button"
            onClick={() => handleContextToggleLock(contextMenu.node)}
            className="w-full flex items-center gap-2.5 px-3 py-2 rounded-md hover:bg-zinc-800 text-left transition-colors cursor-pointer"
          >
            {contextMenu.node.is_locked ? (
              <>
                <Unlock className="w-3.5 h-3.5 text-zinc-400" />
                <span>Desbloquear metadatos</span>
              </>
            ) : (
              <>
                <Lock className="w-3.5 h-3.5 text-[#E50914]" />
                <span>Bloquear contra sobrescritura</span>
              </>
            )}
          </button>

          {contextMenu.node.physical_path && (
            <button
              type="button"
              onClick={() => handleCopyPhysicalPath(contextMenu.node.physical_path)}
              className="w-full flex items-center gap-2.5 px-3 py-2 rounded-md hover:bg-zinc-800 text-left transition-colors cursor-pointer"
            >
              <Copy className="w-3.5 h-3.5 text-zinc-400" />
              <span>Copiar ruta del servidor</span>
            </button>
          )}

          <div className="border-t border-zinc-800 my-1" />

          <button
            type="button"
            onClick={() => handleContextDeleteClick(contextMenu.node)}
            className="w-full flex items-center gap-2.5 px-3 py-2 rounded-md hover:bg-red-500/15 text-red-400 text-left transition-colors cursor-pointer font-medium"
          >
            <Trash2 className="w-3.5 h-3.5 text-red-400" />
            <span>Eliminar elemento</span>
          </button>
        </div>
      )}

      {/* 4. MODAL: BÚSQUEDA ASISTIDA EXTERNA (TMDb / OMDb) */}
      {externalSearchModal && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-[#181818] border border-zinc-700 rounded-2xl w-full max-w-2xl max-h-[85vh] flex flex-col shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            {/* Header del modal */}
            <div className="p-4 border-b border-zinc-800 flex items-center justify-between bg-[#1f1f1f]">
              <div className="flex items-center gap-2">
                <Sparkles className="w-5 h-5 text-amber-400" />
                <h3 className="text-sm font-bold text-white">
                  Buscar Metadatos en TMDb / OMDb
                </h3>
              </div>
              <button
                type="button"
                onClick={() => setExternalSearchModal(false)}
                className="text-zinc-400 hover:text-white p-1 rounded"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Formulario de búsqueda */}
            <div className="p-4 border-b border-zinc-800 bg-[#161616] space-y-3">
              <div className="flex gap-2">
                <div className="relative flex-1">
                  <Search className="w-4 h-4 absolute left-3 top-2.5 text-zinc-400" />
                  <input
                    type="text"
                    value={externalSearchQuery}
                    onChange={(e) => setExternalSearchQuery(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') triggerExternalSearch(externalSearchQuery);
                    }}
                    placeholder="Título de la película o serie a buscar..."
                    className="w-full bg-[#121212] border border-zinc-700 rounded-lg pl-9 pr-3 py-2 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-[#E50914]"
                  />
                </div>
                <button
                  type="button"
                  onClick={() => triggerExternalSearch(externalSearchQuery)}
                  disabled={searchingExternal}
                  className="px-5 py-2 bg-[#E50914] hover:bg-[#F40612] text-white rounded-lg text-xs font-bold transition-all disabled:opacity-60 cursor-pointer flex items-center gap-1.5"
                >
                  {searchingExternal ? (
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  ) : (
                    <Search className="w-3.5 h-3.5" />
                  )}
                  Buscar
                </button>
              </div>

              {/* Selector de tipo */}
              <div className="flex items-center gap-4 text-xs text-zinc-400">
                <span className="text-[11px] font-semibold text-zinc-500 uppercase">Filtrar por:</span>
                <label className="flex items-center gap-1.5 cursor-pointer">
                  <input
                    type="radio"
                    name="extType"
                    checked={externalSearchType === 'all'}
                    onChange={() => setExternalSearchType('all')}
                    className="text-[#E50914] focus:ring-[#E50914] bg-zinc-900"
                  />
                  <span>Todos</span>
                </label>
                <label className="flex items-center gap-1.5 cursor-pointer">
                  <input
                    type="radio"
                    name="extType"
                    checked={externalSearchType === 'movie'}
                    onChange={() => setExternalSearchType('movie')}
                    className="text-[#E50914] focus:ring-[#E50914] bg-zinc-900"
                  />
                  <span>Películas</span>
                </label>
                <label className="flex items-center gap-1.5 cursor-pointer">
                  <input
                    type="radio"
                    name="extType"
                    checked={externalSearchType === 'series'}
                    onChange={() => setExternalSearchType('series')}
                    className="text-[#E50914] focus:ring-[#E50914] bg-zinc-900"
                  />
                  <span>Series de TV</span>
                </label>
              </div>
            </div>

            {/* Lista de Resultados */}
            <div className="flex-1 overflow-y-auto p-4 space-y-3">
              {searchingExternal ? (
                <div className="py-12 flex flex-col items-center justify-center space-y-2 text-xs text-zinc-400">
                  <Loader2 className="w-6 h-6 animate-spin text-[#E50914]" />
                  <span>Consultando TheMovieDB...</span>
                </div>
              ) : externalResults.length === 0 ? (
                <p className="py-12 text-center text-xs text-zinc-500">
                  Ingresa un título para buscar coincidencias y carátulas en alta definición.
                </p>
              ) : (
                externalResults.map((result, idx) => (
                  <div
                    key={idx}
                    className="flex gap-3.5 p-3 rounded-xl bg-[#121212] border border-zinc-800 hover:border-zinc-700 transition-all items-center"
                  >
                    {/* Poster thumbnail */}
                    <div className="w-14 h-20 bg-zinc-900 rounded-md overflow-hidden shrink-0 border border-zinc-800 flex items-center justify-center text-zinc-600">
                      {result.poster_url ? (
                        <img
                          src={result.poster_url}
                          alt={result.title}
                          className="w-full h-full object-cover"
                          onError={(e) => {
                            e.target.style.display = 'none';
                          }}
                        />
                      ) : (
                        <Film className="w-6 h-6" />
                      )}
                    </div>

                    {/* Metadata details */}
                    <div className="flex-1 min-w-0 space-y-1">
                      <div className="flex items-center gap-2">
                        <h4 className="text-xs font-bold text-white truncate">
                          {result.title}
                        </h4>
                        {result.year && (
                          <span className="text-[11px] text-zinc-400 font-mono shrink-0">
                            ({result.year})
                          </span>
                        )}
                        {result.vote_average && (
                          <span className="text-[10px] text-amber-400 font-bold flex items-center gap-0.5 ml-auto shrink-0">
                            <Star className="w-3 h-3 fill-amber-400 text-amber-400" />
                            {result.vote_average}
                          </span>
                        )}
                      </div>
                      <p className="text-[11px] text-zinc-400 line-clamp-2 leading-relaxed">
                        {result.overview || 'Sin descripción disponible.'}
                      </p>
                      <div className="flex items-center gap-2 text-[10px] text-zinc-400 pt-0.5">
                        <span className="px-1.5 py-0.5 rounded bg-zinc-800 uppercase font-mono">
                          {result.media_type === 'series' ? 'Serie TV' : 'Película'}
                        </span>
                        {result.tmdb_id && <span>TMDb: {result.tmdb_id}</span>}
                      </div>
                    </div>

                    {/* Botón Aplicar */}
                    <button
                      type="button"
                      onClick={() => handleApplyExternalMetadata(result)}
                      className="px-3.5 py-1.5 rounded-lg bg-[#E50914] hover:bg-[#F40612] text-white text-xs font-semibold transition-all shrink-0 cursor-pointer shadow-md"
                    >
                      Aplicar
                    </button>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      )}

      {/* 5. MODAL: AGREGAR PERSONA AL CAST */}
      {addPersonModal && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-[#181818] border border-zinc-700 rounded-2xl w-full max-w-sm p-5 space-y-4 shadow-2xl animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-2.5">
              <h3 className="text-sm font-bold text-white flex items-center gap-2">
                <Users className="w-4 h-4 text-[#E50914]" />
                Agregar Miembro del Elenco
              </h3>
              <button
                type="button"
                onClick={() => setAddPersonModal(false)}
                className="text-zinc-400 hover:text-white"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <form onSubmit={handleAddPersonSubmit} className="space-y-3">
              <div className="space-y-1">
                <label className="text-xs font-medium text-zinc-300">
                  Nombre completo
                </label>
                <input
                  type="text"
                  value={newPersonName}
                  onChange={(e) => setNewPersonName(e.target.value)}
                  placeholder="Ej. Keanu Reeves"
                  autoFocus
                  required
                  className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914]"
                />
              </div>

              <div className="space-y-1">
                <label className="text-xs font-medium text-zinc-300">
                  Rol / Función
                </label>
                <select
                  value={newPersonRole}
                  onChange={(e) => setNewPersonRole(e.target.value)}
                  className="w-full bg-[#121212] border border-zinc-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-[#E50914]"
                >
                  <option value="Actor">Actor / Actriz</option>
                  <option value="Director">Director</option>
                  <option value="Guionista">Guionista / Escritor</option>
                  <option value="Productor">Productor</option>
                  <option value="Creador">Creador de la Serie</option>
                </select>
              </div>

              <div className="flex items-center justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setAddPersonModal(false)}
                  className="px-3 py-1.5 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-300 text-xs font-medium"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="px-4 py-1.5 rounded-lg bg-[#E50914] hover:bg-[#F40612] text-white text-xs font-bold"
                >
                  Agregar al Elenco
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 6. MODAL PERSONALIZADO DE CONFIRMACIÓN DE ELIMINACIÓN (Cero alerts nativos) */}
      {deleteModal && (
        <div className="fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-[#181818] border border-red-500/30 rounded-2xl w-full max-w-md p-6 space-y-4 shadow-2xl animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center gap-3 text-red-500">
              <div className="w-10 h-10 rounded-xl bg-red-500/15 border border-red-500/30 flex items-center justify-center shrink-0">
                <AlertTriangle className="w-6 h-6 text-red-500" />
              </div>
              <div>
                <h3 className="text-sm font-bold text-white">
                  ¿Confirmar eliminación de elemento?
                </h3>
                <p className="text-xs text-zinc-400">
                  Esta acción es irreversible en la base de datos.
                </p>
              </div>
            </div>

            <div className="p-3 bg-[#121212] border border-zinc-800 rounded-lg text-xs space-y-1">
              <span className="text-zinc-500 text-[10px] uppercase font-mono tracking-wider">
                Elemento a borrar:
              </span>
              <p className="font-semibold text-white truncate">
                {deleteModal.name}
              </p>
              {deleteModal.physical_path && (
                <p className="text-[11px] text-zinc-400 font-mono truncate">
                  {deleteModal.physical_path}
                </p>
              )}
            </div>

            <p className="text-xs text-zinc-300 leading-relaxed">
              Se eliminará el registro del catálogo multimedia, temporadas, episodios e historiales asociados.
            </p>

            <div className="flex items-center justify-end gap-2.5 pt-2">
              <button
                type="button"
                onClick={() => setDeleteModal(null)}
                disabled={isDeleting}
                className="px-4 py-2 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-xs font-semibold cursor-pointer"
              >
                Cancelar
              </button>
              <button
                type="button"
                onClick={handleConfirmDelete}
                disabled={isDeleting}
                className="flex items-center gap-1.5 px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-xs font-bold transition-all disabled:opacity-60 cursor-pointer shadow-lg shadow-red-950/50"
              >
                {isDeleting ? (
                  <>
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    <span>Eliminando...</span>
                  </>
                ) : (
                  <>
                    <Trash2 className="w-3.5 h-3.5" />
                    <span>Eliminar Definitivamente</span>
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 7. SISTEMA DE TOASTS FLOTANTES (Cero alertas JS nativas) */}
      {toast && (
        <div className="fixed bottom-6 right-6 z-50 flex items-start gap-3 p-4 rounded-xl shadow-2xl max-w-md w-full border backdrop-blur-md animate-in slide-in-from-bottom-5 duration-200 bg-[#181818]/95 border-zinc-700 text-zinc-100">
          <div className="shrink-0 mt-0.5">
            {toast.type === 'success' && <CheckCircle2 className="w-5 h-5 text-emerald-400" />}
            {toast.type === 'error' && <AlertCircle className="w-5 h-5 text-red-400" />}
            {toast.type === 'info' && <RefreshCw className="w-5 h-5 text-[#E50914] animate-spin" />}
          </div>

          <div className="flex-1 min-w-0">
            {toast.title && (
              <h4 className="text-xs font-bold text-white uppercase tracking-wide">
                {toast.title}
              </h4>
            )}
            <p className="text-xs text-zinc-300 mt-0.5 leading-relaxed">
              {toast.message}
            </p>
          </div>

          <button
            type="button"
            onClick={() => setToast(null)}
            className="text-zinc-400 hover:text-white p-0.5 rounded transition-colors"
            title="Cerrar notificación"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}
    </div>
  );
}
