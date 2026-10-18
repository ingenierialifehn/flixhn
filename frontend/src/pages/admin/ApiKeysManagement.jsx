import React, { useState, useEffect, useCallback, useMemo } from 'react';
import api from '../../services/api';
import {
  KeyRound,
  Plus,
  RefreshCw,
  Search,
  Filter,
  Copy,
  Check,
  Eye,
  EyeOff,
  Trash2,
  Edit2,
  Power,
  ShieldCheck,
  ShieldAlert,
  AlertTriangle,
  AlertCircle,
  CheckCircle2,
  X,
  Radio,
  Zap,
  Clock,
  Calendar,
  ExternalLink,
  Code2,
  Terminal,
  HelpCircle,
  Sliders,
  ChevronDown,
  ChevronUp,
  Server,
  Lock,
  Unlock,
  Layers,
  Sparkles,
  Info,
} from 'lucide-react';

export default function ApiKeysManagement() {
  // Estados de datos principales
  const [keys, setKeys] = useState([]);
  const [summary, setSummary] = useState({
    total: 0,
    active: 0,
    revoked: 0,
    expired: 0,
  });
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  // Filtros y búsqueda
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('all'); // all, active, revoked
  const [showDocsGuide, setShowDocsGuide] = useState(false);

  // Estados de revelación de claves individuales (id -> boolean)
  const [revealedKeys, setRevealedKeys] = useState({});
  const [copiedKeyId, setCopiedKeyId] = useState(null);

  // Sistema de Notificaciones Toast (Cero alertas nativas)
  const [toast, setToast] = useState(null); // { type: 'success' | 'error' | 'info', message, title }

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

  // Modales
  // 1. Modal Creación
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [newKeyForm, setNewKeyForm] = useState({
    name: '',
    scopes: ['*'],
    expiration: 'never',
    custom_days: 30,
  });
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState('');

  // 2. Modal Éxito / Copia única tras crear
  const [successModalData, setSuccessModalData] = useState(null); // { rawKey, apiKey }
  const [successModalCopied, setSuccessModalCopied] = useState(false);

  // 3. Modal Confirmación Revocación / Toggle
  const [toggleModalData, setToggleModalData] = useState(null); // { keyItem }
  const [toggling, setToggling] = useState(false);

  // 4. Modal Confirmación Eliminación
  const [deleteModalData, setDeleteModalData] = useState(null); // { keyItem }
  const [deleting, setDeleting] = useState(false);

  // 5. Modal Edición
  const [editModalData, setEditModalData] = useState(null); // { keyItem, name, scopes }
  const [editing, setEditing] = useState(false);

  // 6. Modal / Estado Prueba de Integración en Vivo
  const [testingKeyItem, setTestingKeyItem] = useState(null);
  const [testResult, setTestResult] = useState(null);
  const [isTesting, setIsTesting] = useState(false);

  // Carga inicial y refresco de claves API desde Laravel
  const fetchApiKeys = useCallback(async (isManualRefresh = false) => {
    if (isManualRefresh) setRefreshing(true);
    else setLoading(true);

    try {
      const res = await api.get('/admin/api-keys');
      if (res.data && res.data.status === 'success') {
        setKeys(res.data.keys || []);
        setSummary(
          res.data.summary || {
            total: (res.data.keys || []).length,
            active: 0,
            revoked: 0,
            expired: 0,
          }
        );
      }
    } catch (err) {
      console.error('Error al consultar claves API de FlixHN:', err);
      showToast('error', err.response?.data?.message || 'Error al conectar con el servidor', 'Error de red');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [showToast]);

  useEffect(() => {
    fetchApiKeys();
  }, [fetchApiKeys]);

  // Copiado al portapapeles con feedback instantáneo y toast
  const handleCopy = async (text, id = null, label = 'Clave copiada al portapapeles') => {
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
      }

      if (id !== null) {
        setCopiedKeyId(id);
        setTimeout(() => setCopiedKeyId(null), 2500);
      }
      showToast('success', label, '¡Copiado!');
    } catch (err) {
      console.error('Error al copiar al portapapeles:', err);
      showToast('error', 'No se pudo copiar automáticamente al portapapeles.', 'Atención');
    }
  };

  // Alternar visualización del token
  const toggleRevealKey = (id) => {
    setRevealedKeys((prev) => ({
      ...prev,
      [id]: !prev[id],
    }));
  };

  // Crear nueva clave API
  const handleCreateSubmit = async (e) => {
    e.preventDefault();
    setCreateError('');

    if (!newKeyForm.name.trim()) {
      setCreateError('Por favor ingresa un nombre descriptivo para la aplicación.');
      return;
    }

    setCreating(true);
    try {
      const payload = {
        name: newKeyForm.name.trim(),
        scopes: newKeyForm.scopes,
        expiration: newKeyForm.expiration,
      };

      const res = await api.post('/admin/api-keys', payload);

      if (res.data && res.data.status === 'success') {
        const rawKey = res.data.raw_key || res.data.api_key?.key;
        setCreateModalOpen(false);
        setNewKeyForm({
          name: '',
          scopes: ['*'],
          expiration: 'never',
          custom_days: 30,
        });

        // Abrir modal de éxito con la clave completa
        setSuccessModalData({
          rawKey,
          apiKey: res.data.api_key,
        });

        fetchApiKeys();
        showToast('success', 'Nueva clave API generada criptográficamente con éxito.', 'Clave Generada');
      }
    } catch (err) {
      console.error('Error al crear clave API:', err);
      const msg = err.response?.data?.message || err.response?.data?.errors?.name?.[0] || 'Error al registrar la clave API.';
      setCreateError(msg);
      showToast('error', msg, 'Error de Validación');
    } finally {
      setCreating(false);
    }
  };

  // Confirmar y alternar estado activo/revocado
  const handleConfirmToggle = async () => {
    if (!toggleModalData) return;
    setToggling(true);

    try {
      const res = await api.post(`/admin/api-keys/${toggleModalData.id}/toggle`);
      if (res.data && res.data.status === 'success') {
        const newStatus = res.data.is_active;
        setKeys((prev) =>
          prev.map((k) => (k.id === toggleModalData.id ? { ...k, is_active: newStatus } : k))
        );
        fetchApiKeys();
        showToast(
          'success',
          newStatus ? 'Clave API reactivada exitosamente.' : 'Clave API revocada. Se ha bloqueado el acceso de terceros.',
          newStatus ? 'Clave Activa' : 'Clave Revocada'
        );
      }
    } catch (err) {
      console.error('Error al alternar estado de clave:', err);
      showToast('error', err.response?.data?.message || 'No se pudo modificar el estado de la clave.', 'Error');
    } finally {
      setToggling(false);
      setToggleModalData(null);
    }
  };

  // Confirmar y eliminar definitivamente
  const handleConfirmDelete = async () => {
    if (!deleteModalData) return;
    setDeleting(true);

    try {
      const res = await api.delete(`/admin/api-keys/${deleteModalData.id}`);
      if (res.data && res.data.status === 'success') {
        setKeys((prev) => prev.filter((k) => k.id !== deleteModalData.id));
        fetchApiKeys();
        showToast('success', res.data.message || 'Clave eliminada permanentemente.', 'Eliminada');
      }
    } catch (err) {
      console.error('Error al eliminar clave:', err);
      showToast('error', err.response?.data?.message || 'Error al eliminar la clave API.', 'Error');
    } finally {
      setDeleting(false);
      setDeleteModalData(null);
    }
  };

  // Guardar edición de nombre / scopes
  const handleEditSubmit = async (e) => {
    e.preventDefault();
    if (!editModalData || !editModalData.name.trim()) return;

    setEditing(true);
    try {
      const res = await api.put(`/admin/api-keys/${editModalData.id}`, {
        name: editModalData.name.trim(),
        scopes: editModalData.scopes,
      });

      if (res.data && res.data.status === 'success') {
        fetchApiKeys();
        showToast('success', 'Datos de la clave actualizados correctamente.', 'Actualizado');
        setEditModalData(null);
      }
    } catch (err) {
      console.error('Error al actualizar clave:', err);
      showToast('error', err.response?.data?.message || 'Error al actualizar clave API.', 'Error');
    } finally {
      setEditing(false);
    }
  };

  // Prueba de conexión en vivo con el endpoint /api/v1/ping
  const handleTestKey = async (item) => {
    setTestingKeyItem(item);
    setIsTesting(true);
    setTestResult(null);

    const startTime = performance.now();
    try {
      // Usamos fetch nativo para evitar interceptores de token Sanctum y probar la clave limpia
      const response = await fetch('/api/v1/ping', {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'X-Api-Key': item.key,
        },
      });

      const latency = Math.round(performance.now() - startTime);
      const data = await response.json();

      setTestResult({
        ok: response.ok,
        status: response.status,
        statusText: response.statusText,
        latency,
        data,
      });

      if (response.ok) {
        showToast('success', `Autenticación exitosa vía X-Api-Key (${latency}ms).`, 'Conexión Verificada');
        // Refrescar último uso
        fetchApiKeys();
      } else {
        showToast('error', data.message || `Respuesta HTTP ${response.status}`, 'Autenticación Rechazada');
      }
    } catch (err) {
      const latency = Math.round(performance.now() - startTime);
      setTestResult({
        ok: false,
        status: 0,
        statusText: 'Network Error',
        latency,
        data: { message: err.message || 'Error de red al conectar con /api/v1/ping' },
      });
      showToast('error', 'Error al ejecutar prueba de conexión.', 'Fallo de Red');
    } finally {
      setIsTesting(false);
    }
  };

  // Filtrado de claves en cliente
  const filteredKeys = useMemo(() => {
    return keys.filter((item) => {
      // Filtro de estado
      if (statusFilter === 'active' && (!item.is_active || item.is_expired)) return false;
      if (statusFilter === 'revoked' && item.is_active && !item.is_expired) return false;

      // Filtro de búsqueda
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase().trim();
        const matchesName = item.name.toLowerCase().includes(q);
        const matchesKey = item.key.toLowerCase().includes(q) || item.truncated_key.toLowerCase().includes(q);
        const matchesUser = (item.user_name || '').toLowerCase().includes(q);
        return matchesName || matchesKey || matchesUser;
      }

      return true;
    });
  }, [keys, statusFilter, searchQuery]);

  return (
    <div className="space-y-6 text-zinc-100 select-none">
      {/* 1. Header Principal del Módulo */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-xl relative overflow-hidden">
        {/* Glow de acento sutil en esquina */}
        <div className="absolute top-0 right-0 w-80 h-40 bg-gradient-to-bl from-red-600/10 via-transparent to-transparent pointer-events-none" />

        <div className="flex items-start gap-4 z-10">
          <div className="w-12 h-12 rounded-xl bg-gradient-to-br from-red-600/20 to-zinc-900 border border-red-500/30 flex items-center justify-center shrink-0 shadow-lg text-[#E50914]">
            <KeyRound className="w-6 h-6 stroke-[2.2]" />
          </div>

          <div className="space-y-1">
            <div className="flex items-center gap-2.5 flex-wrap">
              <h1 className="text-xl md:text-2xl font-black text-white tracking-wide">
                Claves de API y Tokens de Integración
              </h1>
              <span className="bg-red-500/10 text-red-400 border border-red-500/20 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full font-mono">
                ISP Core v1
              </span>
            </div>
            <p className="text-xs text-zinc-400 max-w-2xl leading-relaxed">
              Genera y administra claves criptográficas seguras para autorizar aplicaciones externas, sistemas de facturación ISP, Home Assistant, Radarr, Sonarr y automatizaciones locales.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-3 z-10 self-start md:self-center">
          <button
            onClick={() => fetchApiKeys(true)}
            disabled={refreshing}
            className="p-2.5 bg-[#141414] hover:bg-zinc-800 border border-zinc-800 rounded-xl text-zinc-300 hover:text-white transition-all shadow-sm cursor-pointer disabled:opacity-50"
            title="Refrescar lista de claves"
          >
            <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin text-[#E50914]' : ''}`} />
          </button>

          <button
            onClick={() => {
              setCreateError('');
              setCreateModalOpen(true);
            }}
            className="inline-flex items-center gap-2 bg-[#E50914] hover:bg-[#b80710] active:scale-95 text-white font-bold text-xs px-4 py-2.5 rounded-xl shadow-lg shadow-red-950/40 transition-all cursor-pointer"
          >
            <Plus className="w-4 h-4 stroke-[2.5]" />
            <span>+ Nueva Clave API</span>
          </button>
        </div>
      </div>

      {/* 2. Tarjetas de Métricas y Estado Rápido */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-[#181818] border border-zinc-800/80 rounded-xl p-4 flex items-center justify-between shadow-sm">
          <div>
            <span className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400">Total Claves</span>
            <div className="text-2xl font-black text-white mt-0.5">{summary.total}</div>
            <span className="text-[11px] text-zinc-400">Registradas en el nodo</span>
          </div>
          <div className="w-10 h-10 rounded-lg bg-zinc-900 border border-zinc-800 flex items-center justify-center text-zinc-300">
            <KeyRound className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-[#181818] border border-zinc-800/80 rounded-xl p-4 flex items-center justify-between shadow-sm">
          <div>
            <span className="text-[11px] font-semibold uppercase tracking-wider text-emerald-400">Claves Activas</span>
            <div className="text-2xl font-black text-emerald-400 mt-0.5">{summary.active}</div>
            <span className="text-[11px] text-zinc-400">Autorizadas para consumo</span>
          </div>
          <div className="w-10 h-10 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
            <ShieldCheck className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-[#181818] border border-zinc-800/80 rounded-xl p-4 flex items-center justify-between shadow-sm">
          <div>
            <span className="text-[11px] font-semibold uppercase tracking-wider text-red-400">Revocadas / Suspendidas</span>
            <div className="text-2xl font-black text-red-400 mt-0.5">{summary.revoked}</div>
            <span className="text-[11px] text-zinc-400">Tráfico externo bloqueado</span>
          </div>
          <div className="w-10 h-10 rounded-lg bg-red-500/10 border border-red-500/20 flex items-center justify-center text-red-400">
            <ShieldAlert className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-[#181818] border border-zinc-800/80 rounded-xl p-4 flex items-center justify-between shadow-sm">
          <div>
            <span className="text-[11px] font-semibold uppercase tracking-wider text-cyan-400">Gateway v1 On-Net</span>
            <div className="text-sm font-black text-zinc-100 mt-1 font-mono flex items-center gap-1.5">
              <span className="w-2 h-2 rounded-full bg-cyan-400 animate-pulse" />
              <span>/api/v1/*</span>
            </div>
            <span className="text-[11px] text-zinc-400">Header <code className="text-zinc-300 font-mono">X-Api-Key</code></span>
          </div>
          <div className="w-10 h-10 rounded-lg bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400">
            <Radio className="w-5 h-5" />
          </div>
        </div>
      </div>

      {/* 3. Guía rápida desplegable de Integración para Desarrolladores */}
      <div className="bg-[#181818] border border-zinc-800 rounded-xl overflow-hidden transition-all shadow-md">
        <button
          onClick={() => setShowDocsGuide((prev) => !prev)}
          className="w-full px-5 py-3.5 flex items-center justify-between text-left text-xs font-semibold text-zinc-300 hover:text-white hover:bg-zinc-800/40 transition-colors cursor-pointer"
        >
          <div className="flex items-center gap-2.5">
            <Code2 className="w-4 h-4 text-[#E50914]" />
            <span>Guía de Conexión & Ejemplos de Petición cURL / HTTP</span>
            <span className="bg-zinc-800 text-zinc-400 text-[10px] px-2 py-0.5 rounded-full font-mono">
              X-Api-Key | ?api_key=
            </span>
          </div>
          <div className="flex items-center gap-1 text-zinc-400 text-[11px]">
            <span>{showDocsGuide ? 'Ocultar' : 'Ver documentación rápida'}</span>
            {showDocsGuide ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}
          </div>
        </button>

        {showDocsGuide && (
          <div className="p-5 pt-3 border-t border-zinc-800/80 bg-[#121212] space-y-4 text-xs font-mono">
            <p className="text-zinc-400 text-[11px] font-sans leading-relaxed">
              Para autenticar llamadas externas a la API de FlixHN, adjunta la clave generada en el encabezado HTTP <strong className="text-zinc-200">X-Api-Key</strong> o como parámetro de consulta en la URL <strong className="text-zinc-200">?api_key=</strong>.
            </p>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              {/* Opción 1: Encabezado */}
              <div className="bg-[#181818] border border-zinc-800 rounded-lg p-3 space-y-2">
                <div className="flex items-center justify-between text-[11px] text-zinc-400 font-sans">
                  <span className="font-bold text-zinc-200">1. Vía Encabezado HTTP (Recomendado)</span>
                  <button
                    onClick={() => handleCopy('curl -H "X-Api-Key: TU_CLAVE_API" http://localhost/api/v1/ping', null, 'Comando cURL copiado')}
                    className="hover:text-white text-zinc-400 flex items-center gap-1 cursor-pointer"
                  >
                    <Copy className="w-3 h-3" />
                    <span>Copiar</span>
                  </button>
                </div>
                <div className="bg-black/60 p-2.5 rounded border border-zinc-800 text-emerald-400 overflow-x-auto text-[11px]">
                  curl -H "X-Api-Key: TU_CLAVE_API" \<br />
                  &nbsp;&nbsp;http://localhost/api/v1/ping
                </div>
              </div>

              {/* Opción 2: Query param */}
              <div className="bg-[#181818] border border-zinc-800 rounded-lg p-3 space-y-2">
                <div className="flex items-center justify-between text-[11px] text-zinc-400 font-sans">
                  <span className="font-bold text-zinc-200">2. Vía Parámetro en URL</span>
                  <button
                    onClick={() => handleCopy('curl http://localhost/api/v1/ping?api_key=TU_CLAVE_API', null, 'URL de prueba copiada')}
                    className="hover:text-white text-zinc-400 flex items-center gap-1 cursor-pointer"
                  >
                    <Copy className="w-3 h-3" />
                    <span>Copiar</span>
                  </button>
                </div>
                <div className="bg-black/60 p-2.5 rounded border border-zinc-800 text-cyan-400 overflow-x-auto text-[11px]">
                  curl "http://localhost/api/v1/ping?api_key=TU_CLAVE_API"
                </div>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* 4. Barra de Búsqueda y Filtros */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-[#181818] border border-zinc-800 p-3 rounded-xl shadow-sm">
        {/* Input Buscador */}
        <div className="relative flex-1">
          <Search className="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="Buscar por nombre de app, servicio o token..."
            className="w-full bg-[#141414] border border-zinc-800 rounded-lg pl-9 pr-4 py-2 text-xs text-zinc-200 placeholder-zinc-500 focus:outline-none focus:border-[#E50914] transition-colors"
          />
          {searchQuery && (
            <button
              onClick={() => setSearchQuery('')}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300"
            >
              <X className="w-3.5 h-3.5" />
            </button>
          )}
        </div>

        {/* Pestañas de Filtro de Estado */}
        <div className="flex items-center gap-1 bg-[#141414] p-1 rounded-lg border border-zinc-800 shrink-0">
          <button
            onClick={() => setStatusFilter('all')}
            className={`px-3 py-1.5 rounded-md text-xs font-semibold transition-all cursor-pointer ${
              statusFilter === 'all'
                ? 'bg-zinc-800 text-white shadow'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            Todas ({keys.length})
          </button>
          <button
            onClick={() => setStatusFilter('active')}
            className={`px-3 py-1.5 rounded-md text-xs font-semibold transition-all cursor-pointer ${
              statusFilter === 'active'
                ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 shadow'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            Activas ({summary.active})
          </button>
          <button
            onClick={() => setStatusFilter('revoked')}
            className={`px-3 py-1.5 rounded-md text-xs font-semibold transition-all cursor-pointer ${
              statusFilter === 'revoked'
                ? 'bg-red-500/20 text-red-300 border border-red-500/30 shadow'
                : 'text-zinc-400 hover:text-zinc-200'
            }`}
          >
            Revocadas ({summary.revoked})
          </button>
        </div>
      </div>

      {/* 5. Tabla / Listado de Claves API */}
      <div className="bg-[#181818] border border-zinc-800 rounded-2xl overflow-hidden shadow-xl">
        {loading ? (
          <div className="p-12 flex flex-col items-center justify-center gap-3 text-zinc-400">
            <RefreshCw className="w-8 h-8 text-[#E50914] animate-spin" />
            <span className="text-xs font-medium">Cargando llaves API autorizadas en FlixHN...</span>
          </div>
        ) : filteredKeys.length === 0 ? (
          <div className="p-16 text-center space-y-4">
            <div className="w-16 h-16 rounded-2xl bg-zinc-900 border border-zinc-800 mx-auto flex items-center justify-center text-zinc-600">
              <KeyRound className="w-8 h-8" />
            </div>
            <div className="space-y-1">
              <h3 className="text-base font-bold text-zinc-200">
                {searchQuery || statusFilter !== 'all'
                  ? 'No se encontraron claves que coincidan con la búsqueda'
                  : 'No hay Claves API registradas actualmente'}
              </h3>
              <p className="text-xs text-zinc-500 max-w-md mx-auto">
                {searchQuery || statusFilter !== 'all'
                  ? 'Intenta cambiar los términos de búsqueda o el filtro de estado seleccionado.'
                  : 'Genera tu primer token criptográfico para conectar Radarr, Sonarr, Home Assistant o tus scripts de facturación.'}
              </p>
            </div>
            {!searchQuery && statusFilter === 'all' && (
              <button
                onClick={() => setCreateModalOpen(true)}
                className="inline-flex items-center gap-2 bg-[#E50914] hover:bg-[#b80710] text-white text-xs font-bold px-4 py-2.5 rounded-xl shadow transition-all cursor-pointer"
              >
                <Plus className="w-4 h-4 stroke-[2.5]" />
                <span>+ Crear la Primera Clave API</span>
              </button>
            )}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-zinc-800/80 bg-zinc-900/50 text-zinc-400 font-semibold uppercase tracking-wider text-[10px]">
                  <th className="py-3.5 px-4">Aplicación / Servicio</th>
                  <th className="py-3.5 px-4">Token / Clave de Acceso</th>
                  <th className="py-3.5 px-4">Alcance</th>
                  <th className="py-3.5 px-4">Último Uso</th>
                  <th className="py-3.5 px-4">Expiración</th>
                  <th className="py-3.5 px-4">Estado</th>
                  <th className="py-3.5 px-4 text-right">Acciones</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/50">
                {filteredKeys.map((item) => {
                  const isRevealed = !!revealedKeys[item.id];
                  const isCopied = copiedKeyId === item.id;
                  const isCurrentlyActive = item.is_active && !item.is_expired;

                  return (
                    <tr
                      key={item.id}
                      className="hover:bg-zinc-800/30 transition-colors group"
                    >
                      {/* 1. Nombre de la app / servicio */}
                      <td className="py-4 px-4 align-middle">
                        <div className="flex items-center gap-3">
                          <div
                            className={`w-9 h-9 rounded-xl flex items-center justify-center shrink-0 border ${
                              isCurrentlyActive
                                ? 'bg-zinc-900 border-zinc-700/80 text-emerald-400'
                                : 'bg-red-500/10 border-red-500/20 text-red-400'
                            }`}
                          >
                            <KeyRound className="w-4 h-4" />
                          </div>

                          <div className="min-w-0">
                            <div className="font-bold text-zinc-100 flex items-center gap-2">
                              <span className="truncate max-w-[180px] md:max-w-xs">{item.name}</span>
                              {item.is_expired && (
                                <span className="bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[9px] px-1.5 py-0.2 rounded font-mono font-bold uppercase">
                                  Expirada
                                </span>
                              )}
                            </div>
                            <span className="text-[11px] text-zinc-500 block truncate">
                              Creado por {item.user_name || 'Administrador'} • {item.created_human}
                            </span>
                          </div>
                        </div>
                      </td>

                      {/* 2. Token / Clave con máscara y revelación */}
                      <td className="py-4 px-4 align-middle font-mono">
                        <div className="inline-flex items-center gap-1.5 bg-[#121212] border border-zinc-800 px-2.5 py-1.5 rounded-lg max-w-[280px]">
                          <span className="text-zinc-300 truncate select-all text-[11px]">
                            {isRevealed
                              ? item.key
                              : item.truncated_key || `${item.key.slice(0, 8)}...${item.key.slice(-4)}`}
                          </span>

                          <div className="flex items-center gap-1 shrink-0 ml-1">
                            {/* Botón Revelar/Ocultar */}
                            <button
                              onClick={() => toggleRevealKey(item.id)}
                              className="p-1 text-zinc-400 hover:text-zinc-200 rounded hover:bg-zinc-800 transition-colors cursor-pointer"
                              title={isRevealed ? 'Ocultar clave' : 'Mostrar clave completa'}
                            >
                              {isRevealed ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
                            </button>

                            {/* Botón Copiar Rápido */}
                            <button
                              onClick={() => handleCopy(item.key, item.id, `Clave de "${item.name}" copiada`)}
                              className={`p-1 rounded transition-colors cursor-pointer ${
                                isCopied
                                  ? 'text-emerald-400 bg-emerald-500/20'
                                  : 'text-zinc-400 hover:text-white hover:bg-zinc-800'
                              }`}
                              title="Copiar clave al portapapeles"
                            >
                              {isCopied ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                            </button>
                          </div>
                        </div>
                      </td>

                      {/* 3. Permisos / Alcances */}
                      <td className="py-4 px-4 align-middle">
                        <div className="flex flex-wrap gap-1">
                          {item.scopes && item.scopes.includes('*') ? (
                            <span className="bg-indigo-500/10 text-indigo-300 border border-indigo-500/20 px-2 py-0.5 rounded text-[10px] font-semibold">
                              Control Total
                            </span>
                          ) : (
                            (item.scopes || ['*']).map((s, idx) => (
                              <span
                                key={idx}
                                className="bg-zinc-800 text-zinc-300 px-1.5 py-0.5 rounded text-[10px] font-mono"
                              >
                                {s}
                              </span>
                            ))
                          )}
                        </div>
                      </td>

                      {/* 4. Último Uso */}
                      <td className="py-4 px-4 align-middle">
                        <div className="flex items-center gap-2">
                          <span
                            className={`w-2 h-2 rounded-full shrink-0 ${
                              item.last_used_at ? 'bg-emerald-400' : 'bg-zinc-600'
                            }`}
                          />
                          <span
                            className="text-zinc-300 text-[11px]"
                            title={item.last_used_at || 'Sin actividad registrada'}
                          >
                            {item.last_used_human}
                          </span>
                        </div>
                      </td>

                      {/* 5. Expiración */}
                      <td className="py-4 px-4 align-middle">
                        <span
                          className={`text-[11px] ${
                            item.is_expired ? 'text-amber-400 font-semibold' : 'text-zinc-400'
                          }`}
                          title={item.expires_at || 'Sin fecha de caducidad'}
                        >
                          {item.expires_human}
                        </span>
                      </td>

                      {/* 6. Estado */}
                      <td className="py-4 px-4 align-middle">
                        {isCurrentlyActive ? (
                          <span className="inline-flex items-center gap-1.5 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-2.5 py-1 rounded-full text-[10px] font-bold">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                            Activa
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1.5 bg-red-500/10 text-red-400 border border-red-500/20 px-2.5 py-1 rounded-full text-[10px] font-bold">
                            <span className="w-1.5 h-1.5 rounded-full bg-red-500" />
                            Revocada
                          </span>
                        )}
                      </td>

                      {/* 7. Acciones */}
                      <td className="py-4 px-4 align-middle text-right">
                        <div className="flex items-center justify-end gap-1">
                          {/* Botón Probar */}
                          <button
                            onClick={() => handleTestKey(item)}
                            className="p-1.5 text-zinc-400 hover:text-cyan-400 hover:bg-cyan-500/10 rounded-lg transition-colors cursor-pointer"
                            title="Probar conexión en vivo (/api/v1/ping)"
                          >
                            <Zap className="w-4 h-4" />
                          </button>

                          {/* Botón Editar */}
                          <button
                            onClick={() =>
                              setEditModalData({
                                ...item,
                                scopes: item.scopes || ['*'],
                              })
                            }
                            className="p-1.5 text-zinc-400 hover:text-white hover:bg-zinc-800 rounded-lg transition-colors cursor-pointer"
                            title="Editar nombre y permisos"
                          >
                            <Edit2 className="w-4 h-4" />
                          </button>

                          {/* Botón Alternar Estado */}
                          <button
                            onClick={() => setToggleModalData(item)}
                            className={`p-1.5 rounded-lg transition-colors cursor-pointer ${
                              item.is_active
                                ? 'text-zinc-400 hover:text-amber-400 hover:bg-amber-500/10'
                                : 'text-zinc-400 hover:text-emerald-400 hover:bg-emerald-500/10'
                            }`}
                            title={item.is_active ? 'Revocar clave API' : 'Reactivar clave API'}
                          >
                            <Power className="w-4 h-4" />
                          </button>

                          {/* Botón Eliminar */}
                          <button
                            onClick={() => setDeleteModalData(item)}
                            className="p-1.5 text-zinc-400 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition-colors cursor-pointer"
                            title="Eliminar definitivamente"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* =========================================================================
          MODALES ESTILIZADOS FLIXHN (CERO ALERTAS NATIVAS)
          ========================================================================= */}

      {/* MODAL 1: Formulario Nueva Clave API */}
      {createModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fade-in">
          <div className="bg-[#181818] border border-zinc-800 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-5 animate-slide-up relative">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-4">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-xl bg-red-500/10 border border-red-500/20 text-[#E50914] flex items-center justify-center">
                  <KeyRound className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-base font-bold text-white">Generar Nueva Clave API</h3>
                  <p className="text-xs text-zinc-400">Emisión de token de integración con cifrado seguro</p>
                </div>
              </div>
              <button
                onClick={() => setCreateModalOpen(false)}
                className="text-zinc-400 hover:text-white p-1 rounded-lg hover:bg-zinc-800 transition-colors cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleCreateSubmit} className="space-y-4">
              {/* Nombre de la aplicación */}
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-zinc-300">
                  Nombre de la Aplicación o Servicio <span className="text-[#E50914]">*</span>
                </label>
                <input
                  type="text"
                  required
                  value={newKeyForm.name}
                  onChange={(e) => setNewKeyForm({ ...newKeyForm, name: e.target.value })}
                  placeholder="Ej. Radarr, Sonarr, Home Assistant, Script Facturación..."
                  className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-4 py-2.5 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-[#E50914] transition-colors"
                />
                <span className="text-[11px] text-zinc-500">
                  Identificador visual para reconocer qué sistema está consumiendo la API.
                </span>
              </div>

              {/* Alcance / Permisos */}
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-zinc-300">Alcance de Permisos (Scopes)</label>
                <div className="grid grid-cols-3 gap-2">
                  <button
                    type="button"
                    onClick={() => setNewKeyForm({ ...newKeyForm, scopes: ['*'] })}
                    className={`p-2.5 rounded-xl border text-left transition-all cursor-pointer ${
                      newKeyForm.scopes.includes('*')
                        ? 'bg-red-500/10 border-[#E50914] text-white'
                        : 'bg-[#121212] border-zinc-800 text-zinc-400 hover:border-zinc-700'
                    }`}
                  >
                    <div className="text-xs font-bold text-zinc-100">Control Total</div>
                    <div className="text-[10px] text-zinc-400 mt-0.5">Permisos globales (*)</div>
                  </button>

                  <button
                    type="button"
                    onClick={() => setNewKeyForm({ ...newKeyForm, scopes: ['read', 'write'] })}
                    className={`p-2.5 rounded-xl border text-left transition-all cursor-pointer ${
                      !newKeyForm.scopes.includes('*') && newKeyForm.scopes.includes('write')
                        ? 'bg-red-500/10 border-[#E50914] text-white'
                        : 'bg-[#121212] border-zinc-800 text-zinc-400 hover:border-zinc-700'
                    }`}
                  >
                    <div className="text-xs font-bold text-zinc-100">Lectura & Escritura</div>
                    <div className="text-[10px] text-zinc-400 mt-0.5">read, write</div>
                  </button>

                  <button
                    type="button"
                    onClick={() => setNewKeyForm({ ...newKeyForm, scopes: ['read'] })}
                    className={`p-2.5 rounded-xl border text-left transition-all cursor-pointer ${
                      newKeyForm.scopes.length === 1 && newKeyForm.scopes.includes('read')
                        ? 'bg-red-500/10 border-[#E50914] text-white'
                        : 'bg-[#121212] border-zinc-800 text-zinc-400 hover:border-zinc-700'
                    }`}
                  >
                    <div className="text-xs font-bold text-zinc-100">Solo Lectura</div>
                    <div className="text-[10px] text-zinc-400 mt-0.5">Consultas (read)</div>
                  </button>
                </div>
              </div>

              {/* Tiempo de caducidad */}
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-zinc-300">Periodo de Expiración</label>
                <select
                  value={newKeyForm.expiration}
                  onChange={(e) => setNewKeyForm({ ...newKeyForm, expiration: e.target.value })}
                  className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-[#E50914] transition-colors cursor-pointer"
                >
                  <option value="never">Nunca expira (Recomendado para servidores)</option>
                  <option value="30_days">30 días</option>
                  <option value="90_days">90 días</option>
                  <option value="1_year">1 año</option>
                </select>
              </div>

              {createError && (
                <div className="p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs flex items-center gap-2">
                  <AlertCircle className="w-4 h-4 shrink-0" />
                  <span>{createError}</span>
                </div>
              )}

              <div className="flex items-center justify-end gap-3 pt-3 border-t border-zinc-800">
                <button
                  type="button"
                  onClick={() => setCreateModalOpen(false)}
                  className="px-4 py-2.5 rounded-xl text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-800 hover:bg-zinc-700 transition-colors cursor-pointer"
                >
                  Cancelar
                </button>

                <button
                  type="submit"
                  disabled={creating}
                  className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#b80710] shadow-md transition-all cursor-pointer disabled:opacity-50"
                >
                  {creating && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                  <span>{creating ? 'Generando...' : 'Crear Clave'}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL 2: Éxito al Generar Clave (Copia de Seguridad Única) */}
      {successModalData && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/85 backdrop-blur-md animate-fade-in">
          <div className="bg-[#181818] border border-emerald-500/30 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-5 animate-slide-up relative">
            <div className="flex items-center gap-3">
              <div className="w-12 h-12 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 flex items-center justify-center shrink-0">
                <CheckCircle2 className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-base font-black text-white">¡Clave API Generada con Éxito!</h3>
                <span className="text-xs text-zinc-400">
                  Asignada a: <strong className="text-emerald-400">{successModalData.apiKey?.name}</strong>
                </span>
              </div>
            </div>

            {/* Advertencia de Seguridad */}
            <div className="bg-amber-500/10 border border-amber-500/30 rounded-xl p-3.5 flex items-start gap-3 text-amber-200 text-xs leading-relaxed">
              <AlertTriangle className="w-5 h-5 text-amber-400 shrink-0 mt-0.5" />
              <div>
                <strong className="block text-amber-300 font-bold">Importante: Guarda esta clave en un lugar seguro.</strong>
                Por motivos de seguridad, no se volverá a mostrar completa tras cerrar este cuadro.
              </div>
            </div>

            {/* Bloque de Clave con Copia Instantánea */}
            <div className="space-y-1.5">
              <label className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400">
                Tu Clave de Autenticación Criptográfica:
              </label>
              <div className="flex items-center gap-2 bg-[#0d0d0d] border border-zinc-800 p-2.5 rounded-xl font-mono text-xs shadow-inner">
                <div className="flex-1 text-emerald-400 truncate select-all font-semibold">
                  {successModalData.rawKey}
                </div>
                <button
                  onClick={() => {
                    handleCopy(successModalData.rawKey, null, 'Clave copiada al portapapeles');
                    setSuccessModalCopied(true);
                    setTimeout(() => setSuccessModalCopied(false), 3000);
                  }}
                  className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                    successModalCopied
                      ? 'bg-emerald-500 text-black'
                      : 'bg-zinc-800 hover:bg-zinc-700 text-white'
                  }`}
                >
                  {successModalCopied ? (
                    <>
                      <Check className="w-3.5 h-3.5" />
                      <span>¡Copiada!</span>
                    </>
                  ) : (
                    <>
                      <Copy className="w-3.5 h-3.5" />
                      <span>Copiar Clave</span>
                    </>
                  )}
                </button>
              </div>
            </div>

            {/* Ejemplo rápido de cURL */}
            <div className="space-y-1 font-mono text-[11px]">
              <span className="text-zinc-500 text-[10px] uppercase font-sans font-bold">Ejemplo de uso cURL:</span>
              <div className="bg-black/60 p-2 rounded border border-zinc-800 text-zinc-300 truncate">
                curl -H "X-Api-Key: {successModalData.rawKey}" http://localhost/api/v1/ping
              </div>
            </div>

            <div className="pt-2 border-t border-zinc-800 flex justify-end">
              <button
                onClick={() => setSuccessModalData(null)}
                className="w-full py-2.5 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#b80710] shadow-md transition-all cursor-pointer"
              >
                Entendido, ya guardé mi clave
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL 3: Confirmación de Revocación / Suspensión */}
      {toggleModalData && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fade-in">
          <div className="bg-[#181818] border border-zinc-800 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4 animate-slide-up">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center shrink-0">
                <AlertTriangle className="w-5 h-5" />
              </div>
              <div>
                <h3 className="text-base font-bold text-white">
                  {toggleModalData.is_active ? '¿Suspender / Revocar Clave?' : '¿Reactivar Clave API?'}
                </h3>
                <span className="text-xs text-zinc-400">{toggleModalData.name}</span>
              </div>
            </div>

            <p className="text-xs text-zinc-300 leading-relaxed">
              {toggleModalData.is_active
                ? `Las aplicaciones y scripts externos conectados mediante esta clave perderán el acceso de inmediato (HTTP 403 Forbidden). Podrás reactivarla en cualquier momento.`
                : `Se restaurará el acceso a la API para todas las peticiones entrantes que envíen esta clave.`}
            </p>

            <div className="flex items-center justify-end gap-3 pt-3 border-t border-zinc-800">
              <button
                onClick={() => setToggleModalData(null)}
                className="px-4 py-2 rounded-xl text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-800 hover:bg-zinc-700 transition-colors cursor-pointer"
              >
                Cancelar
              </button>
              <button
                onClick={handleConfirmToggle}
                disabled={toggling}
                className={`px-4 py-2 rounded-xl text-xs font-bold text-white transition-all cursor-pointer ${
                  toggleModalData.is_active
                    ? 'bg-amber-600 hover:bg-amber-700'
                    : 'bg-emerald-600 hover:bg-emerald-700'
                }`}
              >
                {toggling ? 'Procesando...' : toggleModalData.is_active ? 'Sí, Revocar Clave' : 'Sí, Reactivar Clave'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL 4: Confirmación de Eliminación Definitiva */}
      {deleteModalData && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/85 backdrop-blur-sm animate-fade-in">
          <div className="bg-[#181818] border border-red-500/30 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4 animate-slide-up">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-red-500/10 border border-red-500/20 text-[#E50914] flex items-center justify-center shrink-0">
                <Trash2 className="w-5 h-5" />
              </div>
              <div>
                <h3 className="text-base font-black text-white">¿Eliminar Clave API Definitivamente?</h3>
                <span className="text-xs text-zinc-400">{deleteModalData.name}</span>
              </div>
            </div>

            <p className="text-xs text-zinc-300 leading-relaxed">
              Esta acción es permanente e irreversible. La clave <strong className="text-white">{deleteModalData.name}</strong> será eliminada de la base de datos y no podrá volver a ser utilizada.
            </p>

            <div className="flex items-center justify-end gap-3 pt-3 border-t border-zinc-800">
              <button
                onClick={() => setDeleteModalData(null)}
                className="px-4 py-2 rounded-xl text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-800 hover:bg-zinc-700 transition-colors cursor-pointer"
              >
                Cancelar
              </button>
              <button
                onClick={handleConfirmDelete}
                disabled={deleting}
                className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#b80710] transition-all cursor-pointer disabled:opacity-50"
              >
                {deleting ? 'Eliminando...' : 'Eliminar Permanentemente'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL 5: Editar Nombre y Alcance */}
      {editModalData && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fade-in">
          <div className="bg-[#181818] border border-zinc-800 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4 animate-slide-up">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
              <h3 className="text-base font-bold text-white">Editar Clave API</h3>
              <button
                onClick={() => setEditModalData(null)}
                className="text-zinc-400 hover:text-white p-1 rounded-lg"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleEditSubmit} className="space-y-4">
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-zinc-300">Nombre de la Aplicación</label>
                <input
                  type="text"
                  required
                  value={editModalData.name}
                  onChange={(e) => setEditModalData({ ...editModalData, name: e.target.value })}
                  className="w-full bg-[#121212] border border-zinc-800 rounded-xl px-4 py-2 text-xs text-white focus:outline-none focus:border-[#E50914]"
                />
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-zinc-300">Alcance (Scopes)</label>
                <div className="grid grid-cols-2 gap-2 text-xs">
                  <button
                    type="button"
                    onClick={() => setEditModalData({ ...editModalData, scopes: ['*'] })}
                    className={`p-2 rounded-xl border text-left cursor-pointer ${
                      editModalData.scopes?.includes('*')
                        ? 'bg-red-500/10 border-[#E50914] text-white'
                        : 'bg-[#121212] border-zinc-800 text-zinc-400'
                    }`}
                  >
                    Control Total (*)
                  </button>
                  <button
                    type="button"
                    onClick={() => setEditModalData({ ...editModalData, scopes: ['read'] })}
                    className={`p-2 rounded-xl border text-left cursor-pointer ${
                      editModalData.scopes?.length === 1 && editModalData.scopes?.includes('read')
                        ? 'bg-red-500/10 border-[#E50914] text-white'
                        : 'bg-[#121212] border-zinc-800 text-zinc-400'
                    }`}
                  >
                    Solo Lectura (read)
                  </button>
                </div>
              </div>

              <div className="flex items-center justify-end gap-3 pt-3 border-t border-zinc-800">
                <button
                  type="button"
                  onClick={() => setEditModalData(null)}
                  className="px-4 py-2 rounded-xl text-xs font-semibold text-zinc-300 bg-zinc-800 hover:bg-zinc-700"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={editing}
                  className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#b80710]"
                >
                  {editing ? 'Guardando...' : 'Guardar Cambios'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* MODAL 6: Resultados Prueba de Integración en Tiempo Real */}
      {testingKeyItem && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/85 backdrop-blur-md animate-fade-in">
          <div className="bg-[#181818] border border-zinc-800 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4 animate-slide-up">
            <div className="flex items-center justify-between border-b border-zinc-800 pb-3">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 flex items-center justify-center">
                  <Zap className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-base font-bold text-white">Prueba de Conexión en Vivo</h3>
                  <span className="text-xs text-zinc-400">{testingKeyItem.name}</span>
                </div>
              </div>
              <button
                onClick={() => setTestingKeyItem(null)}
                className="text-zinc-400 hover:text-white p-1"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-3">
              <div className="flex items-center justify-between text-xs bg-[#121212] p-3 rounded-xl border border-zinc-800">
                <span className="text-zinc-400">Endpoint Evaluado:</span>
                <code className="text-zinc-200 font-mono">GET /api/v1/ping</code>
              </div>

              {isTesting ? (
                <div className="p-8 text-center space-y-2 text-zinc-400">
                  <RefreshCw className="w-6 h-6 animate-spin mx-auto text-cyan-400" />
                  <p className="text-xs">Enviando petición HTTP con header X-Api-Key...</p>
                </div>
              ) : testResult ? (
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <span className="text-xs text-zinc-400">Estado de Respuesta:</span>
                    <span
                      className={`text-xs font-bold px-2 py-0.5 rounded font-mono ${
                        testResult.ok
                          ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
                          : 'bg-red-500/10 text-red-400 border border-red-500/20'
                      }`}
                    >
                      HTTP {testResult.status} ({testResult.latency}ms)
                    </span>
                  </div>

                  <div className="bg-[#0c0c0c] border border-zinc-800 rounded-xl p-3 font-mono text-[11px] text-zinc-300 max-h-48 overflow-y-auto leading-relaxed shadow-inner">
                    <pre>{JSON.stringify(testResult.data, null, 2)}</pre>
                  </div>
                </div>
              ) : null}
            </div>

            <div className="pt-2 border-t border-zinc-800 flex justify-end">
              <button
                onClick={() => setTestingKeyItem(null)}
                className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-zinc-800 hover:bg-zinc-700"
              >
                Cerrar Prueba
              </button>
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
            className="text-zinc-400 hover:text-zinc-200 p-0.5 rounded transition-colors cursor-pointer"
            title="Cerrar notificación"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}
    </div>
  );
}
