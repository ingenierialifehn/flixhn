import React, { useState, useEffect, useRef } from 'react';
import api from '../../services/api';
import {
  Network,
  Save,
  CheckCircle2,
  AlertCircle,
  FolderOpen,
  Eye,
  EyeOff,
  RotateCcw,
  Server,
  Key,
  RefreshCw,
  Plus,
  Trash2,
  Check,
  Layers,
  Power,
  X,
  Loader2,
} from 'lucide-react';

const DEFAULT_SETTINGS = {
  lan_networks: '10.0.0.0/8, 192.168.0.0/16, 172.16.0.0/16, 180.80.8.0/24',
  local_ip_address: '',
  local_http_port: 8789,
  local_https_port: 8920,
  allow_remote_connections: true,
  remote_ip_filter: '',
  remote_ip_filter_mode: 'Whitelist',
  public_http_port: 8789,
  public_https_port: 8920,
  external_domain: '',
  read_proxy_headers: 'Only when they contain remote network addresses',
  custom_ssl_cert_path: '',
  certificate_password: '',
  secure_connection_mode: 'Disabled',
  enable_upnp: false,
  max_simultaneous_streams: 'Unlimited',
  internet_streaming_bitrate_limit: '',
  media_server_url: 'http://45.4.87.126:6789',
  media_server_api_key: '',
};

const NetworkSettings = () => {
  const [formData, setFormData] = useState(DEFAULT_SETTINGS);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [showApiKey, setShowApiKey] = useState(false);
  const [testingMedia, setTestingMedia] = useState(false);
  const [mediaSyncResult, setMediaSyncResult] = useState(null);
  const [toast, setToast] = useState(null); // { type: 'success' | 'error', message: string }
  const fileInputRef = useRef(null);

  // Soporte Multi-Servidor (Punto 9)
  const [nodes, setNodes] = useState([]);
  const [loadingNodes, setLoadingNodes] = useState(false);
  const [showAddNodeModal, setShowAddNodeModal] = useState(false);
  const [newNode, setNewNode] = useState({
    name: '',
    ip_address: '',
    port: 6789,
    api_key: '',
    is_master: false,
  });
  const [addingNode, setAddingNode] = useState(false);
  const [actionNodeId, setActionNodeId] = useState(null);

  const fetchNodes = async () => {
    setLoadingNodes(true);
    try {
      const res = await api.get('/admin/nodes');
      if (res.data?.nodes) {
        setNodes(res.data.nodes);
      }
    } catch (err) {
      console.warn('Error al cargar nodos de medios:', err);
    } finally {
      setLoadingNodes(false);
    }
  };

  useEffect(() => {
    fetchNodes();
  }, []);

  const handleAddNode = async (e) => {
    if (e) e.preventDefault();
    if (!newNode.name.trim() || !newNode.ip_address.trim()) {
      alert('Ingresa el nombre y la dirección IP/Host del nuevo servidor.');
      return;
    }
    setAddingNode(true);
    try {
      const res = await api.post('/admin/nodes', newNode);
      setToast({
        type: 'success',
        message: res.data?.message || `Servidor "${newNode.name}" conectado exitosamente.`,
      });
      setShowAddNodeModal(false);
      setNewNode({ name: '', ip_address: '', port: 6789, api_key: '', is_master: false });
      fetchNodes();
    } catch (err) {
      alert(err.response?.data?.message || 'Error al conectar el nuevo servidor');
    } finally {
      setAddingNode(false);
    }
  };

  const handleActivateNode = async (node) => {
    setActionNodeId(node.id);
    try {
      const res = await api.post(`/admin/nodes/${node.id}/activate`);
      setToast({
        type: 'success',
        message: res.data?.message || `Servidor "${node.name}" activado como principal.`,
      });
      setFormData((prev) => ({
        ...prev,
        media_server_url: `http://${node.ip_address}:${node.port}`,
        media_server_api_key: node.api_key || '',
      }));
      fetchNodes();
    } catch (err) {
      alert(err.response?.data?.message || 'Error al activar el servidor');
    } finally {
      setActionNodeId(null);
    }
  };

  const handleSyncNode = async (node) => {
    setActionNodeId(node.id);
    try {
      const res = await api.post(`/admin/nodes/${node.id}/sync`);
      setToast({
        type: 'success',
        message: res.data?.message || `Catálogo del servidor "${node.name}" sincronizado exitosamente.`,
      });
    } catch (err) {
      alert(err.response?.data?.message || 'Error al sincronizar el servidor');
    } finally {
      setActionNodeId(null);
    }
  };

  const handleDeleteNode = async (node) => {
    if (!window.confirm(`¿Seguro que deseas desvincular el servidor "${node.name}"?`)) return;
    try {
      await api.delete(`/admin/nodes/${node.id}`);
      setToast({
        type: 'success',
        message: 'Servidor desvinculado correctamente.',
      });
      fetchNodes();
    } catch (err) {
      alert(err.response?.data?.message || 'Error al eliminar el servidor');
    }
  };

  // Cargar configuración de red desde el backend (soporta /admin/network-settings y fallback a /admin/network)
  useEffect(() => {
    let isMounted = true;
    setLoading(true);

    api.get('/admin/network-settings')
      .catch(() => api.get('/admin/network'))
      .then((res) => {
        if (!isMounted) return;
        const s = res?.data?.settings || res?.data?.data || res?.data || {};
        setFormData({
          lan_networks: s.lan_networks ?? DEFAULT_SETTINGS.lan_networks,
          local_ip_address: s.local_ip_address ?? '',
          local_http_port: s.local_http_port ?? DEFAULT_SETTINGS.local_http_port,
          local_https_port: s.local_https_port ?? DEFAULT_SETTINGS.local_https_port,
          allow_remote_connections: s.allow_remote_connections !== undefined
            ? Boolean(s.allow_remote_connections)
            : true,
          remote_ip_filter: s.remote_ip_filter ?? '',
          remote_ip_filter_mode: s.remote_ip_filter_mode || 'Whitelist',
          public_http_port: s.public_http_port ?? DEFAULT_SETTINGS.public_http_port,
          public_https_port: s.public_https_port ?? DEFAULT_SETTINGS.public_https_port,
          external_domain: s.external_domain ?? '',
          read_proxy_headers: s.read_proxy_headers || 'Only when they contain remote network addresses',
          custom_ssl_cert_path: s.custom_ssl_cert_path ?? '',
          certificate_password: s.certificate_password ?? '',
          secure_connection_mode: s.secure_connection_mode || 'Disabled',
          enable_upnp: Boolean(s.enable_upnp),
          max_simultaneous_streams: s.max_simultaneous_streams ? String(s.max_simultaneous_streams) : 'Unlimited',
          internet_streaming_bitrate_limit: s.internet_streaming_bitrate_limit !== null && s.internet_streaming_bitrate_limit !== undefined
            ? String(s.internet_streaming_bitrate_limit)
            : '',
          media_server_url: s.media_server_url || s.emby_server_url || DEFAULT_SETTINGS.media_server_url,
          media_server_api_key: s.media_server_api_key || s.emby_api_key || '',
        });
      })
      .catch((err) => {
        console.error('Error al cargar la configuración de red:', err);
        if (isMounted) {
          setFormData(DEFAULT_SETTINGS);
          setToast({
            type: 'error',
            message: 'No se pudo conectar con el servidor para cargar las configuraciones de red. Se cargaron los valores predeterminados.',
          });
        }
      })
      .finally(() => {
        if (isMounted) {
          setLoading(false);
        }
      });

    return () => {
      isMounted = false;
    };
  }, []);

  const handleChange = (field, value) => {
    setFormData((prev) => ({
      ...prev,
      [field]: value,
    }));
  };

  const handleFileSelect = (e) => {
    const file = e.target.files?.[0];
    if (file) {
      handleChange('custom_ssl_cert_path', `/etc/ssl/certs/${file.name}`);
    }
  };

  const handleTestAndSyncMediaServer = async () => {
    if (testingMedia) return;
    setTestingMedia(true);
    setMediaSyncResult(null);

    try {
      const res = await api.post('/admin/media/sync', {
        server_url: formData.media_server_url,
        api_key: formData.media_server_api_key,
      });

      if (res.data?.success) {
        const successMsg = 'Catálogo sincronizado exitosamente con el servidor';
        setToast({
          type: 'success',
          message: successMsg,
        });
        setMediaSyncResult({
          type: 'success',
          message: res.data.message || `${successMsg} (${res.data.total_synced || 0} títulos).`,
        });

        // Actualizar datos del servidor de medios si fueron normalizados
        if (res.data?.server_url) {
          setFormData((prev) => ({
            ...prev,
            media_server_url: res.data.server_url,
          }));
        }

        setTimeout(() => {
          setToast((t) => (t?.message === successMsg ? null : t));
        }, 6000);
      } else {
        const errorMsg = res.data?.message || 'No se pudo comunicar con el servidor remoto. Verifica que la IP y la API Key sean correctas.';
        setMediaSyncResult({
          type: 'error',
          message: errorMsg,
        });
        setToast({
          type: 'error',
          message: errorMsg,
        });
      }
    } catch (err) {
      console.error('Error al sincronizar servidor de medios:', err);
      const errorMsg = err.response?.data?.message || 'No se pudo comunicar con el servidor remoto. Verifica que la IP y la API Key sean correctas.';
      setMediaSyncResult({
        type: 'error',
        message: errorMsg,
      });
      setToast({
        type: 'error',
        message: errorMsg,
      });
    } finally {
      setTestingMedia(false);
    }
  };

  const handleSubmit = async (e) => {
    if (e) e.preventDefault();
    setSaving(true);
    setToast(null);

    try {
      const payload = {
        ...formData,
        local_http_port: parseInt(formData.local_http_port, 10) || 8789,
        local_https_port: parseInt(formData.local_https_port, 10) || 8920,
        public_http_port: parseInt(formData.public_http_port, 10) || 8789,
        public_https_port: parseInt(formData.public_https_port, 10) || 8920,
        allow_remote_connections: Boolean(formData.allow_remote_connections),
        enable_upnp: Boolean(formData.enable_upnp),
        max_simultaneous_streams: String(formData.max_simultaneous_streams),
        internet_streaming_bitrate_limit: formData.internet_streaming_bitrate_limit !== '' ? String(formData.internet_streaming_bitrate_limit) : '',
      };

      let res;
      try {
        res = await api.post('/admin/network-settings', payload);
      } catch (err) {
        res = await api.post('/admin/network', payload);
      }

      if (res.data?.settings) {
        setFormData((prev) => ({
          ...prev,
          ...res.data.settings,
        }));
      }

      setToast({
        type: 'success',
        message: 'Configuración de red guardada exitosamente.',
      });

      setTimeout(() => {
        setToast((t) => (t?.type === 'success' ? null : t));
      }, 5000);
    } catch (err) {
      console.error('Error al guardar la configuración de red:', err);
      const msg = err.response?.data?.message || 'Error al guardar la configuración. Revisa los valores ingresados.';
      setToast({
        type: 'error',
        message: msg,
      });
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="w-full min-h-[60vh] flex flex-col items-center justify-center space-y-4">
        <div className="w-10 h-10 border-3 border-[#E50914] border-t-transparent rounded-full animate-spin" />
        <p className="text-sm font-medium text-zinc-500 dark:text-zinc-400">
          Cargando configuración de red de FlixHN...
        </p>
      </div>
    );
  }

  return (
    <div className="w-full px-4 sm:px-8 py-6 space-y-6 bg-zinc-50 dark:bg-[#141414] text-zinc-900 dark:text-white transition-colors duration-200">
      {/* Toast de Notificación */}
      {toast && (
        <div
          className={`w-full p-4 rounded-xl border flex items-center justify-between shadow-lg transition-all animate-fadeIn ${
            toast.type === 'success'
              ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-700 dark:text-emerald-400 dark:bg-emerald-950/80'
              : 'bg-red-500/10 border-red-500/30 text-red-700 dark:text-red-400 dark:bg-red-950/80'
          }`}
        >
          <div className="flex items-center gap-3">
            {toast.type === 'success' ? (
              <CheckCircle2 className="w-5 h-5 text-emerald-500 flex-shrink-0" />
            ) : (
              <AlertCircle className="w-5 h-5 text-[#E50914] flex-shrink-0" />
            )}
            <span className="text-sm font-medium">{toast.message}</span>
          </div>
          <button
            type="button"
            onClick={() => setToast(null)}
            className="text-xs font-semibold hover:underline opacity-80 hover:opacity-100 cursor-pointer ml-4"
          >
            Cerrar
          </button>
        </div>
      )}

      {/* Cabecera Principal del Módulo */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-zinc-200 dark:border-zinc-800">
        <div className="flex items-center gap-3">
          <div className="p-2.5 rounded-xl bg-[#E50914]/10 text-[#E50914] border border-[#E50914]/20">
            <Network className="w-6 h-6" />
          </div>
          <div>
            <h1 className="text-xl sm:text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
              Red / Conexiones
            </h1>
            <p className="text-xs sm:text-sm text-zinc-500 dark:text-zinc-400">
              Configuración de interfaces, puertos, acceso remoto, proxy y directivas de seguridad para el servidor FlixHN.
            </p>
          </div>
        </div>

        {/* Botón Guardar Superior */}
        <button
          type="button"
          onClick={handleSubmit}
          disabled={saving}
          className="self-start sm:self-auto flex items-center justify-center gap-2 px-6 py-2.5 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#F40612] transition-all shadow-md hover:shadow-red-900/30 cursor-pointer disabled:opacity-50 active:scale-95"
        >
          {saving ? (
            <>
              <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
              <span>Guardando...</span>
            </>
          ) : (
            <>
              <Save className="w-4 h-4" />
              <span>Guardar Configuración</span>
            </>
          )}
        </button>
      </div>

      {/* Formulario Full Width */}
      <form onSubmit={handleSubmit} className="w-full space-y-6">
        {/* Contenedor Principal de Campos */}
        <div className="w-full bg-white dark:bg-[#181818] border border-zinc-200 dark:border-zinc-800 rounded-2xl p-6 sm:p-8 shadow-sm space-y-8">

          {/* SECCIÓN DESTACADA: Servidor de Medios y Contenidos (IP y Token de Acceso) */}
          <div className="p-5 rounded-2xl bg-zinc-50 dark:bg-[#1c1c1c] border border-zinc-200 dark:border-zinc-800 space-y-5">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-zinc-200 dark:border-zinc-800/80">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-xl bg-[#E50914] text-white shadow-md">
                  <Server className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-base font-bold text-zinc-900 dark:text-white">
                    Servidor de Contenidos Multimedia (IP / Token de Red)
                  </h3>
                  <p className="text-xs text-zinc-500 dark:text-zinc-400">
                    Dirección IP del nodo de almacenamiento y credencial de autorización para sincronizar el catálogo.
                  </p>
                </div>
              </div>

              <button
                type="button"
                onClick={handleTestAndSyncMediaServer}
                disabled={testingMedia}
                className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#F40612] disabled:opacity-50 transition-all shadow-md active:scale-95 cursor-pointer"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${testingMedia ? 'animate-spin' : ''}`} />
                <span>{testingMedia ? 'Conectando...' : 'Probar y Sincronizar Catálogo'}</span>
              </button>
            </div>

            {/* Alerta de resultado de sincronización de medios */}
            {mediaSyncResult && (
              <div
                className={`p-3.5 rounded-xl border text-xs font-medium flex items-center justify-between ${
                  mediaSyncResult.type === 'success'
                    ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-700 dark:text-emerald-400'
                    : mediaSyncResult.type === 'warning'
                    ? 'bg-amber-500/10 border-amber-500/30 text-amber-700 dark:text-amber-400'
                    : 'bg-red-500/10 border-red-500/30 text-red-700 dark:text-red-400'
                }`}
              >
                <div className="flex items-center gap-2">
                  <span className="font-bold uppercase tracking-wider">
                    {mediaSyncResult.type === 'success' ? 'Éxito:' : 'Aviso:'}
                  </span>
                  <span>{mediaSyncResult.message}</span>
                </div>
                <button
                  type="button"
                  onClick={() => setMediaSyncResult(null)}
                  className="font-bold hover:underline cursor-pointer ml-3"
                >
                  Cerrar
                </button>
              </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
              {/* URL / IP del Servidor */}
              <div className="space-y-1.5">
                <label
                  htmlFor="media_server_url"
                  className="block text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300"
                >
                  IP y Puerto del Servidor de Medios
                </label>
                <input
                  type="text"
                  id="media_server_url"
                  value={formData.media_server_url}
                  onChange={(e) => handleChange('media_server_url', e.target.value)}
                  placeholder="ej. http://45.4.87.126:6789 o http://65.187.110.22:6789"
                  className="w-full px-4 py-2.5 text-sm font-mono rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 dark:placeholder-zinc-600 focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all"
                />
                <p className="text-[11px] text-zinc-500 dark:text-zinc-400">
                  Dirección URL o IP pública/LAN y puerto donde está alojado el servidor de contenido multimedia.
                </p>
              </div>

              {/* API Key o Token de Acceso */}
              <div className="space-y-1.5">
                <label
                  htmlFor="media_server_api_key"
                  className="block text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300"
                >
                  API Key / Token de Acceso (Autorización HTTP)
                </label>
                <div className="relative">
                  <input
                    type={showApiKey ? 'text' : 'password'}
                    id="media_server_api_key"
                    value={formData.media_server_api_key}
                    onChange={(e) => handleChange('media_server_api_key', e.target.value)}
                    placeholder="Introduce el Token o API Key generado en el servidor"
                    className="w-full pl-4 pr-11 py-2.5 text-sm font-mono rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 dark:placeholder-zinc-600 focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all"
                  />
                  <button
                    type="button"
                    onClick={() => setShowApiKey(!showApiKey)}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-600 dark:hover:text-white transition-colors cursor-pointer"
                  >
                    {showApiKey ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
                <p className="text-[11px] text-zinc-500 dark:text-zinc-400">
                  Token necesario para autorizar las solicitudes y evitar respuestas HTTP 401 Unauthorized.
                </p>
              </div>
            </div>
          </div>

          {/* SECCIÓN MULTI-SERVIDOR: Clúster de Nodos de Streaming (Punto 9) */}
          <div className="p-5 rounded-2xl bg-zinc-50 dark:bg-[#1c1c1c] border border-zinc-200 dark:border-zinc-800 space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-zinc-200 dark:border-zinc-800/80">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-xl bg-amber-500/20 text-amber-500 border border-amber-500/30 shadow-md">
                  <Layers className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-base font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                    <span>Clúster Multi-Servidor (Nodos de Streaming)</span>
                    <span className="text-[10px] uppercase tracking-wider font-extrabold px-2 py-0.5 rounded-full bg-red-600/10 text-red-500 border border-red-500/20">
                      {nodes.length} {nodes.length === 1 ? 'Nodo' : 'Nodos'}
                    </span>
                  </h3>
                  <p className="text-xs text-zinc-500 dark:text-zinc-400">
                    Administra servidores de medios remotos o secundarios, sincroniza catálogos independientes y conmuta nodos activos.
                  </p>
                </div>
              </div>

              <button
                type="button"
                onClick={() => setShowAddNodeModal(true)}
                className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#F40612] transition-all shadow-md active:scale-95 cursor-pointer"
              >
                <Plus className="w-4 h-4" />
                <span>+ Conectar Nuevo Servidor</span>
              </button>
            </div>

            {/* Lista de Nodos */}
            {loadingNodes ? (
              <div className="py-8 flex items-center justify-center gap-3 text-zinc-400 text-xs">
                <Loader2 className="w-4 h-4 animate-spin text-[#E50914]" />
                <span>Cargando nodos del clúster...</span>
              </div>
            ) : nodes.length === 0 ? (
              <div className="py-6 text-center text-xs text-zinc-500 dark:text-zinc-400 border border-dashed border-zinc-300 dark:border-zinc-800 rounded-xl bg-white/40 dark:bg-black/20">
                No hay servidores remotos secundarios configurados. Haz clic en <strong>"+ Conectar Nuevo Servidor"</strong> para vincular un nuevo nodo.
              </div>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-1">
                {nodes.map((node) => {
                  const isActive = !!node.is_active;
                  const isActing = actionNodeId === node.id;
                  return (
                    <div
                      key={node.id}
                      className={`p-4 rounded-xl border transition-all flex flex-col justify-between gap-3 ${
                        isActive
                          ? 'bg-red-500/5 dark:bg-red-950/20 border-red-500/40 ring-1 ring-red-500/20'
                          : 'bg-white dark:bg-[#232323] border-zinc-200 dark:border-zinc-800'
                      }`}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <div className="space-y-1">
                          <div className="flex items-center gap-2">
                            <span className="font-bold text-sm text-zinc-900 dark:text-white">
                              {node.name}
                            </span>
                            {isActive && (
                              <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">
                                Activo
                              </span>
                            )}
                            {node.is_master && (
                              <span className="text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-md bg-zinc-200 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300">
                                Maestro
                              </span>
                            )}
                          </div>
                          <p className="text-xs font-mono text-zinc-500 dark:text-zinc-400">
                            {node.ip_address}:{node.port}
                          </p>
                        </div>

                        {/* Botón Eliminar */}
                        <button
                          type="button"
                          onClick={() => handleDeleteNode(node)}
                          className="p-1.5 text-zinc-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg transition-colors cursor-pointer"
                          title="Desvincular servidor"
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      </div>

                      {/* Botones de acción */}
                      <div className="flex items-center gap-2 pt-2 border-t border-zinc-100 dark:border-zinc-800/80">
                        {!isActive ? (
                          <button
                            type="button"
                            onClick={() => handleActivateNode(node)}
                            disabled={isActing}
                            className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-zinc-700 dark:text-zinc-200 bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 transition-all cursor-pointer active:scale-95 disabled:opacity-50"
                          >
                            <Power className="w-3 h-3 text-emerald-500" />
                            <span>Alternar / Activar</span>
                          </button>
                        ) : (
                          <span className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/20">
                            <Check className="w-3 h-3" />
                            <span>Nodo en Uso</span>
                          </span>
                        )}

                        <button
                          type="button"
                          onClick={() => handleSyncNode(node)}
                          disabled={isActing}
                          className="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-white bg-zinc-800 hover:bg-zinc-700 dark:bg-zinc-700 dark:hover:bg-zinc-600 transition-all cursor-pointer active:scale-95 disabled:opacity-50"
                          title="Sincronizar catálogo independiente de este nodo"
                        >
                          <RefreshCw className={`w-3 h-3 ${isActing ? 'animate-spin' : ''}`} />
                          <span>Sincronizar</span>
                        </button>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>

          {/* 1. LAN networks */}
          <div className="space-y-2">
            <label
              htmlFor="lan_networks"
              className="block text-sm font-semibold text-zinc-900 dark:text-white"
            >
              LAN networks
            </label>
            <input
              type="text"
              id="lan_networks"
              value={formData.lan_networks}
              onChange={(e) => handleChange('lan_networks', e.target.value)}
              className="w-full px-4 py-2.5 text-sm font-mono rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 dark:placeholder-zinc-600 focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all"
            />
            <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
              Comma separated list of IP addresses or IP/netmask entries for networks that will be considered on local network when enforcing bandwidth restrictions. If set, all other IP addresses will be considered to be on the external network and will be subject to the external bandwidth restrictions. If left blank, only the server's subnet and common private IP subnets (10.0.0.0/8, 192.168.0.0/24, etc.) are considered to be on the local network.
            </p>
          </div>

          {/* 2. Local IP address */}
          <div className="space-y-2">
            <label
              htmlFor="local_ip_address"
              className="block text-sm font-semibold text-zinc-900 dark:text-white"
            >
              Local IP address
            </label>
            <input
              type="text"
              id="local_ip_address"
              value={formData.local_ip_address}
              onChange={(e) => handleChange('local_ip_address', e.target.value)}
              placeholder="Opcional"
              className="w-full px-4 py-2.5 text-sm font-mono rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 dark:placeholder-zinc-600 focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all"
            />
            <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
              Optional. Override the local IP address that FlixHN Server will present to apps. If left blank, the server will automatically detect the local IP address.
            </p>
          </div>

          {/* Grid de 2 columnas para Puertos Locales */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            {/* 3. Local http port number */}
            <div className="space-y-2">
              <label
                htmlFor="local_http_port"
                className="block text-sm font-semibold text-zinc-900 dark:text-white"
              >
                Local http port number
              </label>
              <input
                type="number"
                id="local_http_port"
                value={formData.local_http_port}
                onChange={(e) => handleChange('local_http_port', e.target.value)}
                className="w-full px-4 py-2.5 text-sm font-mono rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all"
              />
              <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
                The tcp port number that FlixHN's http server should bind to.
              </p>
            </div>

            {/* 4. Local https port number */}
            <div className="space-y-2">
              <label
                htmlFor="local_https_port"
                className="block text-sm font-semibold text-zinc-900 dark:text-white"
              >
                Local https port number
              </label>
              <input
                type="number"
                id="local_https_port"
                value={formData.local_https_port}
                onChange={(e) => handleChange('local_https_port', e.target.value)}
                className="w-full px-4 py-2.5 text-sm font-mono rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all"
              />
              <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
                The tcp port number that FlixHN's https server should bind to.
              </p>
            </div>
          </div>

          {/* 5. Allow remote connections to this FlixHN Server */}
          <div className="p-4 rounded-xl bg-zinc-50 dark:bg-[#232323]/50 border border-zinc-200 dark:border-zinc-800 flex items-start gap-3.5">
            <div className="pt-0.5">
              <input
                type="checkbox"
                id="allow_remote_connections"
                checked={formData.allow_remote_connections}
                onChange={(e) => handleChange('allow_remote_connections', e.target.checked)}
                className="w-4 h-4 rounded text-[#E50914] focus:ring-[#E50914] accent-[#E50914] cursor-pointer"
              />
            </div>
            <div className="flex-1 space-y-1">
              <label
                htmlFor="allow_remote_connections"
                className="text-sm font-semibold text-zinc-900 dark:text-white cursor-pointer select-none"
              >
                Allow remote connections to this FlixHN Server
              </label>
              <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
                If unchecked, all remote connections will be blocked.
              </p>
            </div>
          </div>

          {/* 6. Remote IP address filter */}
          <div className="space-y-2">
            <label
              htmlFor="remote_ip_filter"
              className="block text-sm font-semibold text-zinc-900 dark:text-white"
            >
              Remote IP address filter
            </label>
            <input
              type="text"
              id="remote_ip_filter"
              value={formData.remote_ip_filter}
              onChange={(e) => handleChange('remote_ip_filter', e.target.value)}
              className="w-full px-4 py-2.5 text-sm font-mono rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 dark:placeholder-zinc-600 focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all"
            />
            <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
              Comma separated list of IP addresses or IP/netmask entries for networks that will be allowed to connect remotely. If left blank, all remote addresses will be allowed.
            </p>
          </div>

          {/* 7. Remote IP address filter mode */}
          <div className="space-y-2">
            <label
              htmlFor="remote_ip_filter_mode"
              className="block text-sm font-semibold text-zinc-900 dark:text-white"
            >
              Remote IP address filter mode
            </label>
            <select
              id="remote_ip_filter_mode"
              value={formData.remote_ip_filter_mode}
              onChange={(e) => handleChange('remote_ip_filter_mode', e.target.value)}
              className="w-full px-4 py-2.5 text-sm rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all cursor-pointer"
            >
              <option value="Whitelist">Whitelist</option>
              <option value="Blacklist">Blacklist</option>
            </select>
          </div>

          {/* Grid de 2 columnas para Puertos Públicos */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            {/* 8. Public http port number */}
            <div className="space-y-2">
              <label
                htmlFor="public_http_port"
                className="block text-sm font-semibold text-zinc-900 dark:text-white"
              >
                Public http port number
              </label>
              <input
                type="number"
                id="public_http_port"
                value={formData.public_http_port}
                onChange={(e) => handleChange('public_http_port', e.target.value)}
                className="w-full px-4 py-2.5 text-sm font-mono rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all"
              />
              <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
                The public port number that should be mapped to the local http port.
              </p>
            </div>

            {/* 9. Public https port number */}
            <div className="space-y-2">
              <label
                htmlFor="public_https_port"
                className="block text-sm font-semibold text-zinc-900 dark:text-white"
              >
                Public https port number
              </label>
              <input
                type="number"
                id="public_https_port"
                value={formData.public_https_port}
                onChange={(e) => handleChange('public_https_port', e.target.value)}
                className="w-full px-4 py-2.5 text-sm font-mono rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all"
              />
              <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
                The public port number that should be mapped to the local https port.
              </p>
            </div>
          </div>

          {/* 10. External domain */}
          <div className="space-y-2">
            <label
              htmlFor="external_domain"
              className="block text-sm font-semibold text-zinc-900 dark:text-white"
            >
              External domain
            </label>
            <input
              type="text"
              id="external_domain"
              value={formData.external_domain}
              onChange={(e) => handleChange('external_domain', e.target.value)}
              placeholder="mydomain.com"
              className="w-full px-4 py-2.5 text-sm font-mono rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 dark:placeholder-zinc-600 focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all"
            />
            <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
              If you have a dynamic DNS or domain name, enter it here, without protocol or port. FlixHN apps will use it when connecting remotely. This field is required when used with a custom ssl certificate. Example: mydomain.com.
            </p>
          </div>

          {/* 11. Read proxy headers to determine client IP addresses */}
          <div className="space-y-2">
            <label
              htmlFor="read_proxy_headers"
              className="block text-sm font-semibold text-zinc-900 dark:text-white"
            >
              Read proxy headers to determine client IP addresses
            </label>
            <select
              id="read_proxy_headers"
              value={formData.read_proxy_headers}
              onChange={(e) => handleChange('read_proxy_headers', e.target.value)}
              className="w-full px-4 py-2.5 text-sm rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all cursor-pointer"
            >
              <option value="Only when they contain remote network addresses">
                Only when they contain remote network addresses
              </option>
              <option value="Always">Always</option>
              <option value="Disabled">Disabled</option>
            </select>
            <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
              Determines if request headers such as X-Real-Ip and X-Forwarded-For should be used to determine the IP address of connecting devices.
            </p>
          </div>

          {/* 12. Custom ssl certificate path */}
          <div className="space-y-2">
            <label
              htmlFor="custom_ssl_cert_path"
              className="block text-sm font-semibold text-zinc-900 dark:text-white"
            >
              Custom ssl certificate path
            </label>
            <div className="flex items-center gap-2">
              <input
                type="text"
                id="custom_ssl_cert_path"
                value={formData.custom_ssl_cert_path}
                onChange={(e) => handleChange('custom_ssl_cert_path', e.target.value)}
                placeholder="/etc/ssl/certs/cert.pfx"
                className="flex-1 px-4 py-2.5 text-sm font-mono rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 dark:placeholder-zinc-600 focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all"
              />
              <input
                type="file"
                ref={fileInputRef}
                onChange={handleFileSelect}
                className="hidden"
                accept=".pfx,.p12,.crt,.pem,.cer"
              />
              <button
                type="button"
                onClick={() => fileInputRef.current?.click()}
                className="px-3.5 py-2.5 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-100 hover:bg-zinc-200 dark:bg-[#232323] dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white flex items-center gap-1.5 text-xs font-semibold transition-colors cursor-pointer"
                title="Examinar archivo de certificado"
              >
                <FolderOpen className="w-4 h-4 text-[#E50914]" />
                <span className="hidden sm:inline">Examinar</span>
              </button>
            </div>
            <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
              Path to a PKCS #12 file containing a certificate and private key to enable TLS support on a custom domain.
            </p>
          </div>

          {/* 13. Certificate password */}
          <div className="space-y-2">
            <label
              htmlFor="certificate_password"
              className="block text-sm font-semibold text-zinc-900 dark:text-white"
            >
              Certificate password
            </label>
            <div className="relative">
              <input
                type={showPassword ? 'text' : 'password'}
                id="certificate_password"
                value={formData.certificate_password}
                onChange={(e) => handleChange('certificate_password', e.target.value)}
                className="w-full px-4 py-2.5 pr-11 text-sm font-mono rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 dark:placeholder-zinc-600 focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all"
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 p-1 cursor-pointer"
                title={showPassword ? 'Ocultar contraseña' : 'Ver contraseña'}
              >
                {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
              </button>
            </div>
            <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
              If your certificate requires a password, please enter it here.
            </p>
          </div>

          {/* 14. Secure connection mode */}
          <div className="space-y-2">
            <label
              htmlFor="secure_connection_mode"
              className="block text-sm font-semibold text-zinc-900 dark:text-white"
            >
              Secure connection mode
            </label>
            <select
              id="secure_connection_mode"
              value={formData.secure_connection_mode}
              onChange={(e) => handleChange('secure_connection_mode', e.target.value)}
              className="w-full px-4 py-2.5 text-sm rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all cursor-pointer"
            >
              <option value="Disabled">Disabled</option>
              <option value="Required for all">Required for all</option>
              <option value="Required for remote access only">Required for remote access only</option>
            </select>
          </div>

          {/* 15. Enable automatic port mapping */}
          <div className="p-4 rounded-xl bg-zinc-50 dark:bg-[#232323]/50 border border-zinc-200 dark:border-zinc-800 flex items-start gap-3.5">
            <div className="pt-0.5">
              <input
                type="checkbox"
                id="enable_upnp"
                checked={formData.enable_upnp}
                onChange={(e) => handleChange('enable_upnp', e.target.checked)}
                className="w-4 h-4 rounded text-[#E50914] focus:ring-[#E50914] accent-[#E50914] cursor-pointer"
              />
            </div>
            <div className="flex-1 space-y-1">
              <label
                htmlFor="enable_upnp"
                className="text-sm font-semibold text-zinc-900 dark:text-white cursor-pointer select-none"
              >
                Enable automatic port mapping
              </label>
              <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
                Attempt to automatically map the public port to the local port via UPnP. This may not work with some router models.
              </p>
            </div>
          </div>

          {/* 16. Max simultaneous video streams */}
          <div className="space-y-2">
            <label
              htmlFor="max_simultaneous_streams"
              className="block text-sm font-semibold text-zinc-900 dark:text-white"
            >
              Max simultaneous video streams
            </label>
            <select
              id="max_simultaneous_streams"
              value={formData.max_simultaneous_streams}
              onChange={(e) => handleChange('max_simultaneous_streams', e.target.value)}
              className="w-full px-4 py-2.5 text-sm rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all cursor-pointer"
            >
              <option value="Unlimited">Unlimited</option>
              <option value="10">10</option>
              <option value="25">25</option>
              <option value="50">50</option>
              <option value="100">100</option>
              <option value="250">250</option>
            </select>
            <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
              Limit the number of concurrent video playback sessions.
            </p>
          </div>

          {/* 17. Internet streaming bitrate limit (Mbps) */}
          <div className="space-y-2">
            <label
              htmlFor="internet_streaming_bitrate_limit"
              className="block text-sm font-semibold text-zinc-900 dark:text-white"
            >
              Internet streaming bitrate limit (Mbps)
            </label>
            <input
              type="text"
              id="internet_streaming_bitrate_limit"
              value={formData.internet_streaming_bitrate_limit}
              onChange={(e) => handleChange('internet_streaming_bitrate_limit', e.target.value)}
              placeholder="Sin límite"
              className="w-full px-4 py-2.5 text-sm font-mono rounded-xl bg-white dark:bg-[#232323] border border-zinc-200 dark:border-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 dark:placeholder-zinc-600 focus:outline-none focus:ring-2 focus:ring-[#E50914] focus:border-[#E50914] transition-all"
            />
            <p className="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
              An optional per-stream bitrate limit for all out of network devices. This is useful to prevent devices from requesting a higher bitrate than your internet connection can handle. This may result in increased CPU load on your server in order to transcode videos on the fly to a lower bitrate.
            </p>
          </div>

        </div>

        {/* Barra de Guardado Inferior Fija / Estilizada */}
        <div className="sticky bottom-4 z-20 w-full p-4 rounded-2xl bg-white/95 dark:bg-[#181818]/95 backdrop-blur-md border border-zinc-200 dark:border-zinc-800 shadow-xl flex flex-col sm:flex-row items-center justify-between gap-4 transition-all">
          <div className="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
            <Network className="w-4 h-4 text-[#E50914] flex-shrink-0" />
            <span>Módulo de Configuración de Red FlixHN</span>
          </div>

          <div className="flex items-center gap-3 w-full sm:w-auto">
            <button
              type="button"
              onClick={() => setFormData(DEFAULT_SETTINGS)}
              className="flex-1 sm:flex-initial flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-xs font-semibold bg-zinc-100 hover:bg-zinc-200 dark:bg-[#232323] dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-700 transition-all cursor-pointer active:scale-95"
            >
              <RotateCcw className="w-3.5 h-3.5 text-zinc-400" />
              <span>Valores por Defecto</span>
            </button>

            <button
              type="submit"
              disabled={saving}
              className="flex-1 sm:flex-initial flex items-center justify-center gap-2 px-6 py-2.5 rounded-xl text-xs font-bold text-white bg-[#E50914] hover:bg-[#F40612] transition-all shadow-md hover:shadow-red-900/30 cursor-pointer disabled:opacity-50 active:scale-95"
            >
              {saving ? (
                <>
                  <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                  <span>Guardando...</span>
                </>
              ) : (
                <>
                  <Save className="w-4 h-4" />
                  <span>Guardar Configuración</span>
                </>
              )}
            </button>
          </div>
        </div>
      </form>

      {/* Modal Conectar Nuevo Servidor (Punto 9) */}
      {showAddNodeModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4 animate-in fade-in duration-200">
          <div className="w-full max-w-md bg-white dark:bg-[#1a1a1a] rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-2xl overflow-hidden p-6 space-y-5">
            <div className="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-xl bg-red-600/10 text-[#E50914] border border-red-600/20">
                  <Server className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-base font-bold text-zinc-900 dark:text-white">
                    Conectar Nuevo Servidor
                  </h3>
                  <p className="text-xs text-zinc-500 dark:text-zinc-400">
                    Añade un nodo remoto de streaming al clúster FlixHN
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setShowAddNodeModal(false)}
                className="text-zinc-400 hover:text-white transition-colors p-1 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleAddNode} className="space-y-4">
              <div className="space-y-1.5">
                <label className="block text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">
                  Nombre o Alias del Servidor *
                </label>
                <input
                  type="text"
                  required
                  placeholder="Ej: Nodo-Secundario-SPS"
                  value={newNode.name}
                  onChange={(e) => setNewNode({ ...newNode, name: e.target.value })}
                  className="w-full px-3.5 py-2 text-sm rounded-xl bg-zinc-50 dark:bg-[#242424] border border-zinc-300 dark:border-zinc-700 text-zinc-900 dark:text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-[#E50914]"
                />
              </div>

              <div className="grid grid-cols-3 gap-3">
                <div className="col-span-2 space-y-1.5">
                  <label className="block text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">
                    Dirección IP / Host *
                  </label>
                  <input
                    type="text"
                    required
                    placeholder="192.168.1.100 ó dominio"
                    value={newNode.ip_address}
                    onChange={(e) => setNewNode({ ...newNode, ip_address: e.target.value })}
                    className="w-full px-3.5 py-2 text-sm font-mono rounded-xl bg-zinc-50 dark:bg-[#242424] border border-zinc-300 dark:border-zinc-700 text-zinc-900 dark:text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-[#E50914]"
                  />
                </div>
                <div className="space-y-1.5">
                  <label className="block text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">
                    Puerto *
                  </label>
                  <input
                    type="number"
                    required
                    value={newNode.port}
                    onChange={(e) => setNewNode({ ...newNode, port: parseInt(e.target.value) || 6789 })}
                    className="w-full px-3.5 py-2 text-sm font-mono rounded-xl bg-zinc-50 dark:bg-[#242424] border border-zinc-300 dark:border-zinc-700 text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-[#E50914]"
                  />
                </div>
              </div>

              <div className="space-y-1.5">
                <label className="block text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">
                  API Key / Token de Acceso
                </label>
                <input
                  type="text"
                  placeholder="Introduce el token de autenticación (opcional)"
                  value={newNode.api_key}
                  onChange={(e) => setNewNode({ ...newNode, api_key: e.target.value })}
                  className="w-full px-3.5 py-2 text-sm font-mono rounded-xl bg-zinc-50 dark:bg-[#242424] border border-zinc-300 dark:border-zinc-700 text-zinc-900 dark:text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-[#E50914]"
                />
              </div>

              <div className="flex items-center justify-end gap-3 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                <button
                  type="button"
                  onClick={() => setShowAddNodeModal(false)}
                  className="px-4 py-2 text-xs font-semibold rounded-xl bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 transition-colors cursor-pointer"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={addingNode}
                  className="inline-flex items-center gap-2 px-5 py-2 text-xs font-bold text-white bg-[#E50914] hover:bg-[#F40612] rounded-xl shadow-md transition-all cursor-pointer disabled:opacity-50 active:scale-95"
                >
                  {addingNode && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
                  <span>{addingNode ? 'Vinculando...' : 'Conectar Servidor'}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default NetworkSettings;
