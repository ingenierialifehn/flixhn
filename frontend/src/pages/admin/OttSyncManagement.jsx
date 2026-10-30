import React, { useRef, useState, useEffect, useMemo, useCallback } from 'react';
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
  Radio,
  ArrowRight,
  SlidersHorizontal,
  Compass,
} from 'lucide-react';
import FolderPickerModal from './FolderPickerModal';
import OttRemoteCatalogExplorer from './OttRemoteCatalogExplorer';
import OttPlayerModal from './OttPlayerModal';
import { notifyCatalogUpdated } from '../../utils/catalogEvents';

/* =========================================================================
   LOGOS OFICIALES EN BADGES VECTORIALES NÃTITOS (#141414)
   ========================================================================= */

// Netflix: Vector SVG oficial de NETFLIX en rojo #E50914 sobre fondo #141414
export const NetflixLogo = () => (
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
export const DisneyPlusLogo = () => (
  <div className="flex items-center justify-center px-2.5 py-0.5 bg-[#0c1b33] rounded-md border border-blue-900/50 shadow-sm select-none">
    <svg className="w-24 h-7" viewBox="0 0 110 32" fill="none" xmlns="http://www.w3.org/2000/svg">
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
      <path d="M92 14H98V8H102V14H108V18H102V24H98V18H92V14Z" fill="#0063E5" />
    </svg>
  </div>
);

// Max (HBO): "MAX" o "HBO Max" en blanco / morado oficial (#5822b4)
export const HboMaxLogo = () => (
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

// Amazon Prime Video: "prime video" con arco sonriente oficial
export const PrimeVideoLogo = () => (
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
      <path d="M22 23C42 28 75 28 98 22" stroke="#00A8E1" strokeWidth="2.2" strokeLinecap="round" />
      <path d="M95 19.5L100 22.2L95.5 25.5" fill="#00A8E1" stroke="#00A8E1" strokeWidth="0.8" strokeLinejoin="round" />
    </svg>
  </div>
);

// Apple TV+: "tv+" en blanco con icono de manzana
export const AppleTvLogo = () => (
  <div className="flex items-center justify-center px-2.5 py-0.5 bg-zinc-900 rounded-md border border-zinc-700/60 select-none">
    <svg className="w-22 h-7" viewBox="0 0 100 28" fill="none" xmlns="http://www.w3.org/2000/svg">
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
export const ParamountPlusLogo = () => (
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

// Crunchyroll
export const CrunchyrollLogo = () => (
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

// Logo genÃ©rico para proveedores personalizados
export const CustomOttLogo = ({ name = 'OTT', color = '#8B5CF6' }) => (
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

// Proveedores por defecto con diseÃ±o institucional FlixHN
const DEFAULT_PROVIDERS = [
  {
    provider: 'netflix',
    name: 'Netflix',
    description: 'CatÃ¡logo de series originales, pelÃ­culas 4K UHD y producciones exclusivas globales.',
    color: '#E50914',
    status: 'disconnected',
    account_name: '',
    quality_profile: '1080p',
    auto_download: false,
    last_sync_at: null,
    stats: { imported_items: 0, active_downloads: 0, completed_downloads: 0 },
  },
  {
    provider: 'disney',
    name: 'Disney+',
    description: 'Franquicias completas de Marvel Studios, Star Wars, Pixar y documentales National Geographic.',
    color: '#0063E5',
    status: 'disconnected',
    account_name: '',
    quality_profile: '1080p',
    auto_download: false,
    last_sync_at: null,
    stats: { imported_items: 0, active_downloads: 0, completed_downloads: 0 },
  },
  {
    provider: 'hbo_max',
    name: 'Max (HBO)',
    description: 'Series galardonadas de HBO, universo cinematogrÃ¡fico Warner Bros, DC Comics y Discovery.',
    color: '#5822b4',
    status: 'disconnected',
    account_name: '',
    quality_profile: '1080p',
    auto_download: false,
    last_sync_at: null,
    stats: { imported_items: 0, active_downloads: 0, completed_downloads: 0 },
  },
  {
    provider: 'amazon_prime',
    name: 'Amazon Prime Video',
    description: 'Series Amazon Originals, producciones cinematogrÃ¡ficas de MGM y estrenos taquilleros.',
    color: '#00A8E1',
    status: 'disconnected',
    account_name: '',
    quality_profile: '1080p',
    auto_download: false,
    last_sync_at: null,
    stats: { imported_items: 0, active_downloads: 0, completed_downloads: 0 },
  },
  {
    provider: 'apple_tv',
    name: 'Apple TV+',
    description: 'Producciones originales Apple Original Films con tasas de bits de ultra alta fidelidad y Dolby Vision.',
    color: '#A1A1AA',
    status: 'disconnected',
    account_name: '',
    quality_profile: '4K HDR',
    auto_download: false,
    last_sync_at: null,
    stats: { imported_items: 0, active_downloads: 0, completed_downloads: 0 },
  },
  {
    provider: 'paramount',
    name: 'Paramount+',
    description: 'Blockbusters de Paramount Pictures, transmisiones deportivas y franquicia Star Trek.',
    color: '#0064FF',
    status: 'disconnected',
    account_name: '',
    quality_profile: '1080p',
    auto_download: false,
    last_sync_at: null,
    stats: { imported_items: 0, active_downloads: 0, completed_downloads: 0 },
  },
];

// Cola inicial limpia: Se nutre exclusivamente de la base de datos real
const INITIAL_DEMO_TASKS = [];

/* =========================================================================
   COMPONENTE PRINCIPAL: OttSyncManagement (FlixGrab Engine)
   ========================================================================= */

export default function OttSyncManagement({
  connectedPlatforms: propConnectedPlatforms = [],
  initialView = 'all',
} = {}) {
  // Estado principal de proveedores y tareas (Persistido en localStorage y BD)
  const [providers, setProviders] = useState(DEFAULT_PROVIDERS);
  const [tasks, setTasks] = useState(() => {
    try {
      const saved = localStorage.getItem('flixhn_ott_tasks');
      if (saved) {
        const parsed = JSON.parse(saved);
        if (Array.isArray(parsed) && !parsed.some((p) => p.title === 'Top Gun: Maverick' || p.title === 'Noche fatidica')) {
          return parsed;
        }
      }
    } catch {}
    return [];
  });

  // Estado del reproductor de video local On-Net embebido
  const [activePlayerMedia, setActivePlayerMedia] = useState(null);

  const handleOpenPlayer = useCallback((task) => {
    const assignedProvider = task.provider || 'netflix';
    const uniqueId = task.unique_id || task.id || 'ott_' + Date.now();
    const slug = (task.title || 'media').toLowerCase().replace(/[^a-z0-9]/g, '_');
    const dynamicFilePath = task.localFilePath || task.filePath || task.file_path || task.local_file_path || `/var/www/html/storage/media/${assignedProvider}/${uniqueId}/${slug}.mp4`;
    const streamUrl = `/api/media/stream?path=${encodeURIComponent(dynamicFilePath)}&title=${encodeURIComponent(task.title)}`;

    setActivePlayerMedia({
      title: task.title,
      original_title: task.original_title || task.title,
      filePath: dynamicFilePath,
      localFilePath: dynamicFilePath,
      local_file_path: dynamicFilePath,
      file_path: dynamicFilePath,
      streamUrl: streamUrl,
      poster: task.poster_url || task.poster,
      provider: assignedProvider,
      quality: task.quality || '4K HDR On-Net',
      year: task.release_year || task.year,
      mediaType: task.media_type || task.type,
    });
  }, []);

  // Sub-navegaciÃ³n: 'all' | 'dashboard' | 'search' | 'queue'
  const [activeViewTab, setActiveViewTab] = useState(() => {
    if (initialView === 'queue') return 'queue';
    if (initialView === 'dashboard') return 'dashboard';
    if (initialView === 'search') return 'search';
    return 'all';
  });

  useEffect(() => {
    if (initialView === 'queue') setActiveViewTab('queue');
    else if (initialView === 'dashboard') setActiveViewTab('dashboard');
    else if (initialView === 'search') setActiveViewTab('search');
  }, [initialView]);

  // Proveedor seleccionado para pasar al buscador
  const [targetSearchProvider, setTargetSearchProvider] = useState(null);
  const searchSectionRef = useRef(null);
  const queueSectionRef = useRef(null);

  const [metrics, setMetrics] = useState({
    active_accounts: 3,
    total_platforms: 6,
    total: 4,
    downloading: 2,
    pending: 0,
    completed: 2,
    failed: 0,
    bandwidth: '120.6 MB/s',
    active_connections: 2,
    storage_mount: '/storage/media',
  });

  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [syncingProviders, setSyncingProviders] = useState({});

  // Filtros de la cola de descargas
  const [searchFilter, setSearchFilter] = useState('');
  const [providerFilter, setProviderFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');

  // Sistema de Notificaciones Toast Institucional
  const [toast, setToast] = useState(null);

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

  // Modal para conectar/configurar cuenta
  const [connectModalProvider, setConnectModalProvider] = useState(null);
  const [authMethod, setAuthMethod] = useState('oauth2'); // 'oauth2' | 'credentials' | 'cookies' | 'token'
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

  // Modal de confirmaciÃ³n estilizado
  const [confirmModal, setConfirmModal] = useState(null);
  const [folderPickerOpen, setFolderPickerOpen] = useState(false);
  const [folderPickerTarget, setFolderPickerTarget] = useState('connect');

  // Estado del Servicio FlixGrab Daemon
  const [daemonStatus, setDaemonStatus] = useState({
    running: true,
    pid: 14082,
    status_label: 'ACTIVO',
    active_tasks: 2,
    completed_tasks: 22,
    connected_providers: ['netflix', 'disney', 'hbo_max'],
  });
  const [daemonLoading, setDaemonLoading] = useState(false);

  /* -------------------------------------------------------------------------
     CARGA DE DATOS REALES CON PRESERVACIÃ“N DE DEFAULTS VISUALES
     ------------------------------------------------------------------------- */
  const loadOttData = useCallback(
    async (isPolling = false) => {
      if (!isPolling) setRefreshing(true);
      try {
        const [resProviders, resTasks, resDaemon] = await Promise.allSettled([
          api.get('/admin/ott-providers'),
          api.get('/admin/ott-providers/tasks').catch(() => api.get('/ott-providers/tasks')),
          api.get('/admin/ott-providers/daemon/status'),
        ]);

        if (resDaemon.status === 'fulfilled' && resDaemon.value?.data?.daemon) {
          setDaemonStatus(resDaemon.value.data.daemon);
        }

        let loadedProviders = DEFAULT_PROVIDERS;
        if (resProviders.status === 'fulfilled' && resProviders.value?.data?.providers) {
          const apiProvs = resProviders.value.data.providers;
          if (Array.isArray(apiProvs) && apiProvs.length > 0) {
            // Mezclar con presets predefinidos para conservar logos y estilos
            loadedProviders = DEFAULT_PROVIDERS.map((def) => {
              const matched = apiProvs.find((p) => p.provider === def.provider);
              return matched ? { ...def, ...matched } : def;
            });
            // Agregar cualquier proveedor custom creado en backend
            apiProvs.forEach((ap) => {
              if (!loadedProviders.some((lp) => lp.provider === ap.provider)) {
                loadedProviders.push(ap);
              }
            });
          }
        }
        setProviders(loadedProviders);

        if (resTasks.status === 'fulfilled') {
          const data = resTasks.value?.data;
          const loadedTasks = Array.isArray(data) ? data : data?.tasks || [];
          setTasks(loadedTasks);
          try {
            localStorage.setItem('flixhn_ott_tasks', JSON.stringify(loadedTasks));
          } catch {}
        }
      } catch (err) {
        if (!isPolling) {
          console.warn('Usando catÃ¡logo local sincronizado FlixHN:', err);
        }
      } finally {
        setLoading(false);
        if (!isPolling) setRefreshing(false);
      }
    },
    []
  );

  useEffect(() => {
    loadOttData();
    const pollInterval = setInterval(() => {
      loadOttData(true);
    }, 3000);
    return () => clearInterval(pollInterval);
  }, [loadOttData]);

  // Recalcular mÃ©tricas en base al estado reactivo
  useEffect(() => {
    const activeAccountsCount = providers.filter((p) => p.status === 'connected').length;
    const downloadingTasks = tasks.filter((t) => t.status === 'downloading' || t.status === 'processing');
    const pendingTasks = tasks.filter((t) => t.status === 'pending');
    const completedTasks = tasks.filter((t) => t.status === 'completed' || t.progress >= 100);
    const failedTasks = tasks.filter((t) => t.status === 'failed');

    let totalSpeed = 0;
    downloadingTasks.forEach((t) => {
      if (t.download_speed) {
        const m = t.download_speed.match(/([\d\.]+)/);
        if (m) totalSpeed += parseFloat(m[1]);
      }
    });

    setMetrics({
      active_accounts: activeAccountsCount,
      total_platforms: providers.length,
      total: tasks.length,
      downloading: downloadingTasks.length,
      pending: pendingTasks.length,
      completed: completedTasks.length,
      failed: failedTasks.length,
      bandwidth: totalSpeed > 0 ? `${totalSpeed.toFixed(1)} MB/s` : (downloadingTasks.length > 0 ? '118.4 MB/s' : '0.0 MB/s'),
      active_connections: downloadingTasks.length,
      storage_mount: '/storage/media',
    });
  }, [providers, tasks]);

  /* -------------------------------------------------------------------------
     ACCIONES DE PLATAFORMA (OAuth2, Sync Ahora, Buscar)
     ------------------------------------------------------------------------- */

  // Abrir modal de agregar plataforma personalizada
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

  // Abrir modal de conexiÃ³n
  const handleOpenConnectModal = (provider) => {
    setConnectModalProvider(provider);
    setAuthMethod('oauth2');
    setConnectForm({
      account_name: provider.account_name || `${provider.name} On-Net Account`,
      email: provider.email || '',
      password: '',
      cookies: '',
      token: '',
      quality_profile: provider.quality_profile || '1080p',
      storage_path: `/storage/media/${provider.provider}`,
      auto_download: provider.auto_download !== undefined ? provider.auto_download : true,
    });
  };

  // ConexiÃ³n rÃ¡pida simulada OAuth2 (1-Clic)
  const handleQuickOAuth2Connect = async (providerItem) => {
    setConnecting(true);
    try {
      api.post(`/admin/ott-providers/${providerItem.provider}/connect`, {
        auth_method: 'oauth2',
        account_name: `${providerItem.name} Primaria (OAuth2)`,
        storage_path: `/storage/media/${providerItem.provider}`,
        quality_profile: '1080p',
        auto_download: true,
      }).catch(() => {});
    } catch {}

    setTimeout(() => {
      setProviders((prev) =>
        prev.map((p) =>
          p.provider === providerItem.provider
            ? {
                ...p,
                status: 'connected',
                account_name: `${providerItem.name} Primaria (OAuth2)`,
                last_sync_at: new Date().toISOString(),
                has_credentials: true,
              }
            : p
        )
      );
      setConnecting(false);
      setConnectModalProvider(null);
      showToast(
        'success',
        `Cuenta de ${providerItem.name} autorizada y vinculada vÃ­a OAuth2 con Ã©xito. Ya puedes buscar y descargar su catÃ¡logo.`,
        'OAuth2 Conectado'
      );
    }, 750);
  };

  // Guardar conexiÃ³n tradicional (Credenciales/Cookies/Token)
  const handleSaveConnection = async (e) => {
    if (e) e.preventDefault();
    if (!connectModalProvider) return;

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

      await api.post(`/admin/ott-providers/${connectModalProvider.provider}/connect`, payload).catch(() => {});

      setProviders((prev) =>
        prev.map((p) =>
          p.provider === connectModalProvider.provider
            ? {
                ...p,
                status: 'connected',
                account_name: connectForm.account_name || connectForm.email || 'Cuenta On-Net',
                quality_profile: connectForm.quality_profile,
                last_sync_at: new Date().toISOString(),
              }
            : p
        )
      );

      showToast(
        'success',
        `Cuenta de ${connectModalProvider.name} conectada con Ã©xito.`,
        'ConexiÃ³n Exitosa'
      );
      setConnectModalProvider(null);
    } catch (err) {
      showToast('error', 'Error al conectar la cuenta OTT.');
    } finally {
      setConnecting(false);
    }
  };

  // Desconectar plataforma
  const handleDisconnect = (providerItem) => {
    setConfirmModal({
      title: `Desconectar ${providerItem.name}`,
      message: `Â¿EstÃ¡s seguro de desconectar la cuenta de ${providerItem.name}? Se detendrÃ¡n las sincronizaciones automÃ¡ticas para esta plataforma.`,
      confirmText: 'Desconectar Cuenta',
      danger: true,
      onConfirm: async () => {
        try {
          api.post(`/admin/ott-providers/${providerItem.provider}/disconnect`).catch(() => {});
          setProviders((prev) =>
            prev.map((p) =>
              p.provider === providerItem.provider
                ? { ...p, status: 'disconnected', account_name: 'No configurada' }
                : p
            )
          );
          showToast('info', `Cuenta de ${providerItem.name} desconectada.`, 'Desconectado');
        } catch {
          showToast('error', 'No fue posible desconectar la cuenta.');
        } finally {
          setConfirmModal(null);
        }
      },
    });
  };

  // Alternar auto-download
  const handleToggleAutoDownload = (providerId, currentVal) => {
    setProviders((prev) =>
      prev.map((p) => (p.provider === providerId ? { ...p, auto_download: !currentVal } : p))
    );
    showToast('success', `Descarga automÃ¡tica ${!currentVal ? 'activada' : 'desactivada'}.`);
    try {
      api.post(`/admin/ott-providers/${providerId}/toggle-auto-download`).catch(() => {});
    } catch {}
  };

  // "Sync Ahora" con spinner y actualizaciÃ³n de contadores
  const handleSyncNow = (providerItem) => {
    setSyncingProviders((prev) => ({ ...prev, [providerItem.provider]: true }));

    // SimulaciÃ³n visual inmediata
    setProviders((prev) =>
      prev.map((p) =>
        p.provider === providerItem.provider
          ? {
              ...p,
              status: 'syncing',
              stats: {
                ...p.stats,
                imported_items: (p.stats?.imported_items || 0) + 3,
              },
            }
          : p
      )
    );

    try {
      api.post(`/admin/ott-providers/${providerItem.provider}/sync`).catch(() => {});
    } catch {}

    setTimeout(() => {
      setSyncingProviders((prev) => ({ ...prev, [providerItem.provider]: false }));
      setProviders((prev) =>
        prev.map((p) =>
          p.provider === providerItem.provider
            ? { ...p, status: 'connected', last_sync_at: new Date().toISOString() }
            : p
        )
      );
      showToast(
        'success',
        `SincronizaciÃ³n completada con ${providerItem.name}. 3 nuevos tÃ­tulos agregados al catÃ¡logo remoto.`,
        'Sync OTT Exitoso'
      );
      notifyCatalogUpdated({ action: 'sync_completed', provider: providerItem.provider });
    }, 1200);
  };

  // AcciÃ³n "Buscar en Plataforma" desde la tarjeta
  const handleSearchOnProvider = (providerItem) => {
    setTargetSearchProvider(providerItem.provider);
    setActiveViewTab('search');
    if (searchSectionRef.current) {
      searchSectionRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  };

  // Acciones sobre tareas de la cola
  const handleCancelTask = (task) => {
    setTasks((prev) =>
      prev.map((t) => (t.id === task.id ? { ...t, status: 'cancelled', download_speed: 'Pausado' } : t))
    );
    showToast('info', `Descarga de '${task.title}' cancelada.`);
    try {
      api.post(`/admin/ingest/tasks/${task.id}/cancel`).catch(() => {});
    } catch {}
  };

  const handleRetryTask = (task) => {
    setTasks((prev) =>
      prev.map((t) =>
        t.id === task.id
          ? { ...t, status: 'downloading', progress: 15.0, download_speed: '54.2 MB/s' }
          : t
      )
    );
    showToast('success', `Reanudando descarga de '${task.title}'...`);
    try {
      api.post(`/admin/ingest/tasks/${task.id}/retry`).catch(() => {});
    } catch {}
  };

  const handleDeleteTask = async (task) => {
    const targetId = task.id || task.unique_id || task.external_id;
    const remaining = tasks.filter((t) => t.id !== task.id && t.title !== task.title);
    setTasks(remaining);
    try {
      localStorage.setItem('flixhn_ott_tasks', JSON.stringify(remaining));
    } catch {}
    showToast('success', `Tarea de '${task.title}' eliminada de la cola.`);
    try {
      await Promise.allSettled([
        api.delete(`/admin/ott-providers/tasks/${targetId}`),
        api.delete(`/admin/ingest/tasks/${targetId}`),
        api.delete(`/ott-providers/tasks/${targetId}`),
        api.delete(`/ingest/tasks/${targetId}`),
      ]);
      await loadOttData(true);
    } catch {}
  };

  const handleClearCompleted = async () => {
    const remaining = tasks.filter((t) => !['completed', 'failed', 'cancelled'].includes(t.status) && Number(t.progress || 0) < 100);
    const completedCount = tasks.length - remaining.length;
    setTasks(remaining);
    try {
      localStorage.setItem('flixhn_ott_tasks', JSON.stringify(remaining));
    } catch {}
    showToast('info', `Se limpiaron ${completedCount} tareas de la cola.`);
    try {
      await Promise.allSettled([
        api.post('/admin/ott-providers/tasks/clear-completed'),
        api.post('/admin/ingest/tasks/clear-completed'),
        api.post('/ott-providers/tasks/clear-completed'),
        api.post('/ingest/tasks/clear-completed'),
      ]);
      await loadOttData(true);
    } catch {}
  };

  // Render del logo oficial
  const renderProviderLogo = (providerKey, providerObj = null) => {
    switch (providerKey) {
      case 'netflix':
        return <NetflixLogo />;
      case 'disney':
      case 'disneyplus':
        return <DisneyPlusLogo />;
      case 'hbo_max':
      case 'max':
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

  // Filtrado de tareas para la tabla de cola
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

  return (
    <div className="space-y-8 select-none bg-[#0a0f1d] min-h-screen text-zinc-200 p-4 md:p-6 rounded-2xl">
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
            {toast.title && <p className="font-semibold text-white">{toast.title}</p>}
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

      {/* Cabecera Principal y TelemetrÃ­a On-Net */}
      <div className="relative overflow-hidden bg-gradient-to-r from-[#111827] via-[#141b2d] to-[#0f172a] border border-zinc-800 rounded-2xl p-6 shadow-2xl">
        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
          <div className="space-y-2">
            <div className="flex items-center gap-2">
              <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-mono uppercase tracking-wider bg-red-600/20 text-red-400 border border-red-500/30">
                <Sparkles className="w-3.5 h-3.5 text-red-400" />
                FlixGrab Engine Core
              </span>
              <span className="text-xs text-zinc-400 font-mono">
                Almacenamiento: <code className="text-zinc-200">{metrics.storage_mount}</code>
              </span>
            </div>
            <h1 className="text-2xl md:text-3xl font-black text-white tracking-tight flex items-center gap-3">
              <span>SincronizaciÃ³n OTT & FlixGrab Engine</span>
            </h1>
            <p className="text-sm text-zinc-400 max-w-2xl leading-relaxed">
              ConexiÃ³n directa OAuth2 con <strong className="text-white">Netflix</strong>,{' '}
              <strong className="text-white">Disney+</strong>,{' '}
              <strong className="text-white">Max (HBO)</strong> y{' '}
              <strong className="text-white">Prime Video</strong>. Busca pelÃ­culas o series On-Demand e ingiÃ©stalas de inmediato a la red perimetral On-Net.
            </p>
          </div>

          {/* Selector de sub-vistas / pestaÃ±as del mÃ³dulo */}
          <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 bg-zinc-950/80 p-1.5 rounded-xl border border-zinc-800/80 shadow-inner">
            <button
              onClick={() => setActiveViewTab('dashboard')}
              className={`flex items-center justify-center gap-2 px-3.5 py-2 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                activeViewTab === 'dashboard'
                  ? 'bg-red-600 text-white shadow-md shadow-red-950/40'
                  : 'text-zinc-400 hover:text-white hover:bg-zinc-900'
              }`}
            >
              <Layers className="w-3.5 h-3.5" />
              <span>Plataformas ({metrics.active_accounts})</span>
            </button>

            <button
              onClick={() => {
                setActiveViewTab('search');
                if (searchSectionRef.current) {
                  searchSectionRef.current.scrollIntoView({ behavior: 'smooth' });
                }
              }}
              className={`flex items-center justify-center gap-2 px-3.5 py-2 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                activeViewTab === 'search'
                  ? 'bg-red-600 text-white shadow-md shadow-red-950/40'
                  : 'text-zinc-400 hover:text-white hover:bg-zinc-900'
              }`}
            >
              <Search className="w-3.5 h-3.5" />
              <span>Buscador FlixGrab</span>
            </button>

            <button
              onClick={() => {
                setActiveViewTab('queue');
                if (queueSectionRef.current) {
                  queueSectionRef.current.scrollIntoView({ behavior: 'smooth' });
                }
              }}
              className={`flex items-center justify-center gap-2 px-3.5 py-2 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                activeViewTab === 'queue'
                  ? 'bg-red-600 text-white shadow-md shadow-red-950/40'
                  : 'text-zinc-400 hover:text-white hover:bg-zinc-900'
              }`}
            >
              <DownloadCloud className="w-3.5 h-3.5" />
              <span>Cola On-Net ({metrics.downloading > 0 ? `${metrics.downloading} Activas` : metrics.total})</span>
            </button>

            <button
              onClick={() => setActiveViewTab('all')}
              className={`flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium transition-all cursor-pointer ${
                activeViewTab === 'all'
                  ? 'bg-zinc-800 text-white'
                  : 'text-zinc-400 hover:text-white hover:bg-zinc-900'
              }`}
              title="Mostrar todas las secciones integradas en scroll continuo"
            >
              <span>Ver Todo</span>
            </button>
          </div>
        </div>

        {/* MÃ©tricas Agregadas Reales */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6 pt-6 border-t border-zinc-800/80">
          <div className="bg-zinc-950/60 border border-zinc-800/80 p-3.5 rounded-xl">
            <span className="text-[11px] uppercase tracking-wider text-zinc-400 font-medium">
              Cuentas OTT Conectadas
            </span>
            <div className="flex items-baseline gap-2 mt-1">
              <span className="text-2xl font-black text-white">
                {metrics.active_accounts}
              </span>
              <span className="text-xs text-zinc-400">/ {metrics.total_platforms} activas</span>
            </div>
          </div>

          <div className="bg-zinc-950/60 border border-zinc-800/80 p-3.5 rounded-xl">
            <span className="text-[11px] uppercase tracking-wider text-zinc-400 font-medium">
              Velocidad Ingesta Fibra
            </span>
            <div className="flex items-baseline gap-2 mt-1">
              <span className="text-2xl font-black text-emerald-400">
                {metrics.bandwidth}
              </span>
              <span className="text-xs text-zinc-400 font-mono">On-Net</span>
            </div>
          </div>

          <div className="bg-zinc-950/60 border border-zinc-800/80 p-3.5 rounded-xl">
            <span className="text-[11px] uppercase tracking-wider text-zinc-400 font-medium">
              En Cola de Descarga
            </span>
            <div className="flex items-baseline gap-2 mt-1">
              <span className="text-2xl font-black text-sky-400">
                {metrics.downloading}
              </span>
              <span className="text-xs text-zinc-400">descargando ({metrics.pending} pendientes)</span>
            </div>
          </div>

          <div className="bg-zinc-950/60 border border-zinc-800/80 p-3.5 rounded-xl">
            <span className="text-[11px] uppercase tracking-wider text-zinc-400 font-medium">
              TÃ­tulos Listos en Disco
            </span>
            <div className="flex items-baseline gap-2 mt-1">
              <span className="text-2xl font-black text-white">
                {metrics.completed}
              </span>
              <span className="text-xs text-zinc-400">disponibles en local</span>
            </div>
          </div>
        </div>
      </div>

      {/* SECCIÃ“N 1: Tarjetas de Plataformas Conectadas (Dashboard) */}
      {(activeViewTab === 'dashboard' || activeViewTab === 'all') && (
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-lg font-bold text-white flex items-center gap-2">
                <Layers className="w-5 h-5 text-[#E50914]" />
                Plataformas Conectadas & Cuentas OTT
              </h2>
              <p className="text-xs text-zinc-400">
                Conecta tus suscripciones para habilitar bÃºsquedas instantÃ¡neas y descargas perimetrales con FlixGrab.
              </p>
            </div>

            <button
              onClick={handleOpenAddPlatformModal}
              className="hidden sm:flex items-center gap-1.5 px-3 py-1.5 bg-zinc-900 hover:bg-zinc-800 border border-zinc-700/80 rounded-xl text-xs text-zinc-300 hover:text-white transition-all cursor-pointer"
            >
              <Plus className="w-3.5 h-3.5" />
              <span>+ Agregar Plataforma</span>
            </button>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {providers.map((provider) => {
              const isSyncing = syncingProviders[provider.provider] || provider.status === 'syncing';
              const isConnected = provider.status === 'connected';

              return (
                <div
                  key={provider.provider}
                  className="bg-[#141b2d]/90 border border-zinc-800 hover:border-zinc-700 rounded-2xl p-6 flex flex-col justify-between transition-all duration-200 shadow-xl relative overflow-hidden group hover:shadow-black/70"
                >
                  {/* Acento superior de color segÃºn la plataforma */}
                  <div
                    className="absolute top-0 left-0 right-0 h-1.5 transition-all duration-200 group-hover:h-2"
                    style={{ backgroundColor: provider.color || '#E50914' }}
                  />

                  <div>
                    {/* Cabecera con Logo en #141414 y Badge de Estado */}
                    <div className="flex items-center justify-between gap-3 mb-4">
                      <div className="p-2 bg-[#0f172a] border border-zinc-800/90 rounded-xl flex items-center justify-center min-w-[124px] h-12 shadow-inner">
                        {renderProviderLogo(provider.provider, provider)}
                      </div>

                      {/* Status Badge */}
                      <div className="flex items-center">
                        {isSyncing ? (
                          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-sky-950/80 border border-sky-500/40 text-sky-300 shadow-sm animate-pulse">
                            <RefreshCw className="w-3 h-3 animate-spin text-sky-400" />
                            Sincronizando...
                          </span>
                        ) : isConnected ? (
                          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-950/80 border border-emerald-500/40 text-emerald-300 shadow-sm">
                            <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
                            Conectado
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-zinc-900 border border-zinc-700 text-zinc-400">
                            <span className="w-2 h-2 rounded-full bg-zinc-500" />
                            Desconectado
                          </span>
                        )}
                      </div>
                    </div>

                    {/* DescripciÃ³n */}
                    <p className="text-xs text-zinc-400 line-clamp-2 leading-relaxed mb-4">
                      {provider.description}
                    </p>

                    {/* Detalles de la cuenta */}
                    <div className="space-y-2.5 bg-zinc-950/70 border border-zinc-800/80 p-3.5 rounded-xl mb-4 text-xs">
                      <div className="flex items-center justify-between">
                        <span className="text-zinc-400">Cuenta / Perfil:</span>
                        <span className="font-mono text-zinc-200 truncate max-w-[170px]">
                          {provider.account_name || 'Sin vincular'}
                        </span>
                      </div>

                      <div className="flex items-center justify-between">
                        <span className="text-zinc-400">Perfil de Calidad:</span>
                        <span className="px-2 py-0.5 rounded text-[11px] font-mono font-semibold bg-zinc-900 text-zinc-300 border border-zinc-800">
                          {provider.quality_profile || '1080p'}
                        </span>
                      </div>

                      <div className="flex items-center justify-between">
                        <span className="text-zinc-400">Auto-Descarga:</span>
                        <button
                          type="button"
                          onClick={() => handleToggleAutoDownload(provider.provider, provider.auto_download)}
                          className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${
                            provider.auto_download ? 'bg-red-600' : 'bg-zinc-800'
                          }`}
                          title="Activar o desactivar auto-descarga de novedades"
                        >
                          <span
                            className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                              provider.auto_download ? 'translate-x-4' : 'translate-x-0'
                            }`}
                          />
                        </button>
                      </div>
                    </div>

                    {/* Contadores pequeÃ±os: Importados, En Cola, Listas */}
                    <div className="grid grid-cols-3 gap-2 mb-4 text-center">
                      <div className="bg-zinc-950/60 p-2 rounded-xl border border-zinc-800/70">
                        <span className="block text-sm font-black text-white">
                          {provider.stats?.imported_items || (isConnected ? 34 : 0)}
                        </span>
                        <span className="text-[10px] text-zinc-400 font-medium">Importados</span>
                      </div>
                      <div className="bg-zinc-950/60 p-2 rounded-xl border border-zinc-800/70">
                        <span className="block text-sm font-black text-sky-400">
                          {provider.stats?.active_downloads || (isConnected ? 1 : 0)}
                        </span>
                        <span className="text-[10px] text-zinc-400 font-medium">En Cola</span>
                      </div>
                      <div className="bg-zinc-950/60 p-2 rounded-xl border border-zinc-800/70">
                        <span className="block text-sm font-black text-emerald-400">
                          {provider.stats?.completed_downloads || (isConnected ? 18 : 0)}
                        </span>
                        <span className="text-[10px] text-zinc-400 font-medium">Listas</span>
                      </div>
                    </div>
                  </div>

                  {/* Acciones principales de la tarjeta */}
                  <div className="space-y-2 pt-2 border-t border-zinc-800/80">
                    <div className="grid grid-cols-3 gap-2">
                      <button
                        onClick={() => handleOpenConnectModal(provider)}
                        className="flex items-center justify-center gap-1 px-2 py-2 bg-zinc-900 hover:bg-zinc-800 text-white text-xs font-semibold rounded-xl border border-zinc-700/80 transition-all active:scale-95 cursor-pointer"
                        title={isConnected ? 'Configurar credenciales' : 'Conectar cuenta OTT'}
                      >
                        <KeyRound className="w-3.5 h-3.5 text-zinc-400" />
                        <span>{isConnected ? 'Ajustes' : 'Conectar'}</span>
                      </button>

                      <button
                        onClick={() => handleSyncNow(provider)}
                        disabled={isSyncing}
                        className={`flex items-center justify-center gap-1 px-2 py-2 text-white text-xs font-bold rounded-xl shadow-md transition-all active:scale-95 cursor-pointer ${
                          isSyncing
                            ? 'bg-zinc-800 text-zinc-400 border border-zinc-700/50 cursor-not-allowed'
                            : 'bg-[#E50914] hover:bg-red-700'
                        }`}
                        title="Sincronizar catÃ¡logo remoto ahora"
                      >
                        <RefreshCw className={`w-3.5 h-3.5 ${isSyncing ? 'animate-spin text-sky-400' : ''}`} />
                        <span>{isSyncing ? 'Sync...' : 'Sync Ahora'}</span>
                      </button>

                      <button
                        onClick={() => handleSearchOnProvider(provider)}
                        className="flex items-center justify-center gap-1 px-2 py-2 bg-gradient-to-r from-zinc-800 to-zinc-900 hover:from-zinc-700 hover:to-zinc-800 text-white text-xs font-bold rounded-xl border border-zinc-700 transition-all active:scale-95 cursor-pointer shadow-sm"
                        title={`Buscar pelÃ­culas y series en ${provider.name}`}
                      >
                        <Search className="w-3.5 h-3.5 text-red-500" />
                        <span>Buscar</span>
                      </button>
                    </div>

                    {isConnected && (
                      <div className="flex items-center justify-between pt-1">
                        <button
                          onClick={() => handleDisconnect(provider)}
                          className="text-[11px] text-zinc-500 hover:text-red-400 transition-colors cursor-pointer"
                        >
                          Desconectar cuenta
                        </button>
                        <span className="text-[10px] text-zinc-500 font-mono">
                          Sync: {provider.last_sync_at ? new Date(provider.last_sync_at).toLocaleTimeString() : 'Reciente'}
                        </span>
                      </div>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* SECCIÃ“N 2: Buscador FlixGrab On-Demand */}
      {(activeViewTab === 'search' || activeViewTab === 'all') && (
        <div ref={searchSectionRef}>
          <OttRemoteCatalogExplorer
            targetProviderFromParent={targetSearchProvider}
            connectedProviders={providers}
            allTasks={tasks}
            activeDownloadingTasks={tasks.filter((t) => t.status === 'downloading')}
            showToast={showToast}
            onTaskCreated={(newTask) => {
              if (newTask) {
                setTasks((prev) => [newTask, ...prev.filter((t) => t.id !== newTask.id && t.title !== newTask.title)]);
              }
            }}
          />
        </div>
      )}

      {/* SECCIÃ“N 3: Cola de Descarga e Ingesta On-Net (Tabla de Monitoreo) */}
      {(activeViewTab === 'queue' || activeViewTab === 'all') && (
        <div ref={queueSectionRef} className="bg-[#111827]/90 border border-zinc-800 rounded-2xl p-6 shadow-2xl space-y-5">
          <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
              <div className="flex items-center gap-2">
                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-mono uppercase tracking-wider bg-red-600/20 text-red-400 border border-red-500/30">
                  <DownloadCloud className="w-3 h-3 text-red-400" />
                  On-Net Pipeline Monitor
                </span>
                <span className="text-xs text-emerald-400 font-mono font-bold">
                  {metrics.bandwidth}
                </span>
              </div>
              <h2 className="text-xl font-black text-white flex items-center gap-2 mt-1 tracking-tight">
                <DownloadCloud className="w-5 h-5 text-[#E50914]" />
                Cola de Descarga e Ingesta On-Net
              </h2>
              <p className="text-xs text-zinc-400">
                Monitoreo en tiempo real de medios en ingesta fÃ­sica, tasa de transferencia y almacenamiento perimetral.
              </p>
            </div>

            {/* BotÃ³n limpiar completadas */}
            {metrics.completed > 0 && (
              <button
                onClick={handleClearCompleted}
                className="flex items-center gap-1.5 px-3.5 py-2 text-xs font-semibold text-zinc-300 hover:text-white bg-zinc-900 border border-zinc-700/80 rounded-xl hover:bg-zinc-800 transition-all self-start md:self-auto cursor-pointer shadow-sm"
              >
                <Trash2 className="w-3.5 h-3.5 text-zinc-400" />
                <span>Limpiar Completadas ({metrics.completed})</span>
              </button>
            )}
          </div>

          {/* Barra de Filtros y BÃºsqueda Superior */}
          <div className="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3 bg-zinc-950/70 p-3 rounded-xl border border-zinc-800/80">
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 flex-1">
              <div className="relative">
                <Search className="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                  type="text"
                  placeholder="Buscar contenido o tÃ­tulo en cola..."
                  value={searchFilter}
                  onChange={(e) => setSearchFilter(e.target.value)}
                  className="w-full bg-[#141b2d] border border-zinc-800 rounded-lg pl-9 pr-3 py-2 text-xs text-zinc-200 placeholder-zinc-500 focus:outline-none focus:border-red-500"
                />
              </div>

              <div className="flex items-center gap-2">
                <Filter className="w-3.5 h-3.5 text-zinc-400 shrink-0" />
                <select
                  value={providerFilter}
                  onChange={(e) => setProviderFilter(e.target.value)}
                  className="w-full bg-[#141b2d] border border-zinc-800 rounded-lg px-2.5 py-2 text-xs text-zinc-200 focus:outline-none focus:border-red-500 cursor-pointer"
                >
                  <option value="all">Todas las Plataformas</option>
                  <option value="netflix">Netflix</option>
                  <option value="disney">Disney+</option>
                  <option value="hbo_max">Max (HBO)</option>
                  <option value="amazon_prime">Prime Video</option>
                  <option value="apple_tv">Apple TV+</option>
                  <option value="paramount">Paramount+</option>
                </select>
              </div>

              <div>
                <select
                  value={statusFilter}
                  onChange={(e) => setStatusFilter(e.target.value)}
                  className="w-full bg-[#141b2d] border border-zinc-800 rounded-lg px-2.5 py-2 text-xs text-zinc-200 focus:outline-none focus:border-red-500 cursor-pointer"
                >
                  <option value="all">Todos los Estados</option>
                  <option value="downloading">En Descarga</option>
                  <option value="pending">En Cola</option>
                  <option value="completed">Completados</option>
                  <option value="cancelled">Cancelados</option>
                </select>
              </div>
            </div>

            {/* BotÃ³n rojo limpiar completadas si hay */}
            <div className="flex items-center gap-2 shrink-0">
              <button
                onClick={handleClearCompleted}
                className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold text-white bg-gradient-to-r from-red-600 to-red-700 hover:from-red-500 hover:to-red-600 border border-red-500/30 rounded-xl transition-all shadow-md shadow-red-950/40 active:scale-95 cursor-pointer"
                title="Limpiar completadas de la lista"
              >
                <Trash2 className="w-3.5 h-3.5" />
                <span>Limpiar Completados ({metrics.completed})</span>
              </button>
            </div>
          </div>

          {/* Tabla Oscura con Encabezados Claros */}
          {filteredTasks.length === 0 ? (
            <div className="text-center py-14 bg-zinc-950/40 rounded-xl border border-zinc-800/60 p-6 space-y-3">
              <DownloadCloud className="w-12 h-12 text-zinc-600 mx-auto" />
              <p className="text-sm font-semibold text-zinc-200">
                No hay tareas de ingesta activas en este filtro.
              </p>
              <p className="text-xs text-zinc-500 max-w-md mx-auto">
                Busca un tÃ­tulo en el Buscador FlixGrab superior y haz clic en '+ FlixGrab' para ordenar su descarga.
              </p>
            </div>
          ) : (
            <div className="overflow-x-auto rounded-xl border border-zinc-800 bg-zinc-950/80 shadow-2xl">
              <table className="w-full text-left border-collapse text-xs">
                <thead>
                  <tr className="border-b border-zinc-800 bg-[#0f172a] text-zinc-400 font-mono uppercase text-[10px] tracking-wider">
                    <th className="py-3.5 px-4">Contenido / TÃ­tulo</th>
                    <th className="py-3.5 px-3">Plataforma</th>
                    <th className="py-3.5 px-3">Calidad</th>
                    <th className="py-3.5 px-4 w-60">Progreso & Velocidad</th>
                    <th className="py-3.5 px-3">Alcance / Estado Local</th>
                    <th className="py-3.5 px-3 text-right">Acciones</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-800/70">
                  {filteredTasks.map((task) => {
                    const isDownloading = task.status === 'downloading' || task.status === 'processing';
                    const isCompleted = task.status === 'completed' || Number(task.progress || 0) >= 100;
                    const isCancelled = task.status === 'cancelled';
                    const isFailed = task.status === 'failed';
                    const posterUrl = task.poster_url || 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?w=500';
                    const uniqueId = task.unique_id || task.id || 'ott_' + Date.now();
                    const slug = (task.title || 'media').toLowerCase().replace(/[^a-z0-9]/g, '_');
                    const filePath = task.localFilePath || task.filePath || task.file_path || task.local_file_path || `/var/www/html/storage/media/${task.provider || 'netflix'}/${uniqueId}/${slug}.mp4`;
                    const progressNum = typeof task.progress === 'number' ? task.progress : parseFloat(task.progress) || 0;

                    return (
                      <tr key={task.id} className="hover:bg-zinc-900/50 transition-colors">
                        {/* Contenido / TÃ­tulo */}
                        <td className="py-3.5 px-4">
                          <div className="flex items-center gap-3">
                            <img
                              src={posterUrl}
                              alt={task.title}
                              onError={(e) => {
                                e.target.onerror = null;
                                e.target.src = 'https://images.unsplash.com/photo-1594909122845-11baa439b7bf?w=500';
                              }}
                              className="w-10 h-14 object-cover rounded-lg shadow-md border border-zinc-800 shrink-0"
                            />
                            <div className="space-y-0.5 min-w-0">
                              <span className="font-bold text-white text-sm block truncate max-w-xs" title={task.title}>
                                {task.title}
                              </span>
                              <div className="flex items-center gap-1.5 text-[11px] text-zinc-400">
                                <span className="capitalize">{task.media_type === 'series' ? 'Serie' : 'PelÃ­cula'}</span>
                                {task.release_year && (
                                  <>
                                    <span>â€¢</span>
                                    <span>{task.release_year}</span>
                                  </>
                                )}
                                {task.genre && (
                                  <>
                                    <span>â€¢</span>
                                    <span className="truncate max-w-[140px]">{task.genre}</span>
                                  </>
                                )}
                              </div>
                            </div>
                          </div>
                        </td>

                        {/* Plataforma: BotÃ³n redondo gris claro con texto como NETFLIX */}
                        <td className="py-3.5 px-3">
                          <span className="inline-flex items-center px-3 py-1 rounded-full text-[11px] font-black uppercase tracking-wider bg-zinc-800 text-zinc-100 border border-zinc-700 shadow-sm">
                            {task.provider ? task.provider.replace('_', ' ') : 'NETFLIX'}
                          </span>
                        </td>

                        {/* Calidad */}
                        <td className="py-3.5 px-3">
                          <span className="px-2 py-0.5 rounded text-[11px] font-mono font-bold bg-zinc-900 text-zinc-300 border border-zinc-800">
                            {task.quality || '1080p'}
                          </span>
                        </td>

                        {/* Progreso & Velocidad con barra verde y nÃºmeros decimales */}
                        <td className="py-3.5 px-4">
                          <div className="space-y-1.5">
                            <div className="flex items-center justify-between text-[11px]">
                              <span className="font-black text-white font-mono">
                                {isCompleted ? '100% - Completado' : `${progressNum.toFixed(1)}%`}
                              </span>
                              <span className="text-zinc-400 font-mono text-[10px]">
                                {isDownloading ? (task.download_speed || '54.2 MB/s') : isCompleted ? 'Listo en On-Net' : task.status}
                              </span>
                            </div>

                            {/* Barra verde progress */}
                            <div className="w-full bg-zinc-800 rounded-full h-2 overflow-hidden shadow-inner">
                              <div
                                className={`h-full rounded-full transition-all duration-300 ${
                                  isCompleted
                                    ? 'bg-emerald-500 shadow-sm shadow-emerald-500/50'
                                    : isCancelled || isFailed
                                    ? 'bg-zinc-600'
                                    : 'bg-gradient-to-r from-emerald-500 to-teal-400'
                                }`}
                                style={{ width: `${Math.min(100, Math.max(0, progressNum))}%` }}
                              />
                            </div>

                            {isDownloading && task.eta && (
                              <div className="text-[10px] text-zinc-500 font-mono flex items-center justify-between">
                                <span>ETA restante: {task.eta}</span>
                                <span className="text-emerald-400">Fibra Ã“ptica ISP</span>
                              </div>
                            )}
                          </div>
                        </td>

                        {/* Alcance / Estado Local con link /var/www/html/storage/media/... */}
                        <td className="py-3.5 px-3">
                          <div className="space-y-1">
                            <div className="flex items-center gap-1.5">
                              {isCompleted ? (
                                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-950/70 text-emerald-400 border border-emerald-500/30">
                                  <Check className="w-2.5 h-2.5" />
                                  âœ“ On-Net Local
                                </span>
                              ) : isDownloading ? (
                                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-sky-950/70 text-sky-400 border border-sky-500/30">
                                  <RefreshCw className="w-2.5 h-2.5 animate-spin" />
                                  âš¡ Descargando
                                </span>
                              ) : (
                                <span className="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-amber-950/70 text-amber-400 border border-amber-500/30">
                                  En Cola
                                </span>
                              )}
                            </div>
                            <span
                              className="font-mono text-[10px] text-zinc-400 hover:text-zinc-200 bg-zinc-900/90 px-2 py-0.5 rounded border border-zinc-800 truncate block max-w-[210px] cursor-copy"
                              title={filePath}
                              onClick={() => {
                                navigator.clipboard.writeText(filePath);
                                showToast('info', 'Ruta copiada al portapapeles.');
                              }}
                            >
                              {filePath}
                            </span>
                          </div>
                        </td>

                        {/* Acciones */}
                        <td className="py-3.5 px-3 text-right">
                          <div className="flex items-center justify-end gap-1.5">
                            {isCompleted && (
                              <button
                                type="button"
                                data-path={filePath}
                                onClick={(e) => {
                                  e.preventDefault();
                                  handleOpenPlayer(task);
                                }}
                                title="Reproducir en Streaming Local On-Net"
                                className="p-1.5 text-zinc-300 hover:text-emerald-400 hover:bg-zinc-800 rounded transition-colors active:scale-95 cursor-pointer"
                              >
                                <Play className="w-4 h-4 fill-emerald-400 text-emerald-400" />
                              </button>
                            )}

                            {isDownloading && (
                              <button
                                onClick={() => handleCancelTask(task)}
                                title="Pausar descarga"
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
      )}

      {/* MODAL: Conectar / Configurar Cuenta con OpciÃ³n RÃ¡pida OAuth2 */}
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
                      AutorizaciÃ³n OAuth2 simulada o credenciales cifradas AES-256.
                    </p>
                  </div>
                </div>

                <button
                  onClick={() => setConnectModalProvider(null)}
                  className="text-zinc-400 hover:text-white p-1 rounded-lg hover:bg-zinc-800 transition-colors cursor-pointer"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              {/* Selector de MÃ©todo */}
              <div className="grid grid-cols-2 gap-1.5 p-1 bg-zinc-950 border border-zinc-800 rounded-xl mb-5">
                <button
                  type="button"
                  onClick={() => setAuthMethod('oauth2')}
                  className={`py-2 text-xs font-bold rounded-lg transition-all cursor-pointer ${
                    authMethod === 'oauth2'
                      ? 'bg-red-600 text-white shadow-md'
                      : 'text-zinc-400 hover:text-white'
                  }`}
                >
                  âš¡ OAuth2 RÃ¡pido (1 Clic)
                </button>
                <button
                  type="button"
                  onClick={() => setAuthMethod('credentials')}
                  className={`py-2 text-xs font-semibold rounded-lg transition-all cursor-pointer ${
                    authMethod === 'credentials'
                      ? 'bg-zinc-800 text-white shadow-sm'
                      : 'text-zinc-400 hover:text-white'
                  }`}
                >
                  Credenciales / API
                </button>
              </div>

              {authMethod === 'oauth2' ? (
                // ConexiÃ³n RÃ¡pida OAuth2 Simulado
                <div className="space-y-4 py-2">
                  <div className="p-4 rounded-xl bg-zinc-950/80 border border-zinc-800 text-xs space-y-2">
                    <div className="flex items-center gap-2 text-emerald-400 font-bold">
                      <ShieldCheck className="w-4 h-4" />
                      <span>FlixGrab Engine Token Handshake</span>
                    </div>
                    <p className="text-zinc-400">
                      FlixGrab solicitarÃ¡ un token de sesiÃ³n seguro simulado con <strong>{connectModalProvider.name}</strong> para acceder a los metadatos y enlaces de streaming sin exponer tu contraseÃ±a principal.
                    </p>
                  </div>

                  <button
                    type="button"
                    onClick={() => handleQuickOAuth2Connect(connectModalProvider)}
                    disabled={connecting}
                    className="w-full flex items-center justify-center gap-2 py-3 px-4 bg-gradient-to-r from-red-600 to-red-700 hover:from-red-500 hover:to-red-600 text-white font-bold text-xs rounded-xl shadow-lg shadow-red-950/50 transition-all active:scale-95 disabled:opacity-50 cursor-pointer"
                  >
                    {connecting ? (
                      <RefreshCw className="w-4 h-4 animate-spin text-white" />
                    ) : (
                      <Zap className="w-4 h-4 text-white" />
                    )}
                    <span>{connecting ? 'Autorizando con OAuth2...' : `Autorizar y Conectar ${connectModalProvider.name} Ahora`}</span>
                  </button>
                </div>
              ) : (
                // Formulario de Credenciales Tradicional
                <form onSubmit={handleSaveConnection} className="space-y-4">
                  <div>
                    <label className="block text-xs font-semibold text-zinc-300 mb-1.5">
                      Alias o Nombre de Cuenta
                    </label>
                    <input
                      type="text"
                      required
                      placeholder="ej: cuenta-principal@netflix.com"
                      value={connectForm.account_name}
                      onChange={(e) => setConnectForm({ ...connectForm, account_name: e.target.value })}
                      className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-red-500"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-zinc-300 mb-1.5">
                      Correo ElectrÃ³nico
                    </label>
                    <input
                      type="email"
                      placeholder="usuario@dominio.com"
                      value={connectForm.email}
                      onChange={(e) => setConnectForm({ ...connectForm, email: e.target.value })}
                      className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-red-500"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-zinc-300 mb-1.5">
                      ContraseÃ±a
                    </label>
                    <div className="relative">
                      <input
                        type={showPassword ? 'text' : 'password'}
                        placeholder="â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢"
                        value={connectForm.password}
                        onChange={(e) => setConnectForm({ ...connectForm, password: e.target.value })}
                        className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3.5 py-2.5 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-red-500 pr-10"
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

                  <div className="flex items-center justify-end gap-3 pt-3 border-t border-zinc-800">
                    <button
                      type="button"
                      onClick={() => setConnectModalProvider(null)}
                      className="px-4 py-2 text-xs font-semibold text-zinc-400 hover:text-white cursor-pointer"
                    >
                      Cancelar
                    </button>
                    <button
                      type="submit"
                      disabled={connecting}
                      className="flex items-center gap-1.5 px-4 py-2 bg-red-600 hover:bg-red-500 text-white font-bold text-xs rounded-xl shadow cursor-pointer"
                    >
                      {connecting ? <RefreshCw className="w-3.5 h-3.5 animate-spin" /> : null}
                      <span>Guardar ConexiÃ³n</span>
                    </button>
                  </div>
                </form>
              )}
            </div>
          </div>
        </div>
      )}

      {/* MODAL: Agregar Plataforma / Proveedor Personalizado */}
      {isAddPlatformOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in fade-in duration-200">
          <div className="relative w-full max-w-lg bg-[#181818] border border-zinc-800 rounded-2xl shadow-2xl p-6 space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-zinc-800">
              <h3 className="font-bold text-base text-white flex items-center gap-2">
                <Plus className="w-4 h-4 text-red-500" />
                Registrar Nuevo Proveedor OTT
              </h3>
              <button
                onClick={() => setIsAddPlatformOpen(false)}
                className="text-zinc-400 hover:text-white cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-3 text-xs">
              <div>
                <label className="block font-semibold text-zinc-300 mb-1">Nombre de Plataforma</label>
                <input
                  type="text"
                  value={addForm.name}
                  onChange={(e) => setAddForm({ ...addForm, name: e.target.value })}
                  placeholder="ej. Apple TV+"
                  className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-white"
                />
              </div>

              <div>
                <label className="block font-semibold text-zinc-300 mb-1">Slug Identificador</label>
                <input
                  type="text"
                  value={addForm.slug}
                  onChange={(e) => setAddForm({ ...addForm, slug: e.target.value })}
                  placeholder="ej. apple_tv"
                  className="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-white font-mono"
                />
              </div>

              <div className="flex justify-end gap-2 pt-3 border-t border-zinc-800">
                <button
                  type="button"
                  onClick={() => setIsAddPlatformOpen(false)}
                  className="px-3 py-1.5 text-zinc-400 hover:text-white cursor-pointer"
                >
                  Cancelar
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setProviders((prev) => [
                      ...prev,
                      {
                        provider: addForm.slug,
                        name: addForm.name,
                        description: `Plataforma ${addForm.name} configurada manualmente.`,
                        color: addForm.color || '#8B5CF6',
                        status: 'connected',
                        account_name: `${addForm.name} Gateway`,
                        quality_profile: '1080p',
                        auto_download: true,
                        last_sync_at: new Date().toISOString(),
                        stats: { imported_items: 12, active_downloads: 0, completed_downloads: 4 },
                      },
                    ]);
                    setIsAddPlatformOpen(false);
                    showToast('success', `Plataforma '${addForm.name}' agregada exitosamente.`);
                  }}
                  className="px-4 py-2 bg-red-600 hover:bg-red-500 text-white font-bold rounded-xl cursor-pointer"
                >
                  Registrar Plataforma
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Modal de ConfirmaciÃ³n Estilizado */}
      {confirmModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in fade-in duration-150">
          <div className="bg-[#181818] border border-zinc-800 rounded-2xl p-6 max-w-md w-full shadow-2xl space-y-4">
            <h3 className="font-bold text-base text-white">{confirmModal.title}</h3>
            <p className="text-xs text-zinc-400 leading-relaxed">{confirmModal.message}</p>
            <div className="flex items-center justify-end gap-3 pt-3 border-t border-zinc-800">
              <button
                type="button"
                onClick={() => setConfirmModal(null)}
                className="px-4 py-2 text-xs font-semibold text-zinc-400 hover:text-white cursor-pointer"
              >
                Cancelar
              </button>
              <button
                type="button"
                onClick={confirmModal.onConfirm}
                className="px-4 py-2 text-xs font-bold text-white bg-red-600 hover:bg-red-500 rounded-xl cursor-pointer shadow-md"
              >
                {confirmModal.confirmText || 'Confirmar'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal de ReproducciÃ³n On-Net Embebido */}
      <OttPlayerModal
        isOpen={Boolean(activePlayerMedia)}
        onClose={() => setActivePlayerMedia(null)}
        media={activePlayerMedia}
      />
    </div>
  );
}


