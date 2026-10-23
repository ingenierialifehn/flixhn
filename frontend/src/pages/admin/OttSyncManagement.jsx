import React, { useState, useEffect, useCallback, useMemo } from 'react';
import api from '../../services/api';
import {
  DownloadCloud,
  RefreshCw,
  CheckCircle2,
  AlertCircle,
  AlertTriangle,
  X,
  KeyRound,
  ShieldCheck,
  Zap,
  HardDrive,
  Film,
  Tv,
  Play,
  RotateCcw,
  Trash2,
  ExternalLink,
  Eye,
  EyeOff,
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
  FolderPlus,
} from 'lucide-react';
import FolderPickerModal from './FolderPickerModal';

/* =========================================================================
   LOGOS OFICIALES EN BADGES VECTORIALES NÍTITOS (#141414)
   ========================================================================= */

// Netflix: Vector SVG oficial de NETFLIX en rojo #E50914 sobre fondo #141414 nítido sin recortes
const NetflixLogo = () => (
  <div className="flex items-center justify-center px-2 py-0.5 select-none">
    <svg className="w-24 h-7" viewBox="0 0 111 30" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path
        d="M105.062 14.281L111 30c-1.75-.25-3.5-.563-5.281-.844l-3.344-8.688-3.438 7.969c-1.688-.281-3.344-.375-5.031-.594l6.031-13.75L94.469 0h5.062l3.063 7.874L105.875 0H111l-5.938 14.281zM90.469 0h-4.594v27.25c1.5.094 3.063.156 4.594.344V0zM81.906 26.938c-4.188-.281-8.375-.531-12.656-.625V0h4.688v21.875c2.688.063 5.375.281 7.969.406v4.656zM64.406 0v4.75H57.75v6.594h6.094v4.563H57.75v10.156c-1.594-.063-3.188-.063-4.781-.063V0h11.438zM48.219 4.75V0H32.656v4.75h5.344v21.156c1.594 0 3.219.063 4.813.125V4.75h5.406zM28.094 0v4.75H20.75v6.281h6.688v4.594H20.75v11.188c-1.75.063-3.469.156-5.25.313V0h12.594zM10.875 14.656L10.781 0H5.875v27.75c1.781.188 3.563.344 5.344.563l4.75-13.656zM0 27.188V0h5.094l4.906 14.156V0h5.094v28c-1.688-.219-3.375-.375-5.094-.563L5.094 13.5v13.938C3.375 27.344 1.688 27.25 0 27.188z"
        fill="#E50914"
      />
    </svg>
  </div>
);

// Disney+: "Disney+" en blanco con arco distintivo sobre fondo azul marino profundo (#0c1b33)
const DisneyPlusLogo = () => (
  <div className="flex items-center justify-center px-2.5 py-0.5 bg-[#0c1b33] rounded-md border border-blue-900/50 shadow-sm select-none">
    <svg className="w-24 h-7" viewBox="0 0 110 32" fill="none" xmlns="http://www.w3.org/2000/svg">
      {/* Arco distintivo de Disney */}
      <path d="M10 26C28 6 64 5 95 15" stroke="#0063E5" strokeWidth="2.5" strokeLinecap="round" />
      <text
        x="45"
        y="23"
        textAnchor="middle"
        fill="#FFFFFF"
        fontWeight="800"
        fontSize="18"
        fontFamily="'Palatino Linotype', 'Georgia', serif"
        fontStyle="italic"
        letterSpacing="0.5"
      >
        Disney
      </text>
      {/* Signo más oficial */}
      <path d="M92 14H98V8H102V14H108V18H102V24H98V18H92V14Z" fill="#0063E5" />
    </svg>
  </div>
);

// Max (HBO): "MAX" o "HBO Max" en blanco / morado oficial (#5822b4)
const HboMaxLogo = () => (
  <div className="flex items-center justify-center px-2.5 py-0.5 bg-[#5822b4]/20 rounded-md border border-[#5822b4]/40 select-none">
    <svg className="w-24 h-7" viewBox="0 0 110 28" fill="none" xmlns="http://www.w3.org/2000/svg">
      <text
        x="26"
        y="21"
        textAnchor="middle"
        fill="#FFFFFF"
        fontWeight="900"
        fontSize="17"
        fontFamily="'Arial Black', 'Impact', sans-serif"
        letterSpacing="1"
      >
        HBO
      </text>
      <rect x="52" y="6" width="2" height="16" fill="#5822b4" rx="1" />
      <text
        x="82"
        y="21"
        textAnchor="middle"
        fill="#FFFFFF"
        fontWeight="900"
        fontSize="18"
        fontFamily="'Arial Black', 'Impact', sans-serif"
        letterSpacing="1.5"
      >
        MAX
      </text>
    </svg>
  </div>
);

// Amazon Prime Video: "prime video" en blanco/azul cielo (#00A8E1) con arco sonriente oficial
const PrimeVideoLogo = () => (
  <div className="flex items-center justify-center px-2 py-0.5 select-none">
    <svg className="w-26 h-7" viewBox="0 0 120 30" fill="none" xmlns="http://www.w3.org/2000/svg">
      <text
        x="36"
        y="18"
        textAnchor="middle"
        fill="#FFFFFF"
        fontWeight="800"
        fontSize="16"
        fontFamily="'Amazon Ember', 'Arial', sans-serif"
        letterSpacing="-0.3"
      >
        prime
      </text>
      <text
        x="82"
        y="18"
        textAnchor="middle"
        fill="#00A8E1"
        fontWeight="600"
        fontSize="15"
        fontFamily="'Amazon Ember', 'Arial', sans-serif"
        letterSpacing="0.2"
      >
        video
      </text>
      {/* Sonrisa/Flecha oficial Prime */}
      <path d="M22 23C42 28 75 28 98 22" stroke="#00A8E1" strokeWidth="2.2" strokeLinecap="round" />
      <path d="M95 19.5L100 22.2L95.5 25.5" fill="#00A8E1" stroke="#00A8E1" strokeWidth="0.8" strokeLinejoin="round" />
    </svg>
  </div>
);

// Apple TV+: "tv+" en blanco con manzana y borde sutil
const AppleTvLogo = () => (
  <div className="flex items-center justify-center px-2.5 py-0.5 bg-zinc-900 rounded-md border border-zinc-700/60 select-none">
    <svg className="w-22 h-7" viewBox="0 0 100 28" fill="none" xmlns="http://www.w3.org/2000/svg">
      {/* Icono Apple */}
      <path
        d="M25.5 13.8C25.5 10.9 27.8 9.5 27.9 9.4C26.6 7.5 24.6 7.2 23.9 7.1C22.2 6.9 20.6 8.1 19.7 8.1C18.8 8.1 17.5 7.1 16.1 7.1C14.3 7.1 12.6 8.2 11.6 9.8C9.7 13.2 11.1 18.2 12.9 20.8C13.8 22.1 14.8 23.5 16.2 23.4C17.6 23.3 18.2 22.5 19.8 22.5C21.4 22.5 21.9 23.4 23.4 23.4C24.9 23.4 25.8 22.1 26.7 20.8C27.7 19.3 28.1 17.8 28.2 17.7C28.1 17.6 25.5 16.6 25.5 13.8Z"
        fill="#FFFFFF"
      />
      <path
        d="M23.1 5.3C23.8 4.4 24.3 3.2 24.2 2C23.1 2 21.8 2.7 21.1 3.6C20.4 4.4 19.9 5.6 20.1 6.8C21.3 6.9 22.5 6.1 23.1 5.3Z"
        fill="#FFFFFF"
      />
      <text
        x="56"
        y="20"
        fill="#FFFFFF"
        fontWeight="700"
        fontSize="17"
        fontFamily="-apple-system, BlinkMacSystemFont, 'SF Pro Display', sans-serif"
      >
        tv
      </text>
      <text
        x="75"
        y="16"
        fill="#FFFFFF"
        fontWeight="800"
        fontSize="18"
        fontFamily="-apple-system, BlinkMacSystemFont, sans-serif"
      >
        +
      </text>
    </svg>
  </div>
);

// Paramount+: "Paramount+" en azul (#0064FF)
const ParamountPlusLogo = () => (
  <div className="flex items-center justify-center px-2.5 py-0.5 bg-[#0064FF]/10 rounded-md border border-[#0064FF]/30 select-none">
    <svg className="w-26 h-7" viewBox="0 0 130 30" fill="none" xmlns="http://www.w3.org/2000/svg">
      <text
        x="54"
        y="20"
        textAnchor="middle"
        fill="#0064FF"
        fontWeight="900"
        fontSize="16"
        fontFamily="'Arial Black', sans-serif"
        letterSpacing="-0.5"
      >
        Paramount
      </text>
      <text
        x="110"
        y="18"
        textAnchor="middle"
        fill="#0064FF"
        fontWeight="900"
        fontSize="18"
        fontFamily="'Arial Black', sans-serif"
      >
        +
      </text>
    </svg>
  </div>
);

// Crunchyroll: Logo anime en naranja (#FF6B00)
const CrunchyrollLogo = () => (
  <div className="flex items-center justify-center px-2.5 py-0.5 bg-[#FF6B00]/10 rounded-md border border-[#FF6B00]/30 select-none">
    <svg className="w-26 h-7" viewBox="0 0 120 30" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="16" cy="15" r="9" fill="#FF6B00" />
      <circle cx="17.5" cy="15" r="5.5" fill="#141414" />
      <circle cx="19" cy="14" r="3" fill="#FF6B00" />
      <text
        x="68"
        y="20"
        textAnchor="middle"
        fill="#FF6B00"
        fontWeight="800"
        fontSize="13"
        fontFamily="sans-serif"
        letterSpacing="0.2"
      >
        crunchyroll
      </text>
    </svg>
  </div>
);

// Logo genérico para proveedores personalizados
const CustomOttLogo = ({ name = 'OTT', color = '#8B5CF6' }) => (
  <div
    className="flex items-center justify-center px-3 py-1 rounded-md border shadow-sm select-none"
    style={{ backgroundColor: `${color}15`, borderColor: `${color}40` }}
  >
    <Film className="w-3.5 h-3.5 mr-1.5" style={{ color }} />
    <span className="font-bold text-xs uppercase tracking-wider truncate max-w-[100px]" style={{ color }}>
      {name}
    </span>
  </div>
);

/* =========================================================================
   COMPONENTE PRINCIPAL: OttSyncManagement
   ========================================================================= */

export default function OttSyncManagement() {
  // Estado principal de proveedores y tareas (Consumidos exclusivamente de la API real)
  const [providers, setProviders] = useState([]);
  const [tasks, setTasks] = useState([]);
  const [metrics, setMetrics] = useState({
    active_accounts: 0,
    total_platforms: 0,
    total: 0,
    downloading: 0,
    pending: 0,
    completed: 0,
    failed: 0,
    bandwidth: '0.0 MB/s',
    active_connections: 0,
    storage_mount: '/storage/media',
  });
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [syncingProviders, setSyncingProviders] = useState({});

  // Filtros de la cola de descargas
  const [searchFilter, setSearchFilter] = useState('');
  const [providerFilter, setProviderFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');

  // Sistema de Notificaciones Toast Institucional (Cero alertas nativas alert())
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

  // Modal para conectar/configurar cuenta existente
  const [connectModalProvider, setConnectModalProvider] = useState(null);
  const [authMethod, setAuthMethod] = useState('credentials'); // 'credentials' | 'cookies' | 'token'
  const [connectForm, setConnectForm] = useState({
    account_name: '',
    email: '',
    password: '',
    cookies: '',
    token: '',
    quality_profile: '1080p',
    storage_path: '/storage/media',
    auto_download: true,
  });
  const [showPassword, setShowPassword] = useState(false);
  const [connecting, setConnecting] = useState(false);

  // Modal para "+ Agregar Plataforma / Proveedor Personalizado"
  const [isAddPlatformOpen, setIsAddPlatformOpen] = useState(false);
  const [addForm, setAddForm] = useState({
    preset: 'amazon_prime',
    name: 'Amazon Prime Video',
    slug: 'amazon_prime',
    auth_method: 'credentials',
    email: '',
    password: '',
    cookies: '',
    token: '',
    quality_profile: '1080p',
    storage_path: '/storage/media/movies',
    auto_download: true,
    color: '#00A8E1',
  });
  const [addingPlatform, setAddingPlatform] = useState(false);

  // Modal de confirmación estilizado (Cero window.confirm)
  const [confirmModal, setConfirmModal] = useState(null);

  // Modal del Explorador Interactivo de Carpetas del Servidor (Server Directory Picker)
  const [folderPickerOpen, setFolderPickerOpen] = useState(false);
  const [folderPickerTarget, setFolderPickerTarget] = useState('connect'); // 'connect' | 'add'

  /* -------------------------------------------------------------------------
     CARGA DE DATOS REALES (Vía GET /api/admin/ott-providers y GET /api/admin/ingest/tasks)
     ------------------------------------------------------------------------- */
  const loadOttData = useCallback(
    async (isPolling = false) => {
      if (!isPolling) setRefreshing(true);
      try {
        const [resProviders, resTasks] = await Promise.allSettled([
          api.get('/admin/ott-providers'),
          api.get('/admin/ingest/tasks'),
        ]);

        let loadedProviders = [];
        if (resProviders.status === 'fulfilled' && resProviders.value?.data?.providers) {
          loadedProviders = resProviders.value.data.providers;
          setProviders(loadedProviders);
        }

        let loadedTasks = [];
        if (resTasks.status === 'fulfilled') {
          const data = resTasks.value?.data;
          loadedTasks = Array.isArray(data) ? data : data?.tasks || [];
          setTasks(loadedTasks);
        }

        // Cálculo estricto de métricas basado en registros reales de BD
        const downloadingTasks = loadedTasks.filter(
          (t) => t.status === 'downloading' || t.status === 'processing'
        );
        const pendingTasks = loadedTasks.filter((t) => t.status === 'pending');
        const completedTasks = loadedTasks.filter(
          (t) => t.status === 'completed' || t.progress >= 100
        );
        const failedTasks = loadedTasks.filter((t) => t.status === 'failed');

        let totalSpeed = 0;
        downloadingTasks.forEach((t) => {
          if (t.download_speed) {
            const m = t.download_speed.match(/([\d\.]+)/);
            if (m) totalSpeed += parseFloat(m[1]);
          }
        });

        const activeAccountsCount = loadedProviders.filter((p) => p.status === 'connected').length;

        setMetrics({
          active_accounts: activeAccountsCount,
          total_platforms: loadedProviders.length,
          total: loadedTasks.length,
          downloading: downloadingTasks.length,
          pending: pendingTasks.length,
          completed: completedTasks.length,
          failed: failedTasks.length,
          bandwidth: totalSpeed > 0 ? `${totalSpeed.toFixed(1)} MB/s` : '0.0 MB/s',
          active_connections: downloadingTasks.length,
          storage_mount: '/storage/media',
        });
      } catch (err) {
        if (!isPolling) {
          console.error('Error consultando módulo OTT FlixHN:', err);
          showToast('error', 'Error al consultar el servicio de sincronización OTT.');
        }
      } finally {
        setLoading(false);
        if (!isPolling) setRefreshing(false);
      }
    },
    [showToast]
  );

  useEffect(() => {
    loadOttData();
  }, [loadOttData]);

  // Comprobar si hay tareas activas en progreso (pending, downloading, processing)
  const hasActiveTasks = useMemo(() => {
    return tasks.some(
      (t) => t.status === 'pending' || t.status === 'downloading' || t.status === 'processing'
    );
  }, [tasks]);

  // Comprobar si hay alguna sincronización activa ('syncing')
  const hasActiveSync = useMemo(() => {
    return (
      providers.some((p) => p.status === 'syncing') ||
      Object.values(syncingProviders).some(Boolean)
    );
  }, [providers, syncingProviders]);

  // Polling automático cada 2.5 segundos mientras existan tareas en estado 'pending', 'downloading' o 'processing'
  useEffect(() => {
    const shouldPollFast = hasActiveTasks || hasActiveSync;
    const pollInterval = shouldPollFast ? 2500 : 8000;

    const interval = setInterval(() => {
      loadOttData(true);
    }, pollInterval);

    return () => clearInterval(interval);
  }, [hasActiveTasks, hasActiveSync, loadOttData]);

  /* -------------------------------------------------------------------------
     ACCIONES: CONEXIÓN, SINCRONIZACIÓN Y DESCONEXIÓN
     ------------------------------------------------------------------------- */

  // Abrir modal de conexión para un proveedor de la lista
  const handleOpenConnectModal = (providerItem) => {
    setConnectModalProvider(providerItem);
    setAuthMethod(providerItem.auth_method || 'credentials');
    setConnectForm({
      account_name: providerItem.account_name || `${providerItem.name} Account`,
      email: providerItem.account_name || '',
      password: '',
      cookies: '',
      token: '',
      quality_profile: providerItem.quality_profile || '1080p',
      storage_path: providerItem.storage_path || `/storage/media/${providerItem.provider}`,
      auto_download: providerItem.auto_download !== undefined ? providerItem.auto_download : true,
    });
    setShowPassword(false);
  };

  // Guardar credenciales de un proveedor existente
  const handleSaveConnection = async (e) => {
    if (e) e.preventDefault();
    if (!connectModalProvider) return;

    if (authMethod === 'credentials' && (!connectForm.email || !connectForm.password)) {
      showToast('error', 'Por favor ingresa usuario/correo y contraseña.');
      return;
    }
    if (authMethod === 'cookies' && !connectForm.cookies.trim()) {
      showToast('error', 'Por favor ingresa las cookies de sesión.');
      return;
    }
    if (authMethod === 'token' && !connectForm.token.trim()) {
      showToast('error', 'Por favor ingresa el Token de API.');
      return;
    }

    setConnecting(true);
    try {
      const payload = {
        provider: connectModalProvider.provider,
        auth_method: authMethod,
        account_name: connectForm.account_name || connectForm.email,
        email: connectForm.email,
        password: connectForm.password,
        cookies: connectForm.cookies,
        token: connectForm.token,
        quality_profile: connectForm.quality_profile,
        storage_path: connectForm.storage_path,
        auto_download: connectForm.auto_download,
      };

      const res = await api.post(`/admin/ott-providers/${connectModalProvider.provider}/connect`, payload);

      if (res.data?.success) {
        showToast(
          'success',
          res.data.message || `Cuenta de ${connectModalProvider.name} conectada con éxito.`,
          'Conexión Exitosa'
        );
        setConnectModalProvider(null);
        loadOttData();
      } else {
        showToast('error', res.data?.message || 'Error al conectar la cuenta OTT.');
      }
    } catch (err) {
      console.error('Error conectando cuenta OTT:', err);
      const msg = err.response?.data?.message || 'Error de conexión con el proveedor OTT.';
      showToast('error', msg, 'Fallo de Autenticación');
    } finally {
      setConnecting(false);
    }
  };

  // Desconectar cuenta y purgar credenciales
  const handleDisconnect = (providerItem) => {
    setConfirmModal({
      title: `Desconectar ${providerItem.name}`,
      message: `¿Estás seguro de desconectar la cuenta de ${providerItem.name}? Se purgarán todas las credenciales cifradas y se detendrán las sincronizaciones programadas.`,
      confirmText: 'Desconectar Cuenta',
      danger: true,
      onConfirm: async () => {
        try {
          const res = await api.post(`/admin/ott-providers/${providerItem.provider}/disconnect`);
          if (res.data?.success) {
            showToast('info', `Cuenta de ${providerItem.name} desconectada y credenciales purgadas.`, 'Desconectado');
            loadOttData();
          }
        } catch (err) {
          showToast('error', 'No fue posible desconectar la cuenta.');
        } finally {
          setConfirmModal(null);
        }
      },
    });
  };

  // Alternar auto-download
  const handleToggleAutoDownload = async (providerId, currentVal) => {
    try {
      const res = await api.post(`/admin/ott-providers/${providerId}/toggle-auto-download`);
      if (res.data?.success) {
        showToast('success', `Descarga automática ${res.data.auto_download ? 'activada' : 'desactivada'}.`);
        setProviders((prev) =>
          prev.map((p) => (p.provider === providerId ? { ...p, auto_download: res.data.auto_download } : p))
        );
      }
    } catch (err) {
      showToast('error', 'No se pudo actualizar la preferencia de descarga automática.');
    }
  };

  // Desbloquear manualmente o cancelar sincronización atascada / huérfana
  const [resettingProviders, setResettingProviders] = useState({});

  const handleResetStatus = async (providerItem) => {
    const providerId = providerItem.id || providerItem.provider;
    setResettingProviders((prev) => ({ ...prev, [providerItem.provider]: true }));
    try {
      const targetStatus = providerItem.has_credentials ? 'connected' : 'disconnected';
      const res = await api.post(`/admin/ott-providers/${providerId}/reset-status`, {
        status: targetStatus,
      });
      if (res.data?.success) {
        showToast(
          'info',
          res.data.message || `Estado de ${providerItem.name} restablecido a ${res.data.status}.`,
          'Estado Desbloqueado'
        );
        setSyncingProviders((prev) => ({ ...prev, [providerItem.provider]: false }));
        loadOttData();
      } else {
        showToast('error', res.data?.message || 'No fue posible restablecer el estado.');
      }
    } catch (err) {
      console.error('Error restableciendo estado OTT:', err);
      const msg = err.response?.data?.message || 'Error al restablecer el estado del proveedor.';
      showToast('error', msg);
    } finally {
      setResettingProviders((prev) => ({ ...prev, [providerItem.provider]: false }));
    }
  };

  // Sincronizar catálogo real ahora
  const handleSyncNow = async (providerItem) => {
    setSyncingProviders((prev) => ({ ...prev, [providerItem.provider]: true }));
    setProviders((prev) =>
      prev.map((p) =>
        p.provider === providerItem.provider
          ? { ...p, status: 'syncing', last_error: null, error_message: null }
          : p
      )
    );
    try {
      const res = await api.post(`/admin/ott-providers/${providerItem.provider}/sync`);
      if (res.data?.success) {
        showToast(
          'success',
          res.data.message || `Sincronización iniciada para ${providerItem.name}.`,
          'Sync OTT En Proceso'
        );
        loadOttData(true);
      } else {
        showToast('error', res.data?.message || 'Error iniciando sincronización.');
        loadOttData();
      }
    } catch (err) {
      console.error('Error sincronizando OTT:', err);
      const msg = err.response?.data?.message || 'Error al despachar el trabajo de sincronización.';
      showToast('error', msg);
      loadOttData();
    } finally {
      setTimeout(() => {
        setSyncingProviders((prev) => ({ ...prev, [providerItem.provider]: false }));
      }, 1200);
    }
  };

  // Eliminar proveedor personalizado
  const handleDeleteProvider = (providerItem) => {
    setConfirmModal({
      title: `Remover Proveedor ${providerItem.name}`,
      message: `¿Deseas remover completamente '${providerItem.name}' y sus registros de ingesta asociados?`,
      confirmText: 'Remover Proveedor',
      danger: true,
      onConfirm: async () => {
        try {
          const res = await api.delete(`/admin/ott-providers/${providerItem.provider}`);
          if (res.data?.success) {
            showToast('success', `Proveedor '${providerItem.name}' removido.`);
            loadOttData();
          }
        } catch (err) {
          showToast('error', 'Error al remover proveedor.');
        } finally {
          setConfirmModal(null);
        }
      },
    });
  };

  /* -------------------------------------------------------------------------
     MODAL "+ AGREGAR PLATAFORMA / PROVEEDOR PERSONALIZADO"
     ------------------------------------------------------------------------- */
  const handleOpenAddPlatformModal = () => {
    setAddForm({
      preset: 'amazon_prime',
      name: 'Amazon Prime Video',
      slug: 'amazon_prime',
      auth_method: 'credentials',
      email: '',
      password: '',
      cookies: '',
      token: '',
      quality_profile: '1080p',
      storage_path: '/storage/media/movies',
      auto_download: true,
      color: '#00A8E1',
    });
    setIsAddPlatformOpen(true);
  };

  const handlePresetChange = (presetKey) => {
    const presetsMap = {
      amazon_prime: {
        name: 'Amazon Prime Video',
        slug: 'amazon_prime',
        quality_profile: '1080p',
        color: '#00A8E1',
        storage_path: '/storage/media/movies',
      },
      apple_tv: {
        name: 'Apple TV+',
        slug: 'apple_tv',
        quality_profile: '4K HDR',
        color: '#A1A1AA',
        storage_path: '/storage/media/series',
      },
      paramount: {
        name: 'Paramount+',
        slug: 'paramount',
        quality_profile: '1080p',
        color: '#0064FF',
        storage_path: '/storage/media/movies',
      },
      crunchyroll: {
        name: 'Crunchyroll',
        slug: 'crunchyroll',
        quality_profile: '1080p',
        color: '#FF6B00',
        storage_path: '/storage/media/anime',
      },
      custom: {
        name: '',
        slug: '',
        quality_profile: '1080p',
        color: '#8B5CF6',
        storage_path: '/storage/media/custom',
      },
    };

    const sel = presetsMap[presetKey] || presetsMap.custom;
    setAddForm((prev) => ({
      ...prev,
      preset: presetKey,
      name: sel.name,
      slug: sel.slug,
      quality_profile: sel.quality_profile,
      color: sel.color,
      storage_path: sel.storage_path,
    }));
  };

  const handleSaveNewPlatform = async (e) => {
    if (e) e.preventDefault();
    if (!addForm.name.trim() || !addForm.slug.trim()) {
      showToast('error', 'Por favor ingresa el nombre y slug de la plataforma.');
      return;
    }

    setAddingPlatform(true);
    try {
      const payload = {
        provider: addForm.slug.trim().toLowerCase().replace(/\s+/g, '_'),
        display_name: addForm.name.trim(),
        account_name: addForm.email || `${addForm.name} Gateway`,
        auth_method: addForm.auth_method,
        email: addForm.email,
        password: addForm.password,
        cookies: addForm.cookies,
        token: addForm.token,
        quality_profile: addForm.quality_profile,
        storage_path: addForm.storage_path,
        auto_download: addForm.auto_download,
        color: addForm.color,
      };

      const res = await api.post('/admin/ott-providers/connect', payload);

      if (res.data?.success) {
        showToast(
          'success',
          `Plataforma '${addForm.name}' registrada y conectada exitosamente.`,
          'Plataforma Agregada'
        );
        setIsAddPlatformOpen(false);
        loadOttData();
      } else {
        showToast('error', res.data?.message || 'Error al registrar la plataforma.');
      }
    } catch (err) {
      console.error('Error registrando plataforma:', err);
      const msg = err.response?.data?.message || 'Error registrando plataforma OTT.';
      showToast('error', msg);
    } finally {
      setAddingPlatform(false);
    }
  };

  /* -------------------------------------------------------------------------
     ACCIONES DE COLA DE DESCARGAS
     ------------------------------------------------------------------------- */
  const handleCancelTask = async (task) => {
    try {
      const res = await api.post(`/admin/ingest/tasks/${task.id}/cancel`);
      if (res.data?.status === 'success' || res.data?.success) {
        showToast('info', `Descarga de '${task.title}' cancelada.`);
        loadOttData();
      }
    } catch (err) {
      showToast('error', 'Error cancelando la descarga.');
    }
  };

  const handleRetryTask = async (task) => {
    try {
      const res = await api.post(`/admin/ingest/tasks/${task.id}/retry`);
      if (res.data?.status === 'success' || res.data?.success) {
        showToast('success', `Reanudando descarga de '${task.title}'...`);
        loadOttData();
      }
    } catch (err) {
      showToast('error', 'Error al reanudar la tarea.');
    }
  };

  const handleDeleteTask = (task) => {
    setConfirmModal({
      title: 'Eliminar de la Cola',
      message: `¿Deseas remover '${task.title}' de la cola de descargas?`,
      confirmText: 'Remover',
      danger: true,
      onConfirm: async () => {
        try {
          const res = await api.delete(`/admin/ingest/tasks/${task.id}`);
          if (res.data?.status === 'success' || res.data?.success) {
            showToast('success', 'Tarea removida de la cola.');
            loadOttData();
          }
        } catch (err) {
          showToast('error', 'Error al eliminar tarea.');
        } finally {
          setConfirmModal(null);
        }
      },
    });
  };

  const handleClearCompleted = async () => {
    try {
      const res = await api.post('/admin/ott-providers/tasks/clear-completed');
      if (res.data?.success) {
        showToast('info', res.data.message || 'Se limpiaron las tareas completadas.');
        loadOttData();
      }
    } catch (err) {
      showToast('error', 'Error limpiando tareas.');
    }
  };

  // Render del logo oficial institucional según el slug
  const renderProviderLogo = (providerKey, providerObj = null) => {
    switch (providerKey) {
      case 'netflix':
        return <NetflixLogo />;
      case 'disney':
        return <DisneyPlusLogo />;
      case 'hbo_max':
        return <HboMaxLogo />;
      case 'amazon_prime':
      case 'prime_video':
        return <PrimeVideoLogo />;
      case 'apple_tv':
        return <AppleTvLogo />;
      case 'paramount':
        return <ParamountPlusLogo />;
      case 'crunchyroll':
        return <CrunchyrollLogo />;
      default:
        return <CustomOttLogo name={providerObj?.name || providerKey} color={providerObj?.color || '#8B5CF6'} />;
    }
  };

  // Filtro de tareas en memoria
  const filteredTasks = useMemo(() => {
    return tasks.filter((t) => {
      if (providerFilter !== 'all' && t.provider !== providerFilter) return false;
      if (statusFilter !== 'all' && t.status !== statusFilter) return false;
      if (searchFilter.trim()) {
        const q = searchFilter.toLowerCase();
        return (
          t.title.toLowerCase().includes(q) ||
          (t.external_id && t.external_id.toLowerCase().includes(q))
        );
      }
      return true;
    });
  }, [tasks, providerFilter, statusFilter, searchFilter]);

  if (loading) {
    return (
      <div className="space-y-6 animate-pulse">
        <div className="bg-[#181818] border border-zinc-800 rounded-2xl p-6 h-36" />
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="bg-[#1A1A1A] border border-zinc-800 rounded-2xl h-72" />
          <div className="bg-[#1A1A1A] border border-zinc-800 rounded-2xl h-72" />
          <div className="bg-[#1A1A1A] border border-zinc-800 rounded-2xl h-72" />
        </div>
        <div className="bg-[#181818] border border-zinc-800 rounded-2xl h-96" />
      </div>
    );
  }

  return (
    <div className="space-y-8 select-none">
      {/* Toast Notification Flotante Institucional */}
      {toast && (
        <div
          className={`fixed bottom-6 right-6 z-50 flex items-start gap-3 p-4 rounded-xl border shadow-2xl backdrop-blur-md max-w-md animate-in slide-in-from-bottom-5 transition-all ${
            toast.type === 'success'
              ? 'bg-emerald-950/90 border-emerald-500/40 text-emerald-100'
              : toast.type === 'error'
              ? 'bg-red-950/90 border-red-500/40 text-red-100'
              : 'bg-zinc-900/95 border-zinc-700/60 text-zinc-100'
          }`}
        >
          {toast.type === 'success' && <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" />}
          {toast.type === 'error' && <AlertCircle className="w-5 h-5 text-red-400 shrink-0 mt-0.5" />}
          {toast.type === 'info' && <Info className="w-5 h-5 text-sky-400 shrink-0 mt-0.5" />}
          <div className="flex-1 text-sm">
            {toast.title && <p className="font-semibold">{toast.title}</p>}
            <p className="text-xs text-zinc-300 mt-0.5">{toast.message}</p>
          </div>
          <button
            onClick={() => setToast(null)}
            className="text-zinc-400 hover:text-white p-1 rounded-md transition-colors"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* Cabecera Principal y Telemetría On-Net */}
      <div className="relative overflow-hidden bg-gradient-to-r from-zinc-900 via-[#181818] to-zinc-900 border border-zinc-800 rounded-2xl p-6 shadow-xl">
        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
          <div className="space-y-2">
            <div className="flex items-center gap-2">
              <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-mono uppercase tracking-wider bg-red-600/20 text-red-400 border border-red-500/30">
                <Sparkles className="w-3.5 h-3.5 text-red-400" />
                Módulo OTT Ingest v2.5
              </span>
              <span className="text-xs text-zinc-400 font-mono">
                Montaje Storage: {metrics.storage_mount}
              </span>
            </div>
            <h1 className="text-2xl md:text-3xl font-black text-white tracking-tight">
              Cuentas y Sincronización OTT
            </h1>
            <p className="text-sm text-zinc-400 max-w-2xl leading-relaxed">
              Integración nativa con <strong className="text-white">Netflix</strong>,{' '}
              <strong className="text-white">Disney+</strong>,{' '}
              <strong className="text-white">Max (HBO)</strong>,{' '}
              <strong className="text-white">Amazon Prime Video</strong> y proveedores dinámicos.
              Cifrado seguro AES, indexación en segundo plano y distribución a la red On-Net de FlixHN.
            </p>
          </div>

          {/* Botones de Cabecera: "+ Agregar Plataforma" y "Refrescar" */}
          <div className="flex flex-wrap items-center gap-3">
            <button
              onClick={handleOpenAddPlatformModal}
              className="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-500 hover:to-red-600 text-white text-xs font-bold rounded-xl shadow-lg shadow-red-950/40 transition-all active:scale-95 cursor-pointer"
            >
              <Plus className="w-4 h-4" />
              <span>+ Agregar Plataforma / Proveedor Personalizado</span>
            </button>

            <button
              onClick={() => loadOttData()}
              disabled={refreshing}
              className="flex items-center gap-2 px-4 py-2.5 bg-zinc-800/80 hover:bg-zinc-700/80 border border-zinc-700/60 text-zinc-200 text-xs font-semibold rounded-xl transition-all shadow-sm active:scale-95 disabled:opacity-50 cursor-pointer"
            >
              <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin text-red-500' : ''}`} />
              <span>{refreshing ? 'Actualizando...' : 'Refrescar'}</span>
            </button>
          </div>
        </div>

        {/* Métricas Agregadas Reales (Sin números ficticios) */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6 pt-6 border-t border-zinc-800/80">
          <div className="bg-zinc-950/60 border border-zinc-800/80 p-3.5 rounded-xl">
            <span className="text-[11px] uppercase tracking-wider text-zinc-400 font-medium">
              Cuentas Activas
            </span>
            <div className="flex items-baseline gap-2 mt-1">
              <span className="text-2xl font-black text-white">
                {metrics.active_accounts}
              </span>
              <span className="text-xs text-zinc-400">/ {metrics.total_platforms} plataformas</span>
            </div>
          </div>

          <div className="bg-zinc-950/60 border border-zinc-800/80 p-3.5 rounded-xl">
            <span className="text-[11px] uppercase tracking-wider text-zinc-400 font-medium">
              Velocidad On-Net
            </span>
            <div className="flex items-baseline gap-2 mt-1">
              <span className={`text-2xl font-black ${metrics.bandwidth !== '0.0 MB/s' ? 'text-emerald-400' : 'text-zinc-500'}`}>
                {metrics.bandwidth}
              </span>
              <span className="text-xs text-zinc-400">agregada</span>
            </div>
          </div>

          <div className="bg-zinc-950/60 border border-zinc-800/80 p-3.5 rounded-xl">
            <span className="text-[11px] uppercase tracking-wider text-zinc-400 font-medium">
              Tareas en Cola
            </span>
            <div className="flex items-baseline gap-2 mt-1">
              <span className="text-2xl font-black text-sky-400">
                {metrics.downloading + metrics.pending}
              </span>
              <span className="text-xs text-zinc-400">({metrics.downloading} activas)</span>
            </div>
          </div>

          <div className="bg-zinc-950/60 border border-zinc-800/80 p-3.5 rounded-xl">
            <span className="text-[11px] uppercase tracking-wider text-zinc-400 font-medium">
              Completadas en Storage
            </span>
            <div className="flex items-baseline gap-2 mt-1">
              <span className="text-2xl font-black text-white">
                {metrics.completed}
              </span>
              <span className="text-xs text-zinc-400">listas para streaming</span>
            </div>
          </div>
        </div>
      </div>

      {/* Sección 1: Cuadrícula de Plataformas Soportadas & Conectadas (#1A1A1A) */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <div>
            <h2 className="text-lg font-bold text-white flex items-center gap-2">
              <Layers className="w-5 h-5 text-[#E50914]" />
              Plataformas Soportadas & Estado de Conexión
            </h2>
            <p className="text-xs text-zinc-400">
              Conecta credenciales oficiales, perfiles de calidad y almacenamiento para descargas en la red local.
            </p>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {providers.map((provider) => {
            const isSyncing = syncingProviders[provider.provider] || provider.status === 'syncing';
            const isConnected = provider.status === 'connected';

            return (
              <div
                key={provider.provider}
                className="bg-[#1A1A1A] border border-zinc-800 hover:border-zinc-700/80 rounded-2xl p-6 flex flex-col justify-between transition-all duration-200 shadow-lg relative overflow-hidden group"
              >
                {/* Acento superior según la identidad de la plataforma */}
                <div
                  className="absolute top-0 left-0 right-0 h-1.5"
                  style={{ backgroundColor: provider.color || '#E50914' }}
                />

                <div>
                  {/* Cabecera con Logo en #141414 y Badge de Estado */}
                  <div className="flex items-center justify-between gap-3 mb-5">
                    <div className="p-2 bg-[#141414] border border-zinc-800 rounded-xl flex items-center justify-center min-w-[120px] h-12 shadow-inner">
                      {renderProviderLogo(provider.provider, provider)}
                    </div>

                    {/* Status Badge */}
                    <div className="flex items-center">
                      {isSyncing ? (
                        <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-sky-950/70 border border-sky-500/40 text-sky-400 shadow-sm animate-pulse">
                          <RefreshCw className="w-3 h-3 animate-spin text-sky-400" />
                          Sincronizando...
                        </span>
                      ) : isConnected ? (
                        <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-950/70 border border-emerald-500/40 text-emerald-400 shadow-sm">
                          <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
                          Conectado
                        </span>
                      ) : provider.status === 'error' ? (
                        <span
                          className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-red-950/70 border border-red-500/40 text-red-400 shadow-sm cursor-help"
                          title={provider.last_error || provider.error_message || 'Fallo al sincronizar'}
                        >
                          <AlertCircle className="w-3.5 h-3.5 text-red-400" />
                          Error
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-zinc-900 border border-zinc-700 text-zinc-400">
                          <span className="w-2 h-2 rounded-full bg-zinc-500" />
                          Desconectado
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Descripción de la plataforma */}
                  <p className="text-xs text-zinc-400 line-clamp-2 leading-relaxed mb-4">
                    {provider.description}
                  </p>

                  {/* Badge / Tooltip Sutil con Motivo del Fallo */}
                  {(provider.last_error || provider.error_message) && provider.status !== 'syncing' && (
                    <div className="relative flex items-start gap-2.5 p-3 rounded-xl bg-red-950/30 border border-red-500/30 text-red-300 text-xs mb-4 shadow-sm">
                      <AlertTriangle className="w-4 h-4 text-red-400 shrink-0 mt-0.5" />
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center justify-between gap-1">
                          <span className="font-bold text-[11px] text-red-400 uppercase tracking-wider">
                            Motivo del fallo
                          </span>
                          <button
                            type="button"
                            onClick={() => handleResetStatus(provider)}
                            disabled={resettingProviders[provider.provider]}
                            className="text-[10px] text-red-400 hover:text-red-200 underline cursor-pointer"
                            title="Restablecer estado y borrar aviso de error"
                          >
                            Limpiar
                          </button>
                        </div>
                        <p
                          className="text-[11px] text-red-200/90 line-clamp-2 mt-0.5 leading-snug break-words"
                          title={provider.last_error || provider.error_message}
                        >
                          {provider.last_error || provider.error_message}
                        </p>
                      </div>
                    </div>
                  )}

                  {/* Detalles de la cuenta */}
                  <div className="space-y-3 bg-zinc-950/60 border border-zinc-800/80 p-3.5 rounded-xl mb-5">
                    <div className="flex items-center justify-between text-xs">
                      <span className="text-zinc-400">Cuenta / Alias:</span>
                      <span className="font-mono text-zinc-200 truncate max-w-[170px]">
                        {provider.account_name || 'No configurada'}
                      </span>
                    </div>

                    <div className="flex items-center justify-between text-xs">
                      <span className="text-zinc-400">Perfil Calidad:</span>
                      <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-zinc-800 text-zinc-200 border border-zinc-700/60">
                        {provider.quality_profile || '1080p'}
                      </span>
                    </div>

                    <div className="flex items-center justify-between text-xs">
                      <span className="text-zinc-400">Última Sincronización:</span>
                      <span className="text-zinc-300 font-mono text-[11px]">
                        {provider.last_sync_at
                          ? new Date(provider.last_sync_at).toLocaleString('es-HN', {
                              dateStyle: 'short',
                              timeStyle: 'short',
                            })
                          : 'Nunca'}
                      </span>
                    </div>

                    {/* Switch de Auto-Download */}
                    <div className="flex items-center justify-between pt-2 border-t border-zinc-800/80 text-xs">
                      <span className="text-zinc-400">Descarga Automática:</span>
                      <button
                        type="button"
                        onClick={() => handleToggleAutoDownload(provider.provider, provider.auto_download)}
                        className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${
                          provider.auto_download ? 'bg-red-600' : 'bg-zinc-800'
                        }`}
                        title="Activar/desactivar descargas periódicas automáticas"
                      >
                        <span
                          className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                            provider.auto_download ? 'translate-x-4' : 'translate-x-0'
                          }`}
                        />
                      </button>
                    </div>
                  </div>

                  {/* Métricas específicas del proveedor */}
                  <div className="grid grid-cols-3 gap-2 mb-6 text-center">
                    <div className="bg-zinc-900/60 p-2 rounded-lg border border-zinc-800/60">
                      <span className="block text-xs font-black text-white">
                        {provider.stats?.imported_items || 0}
                      </span>
                      <span className="text-[10px] text-zinc-400">Importados</span>
                    </div>
                    <div className="bg-zinc-900/60 p-2 rounded-lg border border-zinc-800/60">
                      <span className="block text-xs font-black text-sky-400">
                        {provider.stats?.active_downloads || 0}
                      </span>
                      <span className="text-[10px] text-zinc-400">En Cola</span>
                    </div>
                    <div className="bg-zinc-900/60 p-2 rounded-lg border border-zinc-800/60">
                      <span className="block text-xs font-black text-emerald-400">
                        {provider.stats?.completed_downloads || 0}
                      </span>
                      <span className="text-[10px] text-zinc-400">Listos</span>
                    </div>
                  </div>
                </div>

                {/* Acciones de la Tarjeta */}
                <div className="space-y-2 pt-2 border-t border-zinc-800/60">
                  <div className="grid grid-cols-2 gap-2">
                    <button
                      onClick={() => handleOpenConnectModal(provider)}
                      className="flex items-center justify-center gap-1.5 px-3 py-2 bg-zinc-800 hover:bg-zinc-700 text-white text-xs font-semibold rounded-xl border border-zinc-700/60 transition-all active:scale-95 cursor-pointer"
                    >
                      <KeyRound className="w-3.5 h-3.5 text-zinc-400" />
                      <span>{isConnected ? 'Configurar' : 'Conectar'}</span>
                    </button>

                    <button
                      onClick={() => handleSyncNow(provider)}
                      disabled={isSyncing}
                      className={`flex items-center justify-center gap-1.5 px-3 py-2 text-white text-xs font-bold rounded-xl shadow-md transition-all active:scale-95 cursor-pointer ${
                        isSyncing
                          ? 'bg-zinc-800 text-zinc-400 border border-zinc-700/50 cursor-not-allowed'
                          : 'bg-[#E50914] hover:bg-red-700'
                      }`}
                    >
                      <RefreshCw className={`w-3.5 h-3.5 ${isSyncing ? 'animate-spin text-sky-400' : ''}`} />
                      <span>{isSyncing ? 'Sincronizando...' : 'Sync Ahora'}</span>
                    </button>
                  </div>

                  {/* Acciones secundarias y botón de desbloqueo si está en proceso */}
                  <div className="flex items-center justify-between pt-1">
                    {isSyncing ? (
                      <button
                        type="button"
                        onClick={() => handleResetStatus(provider)}
                        disabled={resettingProviders[provider.provider]}
                        className="inline-flex items-center gap-1.5 text-[11px] text-amber-400 hover:text-amber-300 transition-colors py-0.5 cursor-pointer"
                        title="Desbloquear interfaz manualmente si la tarea quedó huérfana o tarda demasiado"
                      >
                        <RotateCcw className={`w-3 h-3 ${resettingProviders[provider.provider] ? 'animate-spin' : ''}`} />
                        <span>{resettingProviders[provider.provider] ? 'Desbloqueando...' : '¿Atascado? Forzar desbloqueo'}</span>
                      </button>
                    ) : isConnected ? (
                      <button
                        onClick={() => handleDisconnect(provider)}
                        className="text-[11px] text-zinc-400 hover:text-red-400 transition-colors cursor-pointer"
                      >
                        Desconectar y purgar credenciales
                      </button>
                    ) : (
                      <span className="text-[11px] text-zinc-600">Requiere conexión</span>
                    )}

                    {provider.is_custom && (
                      <button
                        onClick={() => handleDeleteProvider(provider)}
                        className="text-[11px] text-zinc-500 hover:text-red-400 transition-colors cursor-pointer"
                        title="Eliminar este proveedor personalizado"
                      >
                        Eliminar
                      </button>
                    )}
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Sección 2: Cola de Descargas e Ingesta On-Net */}
      <div className="bg-[#181818] border border-zinc-800 rounded-2xl p-6 shadow-xl space-y-5">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <h2 className="text-lg font-bold text-white flex items-center gap-2">
              <DownloadCloud className="w-5 h-5 text-[#E50914]" />
              Cola de Descargas e Ingesta On-Net
            </h2>
            <p className="text-xs text-zinc-400">
              Monitoreo en tiempo real de medios en ingesta, velocidad de fibra óptica y registro automático en catálogo.
            </p>
          </div>

          {/* Botón limpiar completadas */}
          {metrics.completed > 0 && (
            <button
              onClick={handleClearCompleted}
              className="flex items-center gap-1.5 px-3 py-1.5 text-xs text-zinc-400 hover:text-zinc-200 bg-zinc-900 border border-zinc-800 rounded-lg hover:bg-zinc-800 transition-colors self-start md:self-auto cursor-pointer"
            >
              <Trash2 className="w-3.5 h-3.5" />
              <span>Limpiar Completadas ({metrics.completed})</span>
            </button>
          )}
        </div>

        {/* Barra de Filtros y Búsqueda */}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 bg-zinc-950/50 p-3 rounded-xl border border-zinc-800/80">
          <div className="relative">
            <Search className="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              placeholder="Buscar título o identificador..."
              value={searchFilter}
              onChange={(e) => setSearchFilter(e.target.value)}
              className="w-full bg-zinc-900 border border-zinc-800 rounded-lg pl-9 pr-3 py-1.5 text-xs text-zinc-200 placeholder-zinc-500 focus:outline-none focus:border-red-500"
            />
          </div>

          <div className="flex items-center gap-2">
            <Filter className="w-3.5 h-3.5 text-zinc-400 shrink-0" />
            <select
              value={providerFilter}
              onChange={(e) => setProviderFilter(e.target.value)}
              className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-2.5 py-1.5 text-xs text-zinc-200 focus:outline-none focus:border-red-500"
            >
              <option value="all">Todas las Plataformas</option>
              {providers.map((p) => (
                <option key={p.provider} value={p.provider}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-2.5 py-1.5 text-xs text-zinc-200 focus:outline-none focus:border-red-500"
            >
              <option value="all">Todos los Estados</option>
              <option value="downloading">En Descarga</option>
              <option value="pending">Pendientes</option>
              <option value="completed">Completados</option>
              <option value="cancelled">Cancelados</option>
              <option value="failed">Fallidos</option>
            </select>
          </div>
        </div>

        {/* Tabla de Tareas o Estado Vacío Limpio */}
        {filteredTasks.length === 0 ? (
          <div className="text-center py-14 bg-zinc-950/40 rounded-xl border border-zinc-800/60 p-6 space-y-3">
            <DownloadCloud className="w-12 h-12 text-zinc-600 mx-auto" />
            <p className="text-sm font-semibold text-zinc-200">
              No hay tareas de ingesta o descargas en cola. Conecta una plataforma para iniciar sincronización.
            </p>
            <p className="text-xs text-zinc-500 max-w-md mx-auto">
              Al conectar y sincronizar una cuenta, las tareas de ingesta hacia el almacenamiento local se procesarán automáticamente.
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-950/60">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-zinc-800 bg-zinc-900/80 text-zinc-400 font-mono uppercase text-[10px]">
                  <th className="py-3 px-4">Contenido / Título</th>
                  <th className="py-3 px-3">Plataforma</th>
                  <th className="py-3 px-3">Calidad</th>
                  <th className="py-3 px-4 w-56">Progreso & Velocidad</th>
                  <th className="py-3 px-3">Almacenamiento Local</th>
                  <th className="py-3 px-3 text-right">Acciones</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-zinc-800/60">
                {filteredTasks.map((task) => {
                  const isDownloading = task.status === 'downloading' || task.status === 'processing';
                  const isCompleted = task.status === 'completed' || task.progress >= 100;
                  const isCancelled = task.status === 'cancelled';
                  const isFailed = task.status === 'failed';
                  const mediaType = task.media_type || task.title_model?.type || task.type || (task.provider === 'series' || (task.output_file && task.output_file.includes('series')) ? 'series' : 'movie');
                  const posterUrl = task.metadata?.poster_url || task.title_model?.poster_url || task.poster_url || '/img/placeholder-poster.png';
                  const filePath = task.file_path || task.output_file || '';

                  return (
                    <tr key={task.id} className="hover:bg-zinc-900/40 transition-colors">
                      {/* Título y Carátula */}
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-3">
                          {posterUrl ? (
                            <img
                              src={posterUrl}
                              alt={task.title}
                              onError={(e) => {
                                e.target.onerror = null;
                                e.target.src = '/img/placeholder-poster.png';
                              }}
                              className="w-9 h-13 object-cover rounded shadow border border-zinc-800"
                            />
                          ) : (
                            <div className="w-9 h-13 bg-zinc-900 rounded border border-zinc-800 flex items-center justify-center">
                              {mediaType === 'series' ? (
                                <Tv className="w-4 h-4 text-zinc-600" />
                              ) : (
                                <Film className="w-4 h-4 text-zinc-600" />
                              )}
                            </div>
                          )}
                          <div className="space-y-0.5">
                            <span className="font-bold text-white text-sm block">
                              {task.title}
                            </span>
                            <div className="flex items-center gap-2 text-[11px] text-zinc-400">
                              <span className="capitalize">{mediaType}</span>
                              {(task.metadata?.release_year || task.title_model?.release_year || task.release_year) && (
                                <>
                                  <span>•</span>
                                  <span>{task.metadata?.release_year || task.title_model?.release_year || task.release_year}</span>
                                </>
                              )}
                              {(task.metadata?.genre || task.title_model?.genre || task.genre) && (
                                <>
                                  <span>•</span>
                                  <span className="truncate max-w-[150px]">{task.metadata?.genre || task.title_model?.genre || task.genre}</span>
                                </>
                              )}
                            </div>
                          </div>
                        </div>
                      </td>

                      {/* Plataforma */}
                      <td className="py-3 px-3">
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wider bg-zinc-900 border border-zinc-700 text-zinc-300">
                          {task.provider ? task.provider.replace('_', ' ') : 'OTT'}
                        </span>
                      </td>

                      {/* Calidad */}
                      <td className="py-3 px-3">
                        <span className="px-2 py-0.5 rounded text-[11px] font-mono bg-zinc-900 text-zinc-300 border border-zinc-800">
                          {task.quality || '1080p'}
                        </span>
                      </td>

                      {/* Progreso y Velocidad */}
                      <td className="py-3 px-4">
                        <div className="space-y-1.5">
                          <div className="flex items-center justify-between text-[11px]">
                            <span className="font-semibold text-zinc-200">
                              {task.progress}%
                            </span>
                            <span className="text-zinc-400 font-mono">
                              {isDownloading ? (task.download_speed || 'Descargando...') : isCompleted ? 'Completado' : task.status}
                            </span>
                          </div>

                          <div className="w-full bg-zinc-800 rounded-full h-1.5 overflow-hidden">
                            <div
                              className={`h-full rounded-full transition-all duration-500 ${
                                isCompleted
                                  ? 'bg-emerald-500'
                                  : isCancelled || isFailed
                                  ? 'bg-zinc-600'
                                  : 'bg-red-600'
                              }`}
                              style={{ width: `${Math.min(100, Math.max(0, task.progress))}%` }}
                            />
                          </div>

                          {isDownloading && task.eta && (
                            <div className="text-[10px] text-zinc-500 font-mono">
                              ETA restante: {task.eta}
                            </div>
                          )}
                        </div>
                      </td>

                      {/* Ruta de Almacenamiento */}
                      <td className="py-3 px-3">
                        <span
                          className="font-mono text-[11px] text-zinc-400 bg-zinc-900/80 px-2 py-1 rounded border border-zinc-800/80 truncate block max-w-[200px]"
                          title={filePath}
                        >
                          {filePath || 'Almacenamiento On-Net'}
                        </span>
                      </td>

                      {/* Acciones */}
                      <td className="py-3 px-3 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          {isDownloading && (
                            <button
                              onClick={() => handleCancelTask(task)}
                              title="Pausar / Cancelar descarga"
                              className="p-1.5 text-zinc-400 hover:text-amber-400 hover:bg-zinc-800 rounded transition-colors cursor-pointer"
                            >
                              <Pause className="w-4 h-4" />
                            </button>
                          )}

                          {(isCancelled || isFailed) && (
                            <button
                              onClick={() => handleRetryTask(task)}
                              title="Reintentar descarga"
                              className="p-1.5 text-zinc-400 hover:text-emerald-400 hover:bg-zinc-800 rounded transition-colors cursor-pointer"
                            >
                              <RotateCcw className="w-4 h-4" />
                            </button>
                          )}

                          <button
                            onClick={() => handleDeleteTask(task)}
                            title="Eliminar de la cola"
                            className="p-1.5 text-zinc-400 hover:text-red-400 hover:bg-zinc-800 rounded transition-colors cursor-pointer"
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

      {/* Modal 1: Conectar / Configurar Cuenta de Plataforma Existente */}
      {connectModalProvider && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in fade-in duration-200">
          <div className="relative w-full max-w-lg bg-[#181818] border border-zinc-800 rounded-2xl shadow-2xl overflow-hidden">
            <div
              className="h-2 w-full"
              style={{ backgroundColor: connectModalProvider.color || '#E50914' }}
            />

            <div className="p-6">
              <div className="flex items-start justify-between gap-4 mb-5">
                <div className="flex items-center gap-3">
                  <div className="px-3 py-2 bg-[#141414] border border-zinc-800 rounded-xl flex items-center justify-center min-w-[104px] shadow-inner">
                    {renderProviderLogo(connectModalProvider.provider, connectModalProvider)}
                  </div>
                  <div>
                    <h3 className="text-lg font-bold text-white">
                      Conectar {connectModalProvider.name}
                    </h3>
                    <p className="text-xs text-zinc-400">
                      Credenciales seguras cifradas con algoritmo AES-256 en base de datos.
                    </p>
                  </div>
                </div>

                <button
                  onClick={() => setConnectModalProvider(null)}
                  className="text-zinc-400 hover:text-white p-1 rounded-lg hover:bg-zinc-800 transition-colors"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              {/* Selector de Método de Autenticación */}
              <div className="grid grid-cols-3 gap-1.5 p-1 bg-zinc-950 border border-zinc-800 rounded-xl mb-5">
                <button
                  type="button"
                  onClick={() => setAuthMethod('credentials')}
                  className={`py-2 text-xs font-semibold rounded-lg transition-all cursor-pointer ${
                    authMethod === 'credentials'
                      ? 'bg-zinc-800 text-white shadow-sm'
                      : 'text-zinc-400 hover:text-zinc-200'
                  }`}
                >
                  Credenciales
                </button>
                <button
                  type="button"
                  onClick={() => setAuthMethod('cookies')}
                  className={`py-2 text-xs font-semibold rounded-lg transition-all cursor-pointer ${
                    authMethod === 'cookies'
                      ? 'bg-zinc-800 text-white shadow-sm'
                      : 'text-zinc-400 hover:text-zinc-200'
                  }`}
                >
                  Cookies JSON
                </button>
                <button
                  type="button"
                  onClick={() => setAuthMethod('token')}
                  className={`py-2 text-xs font-semibold rounded-lg transition-all cursor-pointer ${
                    authMethod === 'token'
                      ? 'bg-zinc-800 text-white shadow-sm'
                      : 'text-zinc-400 hover:text-zinc-200'
                  }`}
                >
                  Token de API
                </button>
              </div>

              {/* Formulario */}
              <form onSubmit={handleSaveConnection} className="space-y-4">
                <div>
                  <label className="block text-xs font-semibold text-zinc-300 mb-1.5">
                    Alias o Identificador de Cuenta
                  </label>
                  <input
                    type="text"
                    required
                    placeholder="ej: cuenta-principal@flixhn.local"
                    value={connectForm.account_name}
                    onChange={(e) => setConnectForm({ ...connectForm, account_name: e.target.value })}
                    className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-red-500 transition-colors"
                  />
                </div>

                {authMethod === 'credentials' && (
                  <>
                    <div>
                      <label className="block text-xs font-semibold text-zinc-300 mb-1.5">
                        Correo de Acceso {connectModalProvider.name}
                      </label>
                      <input
                        type="email"
                        required
                        placeholder="usuario@dominio.com"
                        value={connectForm.email}
                        onChange={(e) => setConnectForm({ ...connectForm, email: e.target.value })}
                        className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-red-500 transition-colors"
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-semibold text-zinc-300 mb-1.5">
                        Contraseña de la Plataforma
                      </label>
                      <div className="relative">
                        <input
                          type={showPassword ? 'text' : 'password'}
                          required
                          placeholder="••••••••••••"
                          value={connectForm.password}
                          onChange={(e) => setConnectForm({ ...connectForm, password: e.target.value })}
                          className="w-full bg-zinc-900 border border-zinc-800 rounded-xl pl-3.5 pr-10 py-2.5 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-red-500 transition-colors"
                        />
                        <button
                          type="button"
                          onClick={() => setShowPassword(!showPassword)}
                          className="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-white"
                        >
                          {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                        </button>
                      </div>
                    </div>
                  </>
                )}

                {authMethod === 'cookies' && (
                  <div>
                    <label className="block text-xs font-semibold text-zinc-300 mb-1.5">
                      Cookies de Sesión Exportadas (JSON / Netscape)
                    </label>
                    <textarea
                      rows={4}
                      required
                      placeholder='[{"name":"session_id","value":"..."},{"name":"token","value":"..."}]'
                      value={connectForm.cookies}
                      onChange={(e) => setConnectForm({ ...connectForm, cookies: e.target.value })}
                      className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs font-mono text-zinc-200 placeholder-zinc-600 focus:outline-none focus:border-red-500 transition-colors"
                    />
                  </div>
                )}

                {authMethod === 'token' && (
                  <div>
                    <label className="block text-xs font-semibold text-zinc-300 mb-1.5">
                      Token de Autenticación / API Key
                    </label>
                    <input
                      type="text"
                      required
                      placeholder="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
                      value={connectForm.token}
                      onChange={(e) => setConnectForm({ ...connectForm, token: e.target.value })}
                      className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs font-mono text-zinc-200 placeholder-zinc-600 focus:outline-none focus:border-red-500 transition-colors"
                    />
                  </div>
                )}

                {/* Perfil de Calidad y Almacenamiento */}
                <div className="grid grid-cols-2 gap-4 pt-1">
                  <div>
                    <label className="block text-xs font-semibold text-zinc-300 mb-1.5">
                      Perfil de Calidad
                    </label>
                    <select
                      value={connectForm.quality_profile}
                      onChange={(e) => setConnectForm({ ...connectForm, quality_profile: e.target.value })}
                      className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-xs text-zinc-200 focus:outline-none focus:border-red-500"
                    >
                      <option value="1080p">1080p Full HD</option>
                      <option value="4K HDR">4K HDR / Dolby Vision</option>
                      <option value="720p">720p HD Adaptativo</option>
                    </select>
                  </div>

                  <div>
                    <div className="flex items-center justify-between mb-1.5">
                      <label className="block text-xs font-semibold text-zinc-300">
                        Ruta Destino
                      </label>
                      <span className="text-[10px] text-zinc-500 font-mono">Servidor FlixHN</span>
                    </div>
                    <div className="flex items-center gap-1.5">
                      <div className="relative flex-1">
                        <input
                          type="text"
                          readOnly
                          value={connectForm.storage_path}
                          className="w-full bg-zinc-900 border border-zinc-800 rounded-xl pl-3 pr-8 py-2 text-xs font-mono text-zinc-200 focus:outline-none cursor-default select-all"
                          title="Ruta seleccionada en el servidor"
                        />
                        <HardDrive className="w-3.5 h-3.5 text-zinc-500 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                      </div>
                      <button
                        type="button"
                        onClick={() => {
                          setFolderPickerTarget('connect');
                          setFolderPickerOpen(true);
                        }}
                        className="flex items-center gap-1.5 px-3 py-2 bg-zinc-800 hover:bg-zinc-700 text-zinc-200 hover:text-white border border-zinc-700 rounded-xl text-xs font-semibold transition-all cursor-pointer shadow-sm shrink-0 active:scale-95"
                        title="Explorar y seleccionar carpetas del servidor"
                      >
                        <Folder className="w-3.5 h-3.5 text-amber-400 fill-current" />
                        <span>Examinar...</span>
                      </button>
                    </div>
                  </div>
                </div>

                <div className="pt-1">
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={connectForm.auto_download}
                      onChange={(e) => setConnectForm({ ...connectForm, auto_download: e.target.checked })}
                      className="rounded border-zinc-700 text-red-600 focus:ring-red-500 w-4 h-4"
                    />
                    <span className="text-xs text-zinc-300">
                      Habilitar descarga automática periódica de estrenos
                    </span>
                  </label>
                </div>

                {/* Botones */}
                <div className="flex items-center justify-end gap-3 pt-4 border-t border-zinc-800">
                  <button
                    type="button"
                    onClick={() => setConnectModalProvider(null)}
                    className="px-4 py-2 text-xs font-semibold text-zinc-400 hover:text-white transition-colors cursor-pointer"
                  >
                    Cancelar
                  </button>
                  <button
                    type="submit"
                    disabled={connecting}
                    className="flex items-center gap-2 px-5 py-2.5 bg-[#E50914] hover:bg-red-700 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow-lg transition-all active:scale-95 cursor-pointer"
                  >
                    {connecting ? (
                      <>
                        <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                        <span>Validando sesión...</span>
                      </>
                    ) : (
                      <>
                        <ShieldCheck className="w-4 h-4" />
                        <span>Guardar y Conectar</span>
                      </>
                    )}
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      )}

      {/* Modal 2: "+ Agregar Plataforma / Proveedor Personalizado" */}
      {isAddPlatformOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in fade-in duration-200">
          <div className="relative w-full max-w-lg bg-[#181818] border border-zinc-800 rounded-2xl shadow-2xl overflow-hidden">
            <div
              className="h-2 w-full"
              style={{ backgroundColor: addForm.color || '#E50914' }}
            />

            <div className="p-6">
              <div className="flex items-start justify-between gap-4 mb-4">
                <div className="flex items-center gap-3">
                  <div className="p-2.5 bg-[#141414] border border-zinc-800 rounded-xl text-red-500">
                    <FolderPlus className="w-6 h-6" />
                  </div>
                  <div>
                    <h3 className="text-lg font-bold text-white">
                      Agregar Plataforma / Proveedor Personalizado
                    </h3>
                    <p className="text-xs text-zinc-400">
                      Integra Amazon Prime Video, Apple TV+, Paramount+, Crunchyroll u otro servicio OTT.
                    </p>
                  </div>
                </div>

                <button
                  onClick={() => setIsAddPlatformOpen(false)}
                  className="text-zinc-400 hover:text-white p-1 rounded-lg hover:bg-zinc-800 transition-colors"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <form onSubmit={handleSaveNewPlatform} className="space-y-4">
                {/* Seleccionar Plantilla Rápida */}
                <div>
                  <label className="block text-xs font-semibold text-zinc-300 mb-1.5">
                    Servicio o Plantilla
                  </label>
                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    {[
                      { id: 'amazon_prime', label: 'Prime Video' },
                      { id: 'apple_tv', label: 'Apple TV+' },
                      { id: 'paramount', label: 'Paramount+' },
                      { id: 'crunchyroll', label: 'Crunchyroll' },
                      { id: 'custom', label: 'Personalizado' },
                    ].map((p) => (
                      <button
                        key={p.id}
                        type="button"
                        onClick={() => handlePresetChange(p.id)}
                        className={`px-3 py-2 text-xs font-semibold rounded-xl border text-center transition-all cursor-pointer ${
                          addForm.preset === p.id
                            ? 'bg-zinc-800 text-white border-red-500/60 shadow-sm'
                            : 'bg-zinc-900/80 text-zinc-400 border-zinc-800 hover:text-zinc-200'
                        }`}
                      >
                        {p.label}
                      </button>
                    ))}
                  </div>
                </div>

                {/* Nombre y Slug */}
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-zinc-300 mb-1.5">
                      Nombre de la Plataforma
                    </label>
                    <input
                      type="text"
                      required
                      placeholder="ej: Crunchyroll"
                      value={addForm.name}
                      onChange={(e) => setAddForm({ ...addForm, name: e.target.value })}
                      className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3.5 py-2 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-red-500"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-zinc-300 mb-1.5">
                      Identificador / Slug
                    </label>
                    <input
                      type="text"
                      required
                      placeholder="ej: crunchyroll"
                      value={addForm.slug}
                      onChange={(e) => setAddForm({ ...addForm, slug: e.target.value })}
                      className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3.5 py-2 text-xs font-mono text-zinc-200 placeholder-zinc-600 focus:outline-none focus:border-red-500"
                    />
                  </div>
                </div>

                {/* Tipo de Autenticación */}
                <div>
                  <label className="block text-xs font-semibold text-zinc-300 mb-1.5">
                    Tipo de Autenticación
                  </label>
                  <div className="grid grid-cols-3 gap-2 p-1 bg-zinc-950 border border-zinc-800 rounded-xl">
                    <button
                      type="button"
                      onClick={() => setAddForm({ ...addForm, auth_method: 'credentials' })}
                      className={`py-1.5 text-xs font-semibold rounded-lg transition-all cursor-pointer ${
                        addForm.auth_method === 'credentials'
                          ? 'bg-zinc-800 text-white shadow-sm'
                          : 'text-zinc-400 hover:text-zinc-200'
                      }`}
                    >
                      Credenciales
                    </button>
                    <button
                      type="button"
                      onClick={() => setAddForm({ ...addForm, auth_method: 'cookies' })}
                      className={`py-1.5 text-xs font-semibold rounded-lg transition-all cursor-pointer ${
                        addForm.auth_method === 'cookies'
                          ? 'bg-zinc-800 text-white shadow-sm'
                          : 'text-zinc-400 hover:text-zinc-200'
                      }`}
                    >
                      Cookies JSON
                    </button>
                    <button
                      type="button"
                      onClick={() => setAddForm({ ...addForm, auth_method: 'token' })}
                      className={`py-1.5 text-xs font-semibold rounded-lg transition-all cursor-pointer ${
                        addForm.auth_method === 'token'
                          ? 'bg-zinc-800 text-white shadow-sm'
                          : 'text-zinc-400 hover:text-zinc-200'
                      }`}
                    >
                      Token API
                    </button>
                  </div>
                </div>

                {/* Campos de Autenticación */}
                {addForm.auth_method === 'credentials' && (
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-xs font-semibold text-zinc-300 mb-1">
                        Usuario / Correo
                      </label>
                      <input
                        type="text"
                        placeholder="cuenta@dominio.com"
                        value={addForm.email}
                        onChange={(e) => setAddForm({ ...addForm, email: e.target.value })}
                        className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-red-500"
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-semibold text-zinc-300 mb-1">
                        Contraseña
                      </label>
                      <input
                        type="password"
                        placeholder="••••••••••••"
                        value={addForm.password}
                        onChange={(e) => setAddForm({ ...addForm, password: e.target.value })}
                        className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-red-500"
                      />
                    </div>
                  </div>
                )}

                {addForm.auth_method === 'cookies' && (
                  <div>
                    <label className="block text-xs font-semibold text-zinc-300 mb-1">
                      Cookies de Sesión JSON
                    </label>
                    <textarea
                      rows={3}
                      placeholder='[{"name":"session-token","value":"..."}]'
                      value={addForm.cookies}
                      onChange={(e) => setAddForm({ ...addForm, cookies: e.target.value })}
                      className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-xs font-mono text-zinc-300 placeholder-zinc-600 focus:outline-none focus:border-red-500"
                    />
                  </div>
                )}

                {addForm.auth_method === 'token' && (
                  <div>
                    <label className="block text-xs font-semibold text-zinc-300 mb-1">
                      Token de Autenticación
                    </label>
                    <input
                      type="text"
                      placeholder="API Token / Bearer Token"
                      value={addForm.token}
                      onChange={(e) => setAddForm({ ...addForm, token: e.target.value })}
                      className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-xs font-mono text-zinc-300 placeholder-zinc-600 focus:outline-none focus:border-red-500"
                    />
                  </div>
                )}

                {/* Perfil de Calidad y Ruta de Almacenamiento */}
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-semibold text-zinc-300 mb-1">
                      Perfil de Calidad
                    </label>
                    <select
                      value={addForm.quality_profile}
                      onChange={(e) => setAddForm({ ...addForm, quality_profile: e.target.value })}
                      className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-xs text-zinc-200 focus:outline-none focus:border-red-500"
                    >
                      <option value="1080p">1080p Full HD</option>
                      <option value="4K HDR">4K HDR / Dolby Vision</option>
                      <option value="720p">720p HD Adaptativo</option>
                    </select>
                  </div>

                  <div>
                    <div className="flex items-center justify-between mb-1">
                      <label className="block text-xs font-semibold text-zinc-300">
                        Ruta Destino de Almacenamiento
                      </label>
                      <span className="text-[10px] text-zinc-500 font-mono">Servidor FlixHN</span>
                    </div>
                    <div className="flex items-center gap-1.5">
                      <div className="relative flex-1">
                        <input
                          type="text"
                          readOnly
                          value={addForm.storage_path}
                          className="w-full bg-zinc-900 border border-zinc-800 rounded-xl pl-3 pr-8 py-2 text-xs font-mono text-zinc-200 focus:outline-none cursor-default select-all"
                          title="Ruta seleccionada en el servidor"
                        />
                        <HardDrive className="w-3.5 h-3.5 text-zinc-500 absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" />
                      </div>
                      <button
                        type="button"
                        onClick={() => {
                          setFolderPickerTarget('add');
                          setFolderPickerOpen(true);
                        }}
                        className="flex items-center gap-1.5 px-3 py-2 bg-zinc-800 hover:bg-zinc-700 text-zinc-200 hover:text-white border border-zinc-700 rounded-xl text-xs font-semibold transition-all cursor-pointer shadow-sm shrink-0 active:scale-95"
                        title="Explorar y seleccionar carpetas del servidor"
                      >
                        <Folder className="w-3.5 h-3.5 text-amber-400 fill-current" />
                        <span>Examinar...</span>
                      </button>
                    </div>
                  </div>
                </div>

                {/* Checkbox auto download */}
                <div>
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={addForm.auto_download}
                      onChange={(e) => setAddForm({ ...addForm, auto_download: e.target.checked })}
                      className="rounded border-zinc-700 text-red-600 focus:ring-red-500 w-4 h-4"
                    />
                    <span className="text-xs text-zinc-300">
                      Activar sincronización automática de nuevos estrenos
                    </span>
                  </label>
                </div>

                {/* Botones de acción */}
                <div className="flex items-center justify-end gap-3 pt-4 border-t border-zinc-800">
                  <button
                    type="button"
                    onClick={() => setIsAddPlatformOpen(false)}
                    className="px-4 py-2 text-xs font-semibold text-zinc-400 hover:text-white transition-colors cursor-pointer"
                  >
                    Cancelar
                  </button>
                  <button
                    type="submit"
                    disabled={addingPlatform}
                    className="flex items-center gap-2 px-5 py-2.5 bg-[#E50914] hover:bg-red-700 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow-lg transition-all active:scale-95 cursor-pointer"
                  >
                    {addingPlatform ? (
                      <>
                        <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                        <span>Registrando plataforma...</span>
                      </>
                    ) : (
                      <>
                        <Plus className="w-4 h-4" />
                        <span>Registrar y Conectar Plataforma</span>
                      </>
                    )}
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      )}

      {/* Modal de Confirmación Estilizado (Reemplazo total de window.confirm) */}
      {confirmModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in fade-in duration-200">
          <div className="bg-[#181818] border border-zinc-800 rounded-2xl p-6 max-w-md w-full shadow-2xl space-y-4">
            <div className="flex items-center gap-3">
              <div
                className={`p-2.5 rounded-xl ${
                  confirmModal.danger ? 'bg-red-950/60 text-red-400 border border-red-500/30' : 'bg-zinc-800 text-white'
                }`}
              >
                <AlertTriangle className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-base font-bold text-white">{confirmModal.title}</h3>
                <p className="text-xs text-zinc-400 mt-0.5">{confirmModal.message}</p>
              </div>
            </div>

            <div className="flex items-center justify-end gap-3 pt-3 border-t border-zinc-800">
              <button
                type="button"
                onClick={() => setConfirmModal(null)}
                className="px-4 py-2 text-xs font-semibold text-zinc-400 hover:text-white transition-colors cursor-pointer"
              >
                Cancelar
              </button>
              <button
                type="button"
                onClick={confirmModal.onConfirm}
                className={`px-4 py-2 text-xs font-bold rounded-xl transition-all active:scale-95 cursor-pointer ${
                  confirmModal.danger
                    ? 'bg-red-600 hover:bg-red-700 text-white shadow-lg shadow-red-900/30'
                    : 'bg-zinc-800 hover:bg-zinc-700 text-white'
                }`}
              >
                {confirmModal.confirmText || 'Confirmar'}
              </button>
            </div>
          </div>
        </div>
      )}
      {/* Modal 3: Explorador Interactivo de Carpetas del Servidor (Server Directory Picker) */}
      <FolderPickerModal
        isOpen={folderPickerOpen}
        onClose={() => setFolderPickerOpen(false)}
        initialPath={
          folderPickerTarget === 'connect'
            ? connectForm.storage_path || '/storage/media'
            : addForm.storage_path || '/storage/media'
        }
        onSelect={(chosenPath) => {
          if (folderPickerTarget === 'connect') {
            setConnectForm((prev) => ({ ...prev, storage_path: chosenPath }));
          } else {
            setAddForm((prev) => ({ ...prev, storage_path: chosenPath }));
          }
          showToast('success', `Ruta asignada: ${chosenPath}`, 'Directorio Seleccionado');
        }}
      />
    </div>
  );
}
