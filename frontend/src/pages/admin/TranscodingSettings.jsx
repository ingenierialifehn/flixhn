import React, { useState, useEffect, useCallback } from 'react';
import api from '../../services/api';
import {
  Cpu,
  Save,
  CheckCircle2,
  AlertCircle,
  HelpCircle,
  Sliders,
  FolderOpen,
  Check,
  X,
  Loader2,
  Sparkles,
  Zap,
  Volume2,
  FileText,
  AlertTriangle,
  RotateCcw,
  ExternalLink,
  Film,
  Layers,
  Settings,
  Info,
} from 'lucide-react';

const DEFAULT_SETTINGS = {
  enable_hardware_acceleration: 'no',
  h264_encoder_settings: {
    preset: 'veryfast',
    crf: 23,
    profile: 'high',
  },
  h265_encoder_settings: {
    preset: 'medium',
    crf: 28,
    profile: 'main',
  },
  temp_path: '',
  enable_throttling: false,
  audio_boost_downmix: 2,
  allow_subtitle_extraction_on_the_fly: false,
  enable_software_tone_mapping: true,
  tone_mapping_resolution_limit: 'no_limit',
  tone_mapping_algorithm: 'hable',
};

const PRESET_OPTIONS = [
  { value: 'ultrafast', label: 'Ultra Rápido (ultrafast)', desc: 'Mínimo uso de CPU, archivos más grandes y menor compresión' },
  { value: 'superfast', label: 'Súper Rápido (superfast)', desc: 'Muy bajo impacto en CPU, ideal para procesadores básicos' },
  { value: 'veryfast', label: 'Muy Rápido (veryfast)', desc: 'Recomendado para streaming en tiempo real y múltiples usuarios' },
  { value: 'faster', label: 'Más Rápido (faster)', desc: 'Buen balance hacia baja latencia' },
  { value: 'fast', label: 'Rápido (fast)', desc: 'Compresión estándar con velocidad moderada' },
  { value: 'medium', label: 'Medio (medium)', desc: 'Balance óptimo entre compresión y calidad por defecto' },
  { value: 'slow', label: 'Lento (slow)', desc: 'Mayor calidad visual y mejor compresión; consumo elevado de CPU' },
  { value: 'slower', label: 'Más Lento (slower)', desc: 'Compresión densa para bibliotecas con baja tasa de reproducción' },
  { value: 'veryslow', label: 'Muy Lento (veryslow)', desc: 'Máxima compresión posible; consumo extremo de CPU' },
];

const H264_PROFILES = [
  { value: 'auto', label: 'Automático' },
  { value: 'baseline', label: 'Baseline (Máxima compatibilidad para dispositivos antiguos)' },
  { value: 'main', label: 'Main (Dispositivos portátiles y transmisiones SD)' },
  { value: 'high', label: 'High (Recomendado para 1080p Full HD y Smart TVs)' },
  { value: 'high10', label: 'High 10 (Color de 10 bits)' },
];

const H265_PROFILES = [
  { value: 'auto', label: 'Automático' },
  { value: 'main', label: 'Main (8-bit estándar HEVC)' },
  { value: 'main10', label: 'Main 10 (10-bit HDR compatible)' },
];

const TONE_MAPPING_ALGORITHMS = [
  {
    id: 'hable',
    name: 'Hable (Fílmico de Uncharted 2)',
    desc: 'Desarrollado por Jim Hejl y Richard Burgess-Dawson. Preserva con maestría el contraste en altas luces y retiene detalles oscuros sin lavar los medios tonos.',
    recommended: true,
  },
  {
    id: 'mobius',
    name: 'Mobius (Transición Suave)',
    desc: 'Mapeo lineal para valores SDR estándar que transiciona suavemente a una curva hiperbólica para los picos HDR. Excelente fidelidad de color.',
  },
  {
    id: 'reinhard',
    name: 'Reinhard (Fotográfico)',
    desc: 'Algoritmo fotográfico clásico basado en luminancia. Produce transiciones suaves y naturales, aunque puede atenuar ligeramente la saturación general.',
  },
  {
    id: 'bt.2390',
    name: 'ITU-R BT.2390 (EBU / SMPTE)',
    desc: 'Estándar internacional de radiodifusión para mapeo armónico de curvas PQ/HLG a pantallas de rango dinámico estándar.',
  },
];

export default function TranscodingSettings() {
  const [activeTab, setActiveTab] = useState('transcoding'); // 'transcoding' | 'tone_mapping'
  const [formData, setFormData] = useState(DEFAULT_SETTINGS);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  // Sistema de Notificaciones Toast flotante
  const [toast, setToast] = useState(null); // { type: 'success' | 'error', message: string }

  // Validación de ruta temporal
  const [validatingPath, setValidatingPath] = useState(false);
  const [pathValidationResult, setPathValidationResult] = useState(null); // { status, message, path }

  // Modales de configuración de encoders (H.264 / H.265)
  const [editingEncoder, setEditingEncoder] = useState(null); // 'h264' | 'h265' | null
  const [modalEncoderData, setModalEncoderData] = useState({
    preset: 'veryfast',
    crf: 23,
    profile: 'high',
  });

  // Modal informativo sobre Aceleración por Hardware
  const [showHwDocsModal, setShowHwDocsModal] = useState(false);

  // Modal para restablecer valores predeterminados (sin usar confirm())
  const [showResetConfirmModal, setShowResetConfirmModal] = useState(false);

  // Auto-dismiss del toast después de 4.5 segundos
  useEffect(() => {
    if (!toast) return;
    const timer = setTimeout(() => {
      setToast(null);
    }, 4500);
    return () => clearTimeout(timer);
  }, [toast]);

  // Cargar configuración desde la API al montar el componente
  const fetchSettings = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/admin/transcoding');
      if (res.data?.data) {
        const d = res.data.data;
        setFormData({
          enable_hardware_acceleration: d.enable_hardware_acceleration || 'no',
          h264_encoder_settings: {
            preset: d.h264_encoder_settings?.preset || 'veryfast',
            crf: typeof d.h264_encoder_settings?.crf === 'number' ? d.h264_encoder_settings.crf : 23,
            profile: d.h264_encoder_settings?.profile || 'high',
          },
          h265_encoder_settings: {
            preset: d.h265_encoder_settings?.preset || 'medium',
            crf: typeof d.h265_encoder_settings?.crf === 'number' ? d.h265_encoder_settings.crf : 28,
            profile: d.h265_encoder_settings?.profile || 'main',
          },
          temp_path: d.temp_path || '',
          enable_throttling: Boolean(d.enable_throttling),
          audio_boost_downmix: typeof d.audio_boost_downmix === 'number' ? d.audio_boost_downmix : 2,
          allow_subtitle_extraction_on_the_fly: Boolean(d.allow_subtitle_extraction_on_the_fly),
          enable_software_tone_mapping: d.enable_software_tone_mapping !== undefined ? Boolean(d.enable_software_tone_mapping) : true,
          tone_mapping_resolution_limit: d.tone_mapping_resolution_limit || 'no_limit',
          tone_mapping_algorithm: d.tone_mapping_algorithm || 'hable',
        });
      }
    } catch (err) {
      console.error('Error cargando configuración de transcodificación:', err);
      setToast({
        type: 'error',
        message: 'No se pudo cargar la configuración del servidor. Mostrando valores locales predeterminados.',
      });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchSettings();
  }, [fetchSettings]);

  // Guardar configuración en la API
  const handleSave = async () => {
    setSaving(true);
    try {
      const payload = {
        enable_hardware_acceleration: formData.enable_hardware_acceleration,
        h264_encoder_settings: formData.h264_encoder_settings,
        h265_encoder_settings: formData.h265_encoder_settings,
        temp_path: formData.temp_path ? formData.temp_path.trim() : null,
        enable_throttling: Boolean(formData.enable_throttling),
        audio_boost_downmix: parseInt(formData.audio_boost_downmix, 10) || 2,
        allow_subtitle_extraction_on_the_fly: Boolean(formData.allow_subtitle_extraction_on_the_fly),
        enable_software_tone_mapping: Boolean(formData.enable_software_tone_mapping),
        tone_mapping_resolution_limit: formData.tone_mapping_resolution_limit,
        tone_mapping_algorithm: formData.tone_mapping_algorithm,
      };

      const res = await api.post('/admin/transcoding', payload);

      setToast({
        type: 'success',
        message: res.data?.message || 'Configuración de transcodificación guardada correctamente.',
      });
    } catch (err) {
      console.error('Error guardando configuración:', err);
      const errMsg =
        err.response?.data?.message ||
        err.response?.data?.error ||
        'Error al guardar la configuración de transcodificación en el servidor.';
      setToast({
        type: 'error',
        message: errMsg,
      });
    } finally {
      setSaving(false);
    }
  };

  // Validar ruta temporal en el servidor
  const handleValidatePath = async () => {
    setValidatingPath(true);
    setPathValidationResult(null);
    try {
      const res = await api.post('/admin/transcoding/validate-path', {
        temp_path: formData.temp_path,
      });
      setPathValidationResult({
        status: res.data?.status || 'success',
        message: res.data?.message || 'Ruta válida y con permisos de escritura.',
        path: res.data?.path || formData.temp_path,
      });
    } catch (err) {
      setPathValidationResult({
        status: 'error',
        message:
          err.response?.data?.message ||
          'La ruta especificada no existe o el servidor no tiene permisos de escritura.',
        path: formData.temp_path,
      });
    } finally {
      setValidatingPath(false);
    }
  };

  // Abrir modal de configuración de encoder
  const openEncoderModal = (encoderKey) => {
    setEditingEncoder(encoderKey);
    if (encoderKey === 'h264') {
      setModalEncoderData({ ...formData.h264_encoder_settings });
    } else {
      setModalEncoderData({ ...formData.h265_encoder_settings });
    }
  };

  // Aplicar cambios del modal de encoder
  const applyEncoderModal = () => {
    if (editingEncoder === 'h264') {
      setFormData((prev) => ({
        ...prev,
        h264_encoder_settings: {
          preset: modalEncoderData.preset,
          crf: parseInt(modalEncoderData.crf, 10) || 23,
          profile: modalEncoderData.profile,
        },
      }));
    } else if (editingEncoder === 'h265') {
      setFormData((prev) => ({
        ...prev,
        h265_encoder_settings: {
          preset: modalEncoderData.preset,
          crf: parseInt(modalEncoderData.crf, 10) || 28,
          profile: modalEncoderData.profile,
        },
      }));
    }
    setEditingEncoder(null);
    setToast({
      type: 'success',
      message: `Parámetros de encoder ${editingEncoder.toUpperCase()} actualizados localmente. Recuerda guardar cambios.`,
    });
  };

  // Restablecer valores predeterminados
  const handleConfirmReset = () => {
    setFormData(DEFAULT_SETTINGS);
    setShowResetConfirmModal(false);
    setPathValidationResult(null);
    setToast({
      type: 'success',
      message: 'Valores restablecidos a los valores predeterminados de FlixHN. Haz clic en "Guardar" para persistir.',
    });
  };

  if (loading) {
    return (
      <div className="w-full min-h-[500px] flex flex-col items-center justify-center space-y-4 text-zinc-400">
        <Loader2 className="w-10 h-10 text-[#E50914] animate-spin" />
        <p className="text-sm font-medium tracking-wide">
          Cargando parámetros del motor de transcodificación FlixHN...
        </p>
      </div>
    );
  }

  return (
    <div className="w-full px-4 sm:px-8 py-6 space-y-8 bg-[#141414] text-zinc-100 min-h-full transition-colors duration-200">
      {/* Toast Flotante de Notificaciones */}
      {toast && (
        <div
          className={`fixed top-6 right-6 z-50 max-w-md w-full p-4 rounded-2xl border shadow-2xl flex items-start gap-3 backdrop-blur-md transition-all duration-300 animate-in fade-in slide-in-from-top-4 ${
            toast.type === 'success'
              ? 'bg-[#18271e]/95 border-emerald-500/40 text-emerald-200 shadow-emerald-950/40'
              : 'bg-[#291416]/95 border-[#E50914]/40 text-red-200 shadow-red-950/40'
          }`}
        >
          {toast.type === 'success' ? (
            <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" />
          ) : (
            <AlertCircle className="w-5 h-5 text-[#E50914] shrink-0 mt-0.5" />
          )}
          <div className="flex-1 text-xs md:text-sm font-medium leading-relaxed">
            {toast.message}
          </div>
          <button
            type="button"
            onClick={() => setToast(null)}
            className="text-zinc-400 hover:text-white p-1 rounded-lg hover:bg-white/10 transition-colors cursor-pointer shrink-0"
            aria-label="Cerrar notificación"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* Cabecera Principal */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-zinc-800">
        <div className="flex items-center gap-3.5">
          <div className="p-3 rounded-2xl bg-[#E50914]/10 text-[#E50914] border border-[#E50914]/25 shadow-inner">
            <Cpu className="w-7 h-7" />
          </div>
          <div>
            <h1 className="text-xl sm:text-2xl font-bold tracking-tight text-white flex items-center gap-2">
              Transcodificación
              <span className="text-[11px] font-mono uppercase bg-zinc-900 text-zinc-400 px-2 py-0.5 rounded border border-zinc-800">
                Core Engine
              </span>
            </h1>
            <p className="text-xs sm:text-sm text-zinc-400 mt-0.5">
              Administración de aceleración por hardware GPU, perfiles de codecs H.264/H.265 y mapeo tonal HDR a SDR.
            </p>
          </div>
        </div>

        {/* Acciones de Cabecera */}
        <div className="flex items-center gap-3 self-start sm:self-auto">
          <button
            type="button"
            onClick={() => setShowResetConfirmModal(true)}
            className="flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-semibold text-zinc-400 hover:text-white bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 transition-colors cursor-pointer"
            title="Restablecer a valores por defecto"
          >
            <RotateCcw className="w-3.5 h-3.5" />
            <span className="hidden md:inline">Valores por defecto</span>
          </button>

          <button
            type="button"
            onClick={handleSave}
            disabled={saving}
            className="flex items-center gap-2 px-5 py-2 rounded-xl text-xs md:text-sm font-bold text-white bg-[#E50914] hover:bg-[#F40612] transition-all shadow-lg shadow-red-950/40 cursor-pointer disabled:opacity-50 active:scale-95"
          >
            {saving ? (
              <>
                <Loader2 className="w-4 h-4 animate-spin" />
                <span>Guardando...</span>
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

      {/* Pestañas Superiores Estilo Pill Buttons (Dark UI) */}
      <div className="flex items-center gap-2 p-1.5 rounded-2xl bg-zinc-900/90 border border-zinc-800/80 w-fit">
        <button
          type="button"
          onClick={() => setActiveTab('transcoding')}
          className={`flex items-center gap-2 px-5 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all cursor-pointer ${
            activeTab === 'transcoding'
              ? 'bg-[#E50914] text-white shadow-md shadow-red-950/50'
              : 'text-zinc-400 hover:text-white hover:bg-zinc-800/50'
          }`}
        >
          <Cpu className="w-4 h-4" />
          <span>Transcoding</span>
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('tone_mapping')}
          className={`flex items-center gap-2 px-5 py-2 rounded-xl text-xs sm:text-sm font-bold transition-all cursor-pointer ${
            activeTab === 'tone_mapping'
              ? 'bg-[#E50914] text-white shadow-md shadow-red-950/50'
              : 'text-zinc-400 hover:text-white hover:bg-zinc-800/50'
          }`}
        >
          <Sparkles className="w-4 h-4" />
          <span>Tone Mapping</span>
        </button>
      </div>

      {/* ========================================================================= */}
      {/* PESTAÑA 1: TRANSCODING */}
      {/* ========================================================================= */}
      {activeTab === 'transcoding' && (
        <div className="space-y-6">
          {/* 1. ACELERACIÓN POR HARDWARE */}
          <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-sm space-y-4">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
              <div className="space-y-1">
                <label className="text-sm font-bold text-white flex items-center gap-2">
                  <Zap className="w-4 h-4 text-amber-500" />
                  Habilitar aceleración por hardware cuando esté disponible
                </label>
                <p className="text-xs text-zinc-400 leading-relaxed">
                  Utiliza chips y tarjetas de video dedicadas para codificar y decodificar video con latencia ultra-baja y mínimo consumo de CPU.
                </p>
              </div>

              <button
                type="button"
                onClick={() => setShowHwDocsModal(true)}
                className="inline-flex items-center gap-1.5 text-xs text-[#E50914] hover:text-red-400 font-semibold underline underline-offset-4 cursor-pointer self-start sm:self-auto shrink-0"
              >
                <HelpCircle className="w-3.5 h-3.5" />
                Guía de compatibilidad y drivers
              </button>
            </div>

            <div className="pt-2">
              <select
                value={formData.enable_hardware_acceleration}
                onChange={(e) =>
                  setFormData({ ...formData, enable_hardware_acceleration: e.target.value })
                }
                className="w-full sm:max-w-md px-4 py-2.5 text-sm rounded-xl bg-[#242424] border border-zinc-700/80 text-white focus:outline-none focus:ring-2 focus:ring-[#E50914] cursor-pointer"
              >
                <option value="no">No (Desactivada - Procesar todo por software en CPU)</option>
                <option value="vaapi">VAAPI (Video Acceleration API - Linux / Intel / AMD)</option>
                <option value="nvenc">NVIDIA NVENC (GPU GeForce / Quadro / Tesla)</option>
                <option value="qsv">Intel QuickSync (GPU integrada Intel HD / UHD / Iris)</option>
                <option value="videotoolbox">Apple VideoToolbox (macOS Apple Silicon / M-series)</option>
                <option value="amf">AMD AMF (Advanced Media Framework)</option>
              </select>
            </div>
          </div>

          {/* 2. SOFTWARE ENCODERS */}
          <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-sm space-y-4">
            <div>
              <h2 className="text-sm font-bold text-white flex items-center gap-2">
                <Film className="w-4 h-4 text-[#E50914]" />
                Software Encoders
              </h2>
              <p className="text-xs text-zinc-400 mt-1">
                Configura los perfiles y parámetros de compresión por software para los codecs de salida.
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-1">
              {/* Tarjeta H.264 (AVC) */}
              <div className="bg-[#1f1f1f] border border-zinc-800 rounded-xl p-4 flex items-center justify-between hover:border-zinc-700 transition-colors">
                <div className="space-y-1.5">
                  <div className="flex items-center gap-2">
                    <span className="font-bold text-sm text-white">H.264 (AVC)</span>
                    <span className="px-2 py-0.5 text-[10px] font-bold rounded bg-zinc-800 text-zinc-300 border border-zinc-700">
                      Estándar Universal
                    </span>
                  </div>
                  <div className="text-xs text-zinc-400 space-x-2">
                    <span>Preset: <strong className="text-zinc-200">{formData.h264_encoder_settings.preset}</strong></span>
                    <span>•</span>
                    <span>CRF: <strong className="text-zinc-200">{formData.h264_encoder_settings.crf}</strong></span>
                    <span>•</span>
                    <span>Perfil: <strong className="text-zinc-200">{formData.h264_encoder_settings.profile || 'High'}</strong></span>
                  </div>
                </div>

                <button
                  type="button"
                  onClick={() => openEncoderModal('h264')}
                  className="p-2.5 rounded-xl bg-zinc-800/80 hover:bg-[#E50914] text-zinc-300 hover:text-white transition-all cursor-pointer shadow hover:shadow-red-950/40"
                  title="Configurar H.264"
                >
                  <Settings className="w-4 h-4" />
                </button>
              </div>

              {/* Tarjeta H.265 (HEVC) */}
              <div className="bg-[#1f1f1f] border border-zinc-800 rounded-xl p-4 flex items-center justify-between hover:border-zinc-700 transition-colors">
                <div className="space-y-1.5">
                  <div className="flex items-center gap-2">
                    <span className="font-bold text-sm text-white">H.265 (HEVC)</span>
                    <span className="px-2 py-0.5 text-[10px] font-bold rounded bg-[#E50914]/20 text-red-300 border border-[#E50914]/30">
                      Alta Eficiencia
                    </span>
                  </div>
                  <div className="text-xs text-zinc-400 space-x-2">
                    <span>Preset: <strong className="text-zinc-200">{formData.h265_encoder_settings.preset}</strong></span>
                    <span>•</span>
                    <span>CRF: <strong className="text-zinc-200">{formData.h265_encoder_settings.crf}</strong></span>
                    <span>•</span>
                    <span>Perfil: <strong className="text-zinc-200">{formData.h265_encoder_settings.profile || 'Main'}</strong></span>
                  </div>
                </div>

                <button
                  type="button"
                  onClick={() => openEncoderModal('h265')}
                  className="p-2.5 rounded-xl bg-zinc-800/80 hover:bg-[#E50914] text-zinc-300 hover:text-white transition-all cursor-pointer shadow hover:shadow-red-950/40"
                  title="Configurar H.265"
                >
                  <Settings className="w-4 h-4" />
                </button>
              </div>
            </div>
          </div>

          {/* 3. TRANSCODING TEMPORARY PATH */}
          <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-sm space-y-3">
            <label className="block text-sm font-bold text-white">
              Transcoding temporary path
            </label>
            <p className="text-xs text-zinc-400">
              Carpeta de archivos temporales del transcodificador. Especifica una ruta personalizada o deja en blanco para usar la ruta por defecto del servidor.
            </p>

            <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 pt-1">
              <div className="relative flex-1">
                <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-zinc-400">
                  <FolderOpen className="w-4 h-4" />
                </div>
                <input
                  type="text"
                  value={formData.temp_path}
                  onChange={(e) => {
                    setFormData({ ...formData, temp_path: e.target.value });
                    setPathValidationResult(null);
                  }}
                  placeholder="/tmp/transcoding o dejar vacío para ruta automática"
                  className="w-full pl-10 pr-4 py-2.5 text-sm font-mono rounded-xl bg-[#242424] border border-zinc-700/80 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-[#E50914]"
                />
              </div>

              <button
                type="button"
                onClick={handleValidatePath}
                disabled={validatingPath}
                className="px-5 py-2.5 rounded-xl text-xs font-bold text-zinc-200 hover:text-white bg-zinc-800 hover:bg-zinc-700 border border-zinc-700/80 transition-colors flex items-center justify-center gap-2 cursor-pointer disabled:opacity-50 shrink-0"
              >
                {validatingPath ? (
                  <>
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    <span>Validando...</span>
                  </>
                ) : (
                  <>
                    <Check className="w-3.5 h-3.5 text-emerald-400" />
                    <span>Validar ruta</span>
                  </>
                )}
              </button>
            </div>

            {/* Mensaje de validación de ruta */}
            {pathValidationResult && (
              <div
                className={`p-3 rounded-xl border flex items-start gap-2.5 text-xs font-medium animate-in fade-in ${
                  pathValidationResult.status === 'success'
                    ? 'bg-emerald-950/50 border-emerald-500/40 text-emerald-300'
                    : 'bg-red-950/50 border-red-500/40 text-red-300'
                }`}
              >
                {pathValidationResult.status === 'success' ? (
                  <CheckCircle2 className="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" />
                ) : (
                  <AlertCircle className="w-4 h-4 text-red-400 shrink-0 mt-0.5" />
                )}
                <div>
                  <p>{pathValidationResult.message}</p>
                  {pathValidationResult.path && (
                    <p className="font-mono text-[11px] text-zinc-400 mt-0.5">
                      Ruta verificada: {pathValidationResult.path}
                    </p>
                  )}
                </div>
              </div>
            )}
          </div>

          {/* 4. THROTTLING */}
          <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-sm flex items-start justify-between gap-4">
            <div className="space-y-1">
              <label htmlFor="enable_throttling" className="text-sm font-bold text-white cursor-pointer">
                Enable throttling
              </label>
              <p className="text-xs text-zinc-400 leading-relaxed max-w-2xl">
                Ajusta dinámicamente la velocidad de transcodificación para reducir el uso de CPU. Cuando el cliente almacena suficiente búfer, el transcodificador entra en pausa o velocidad 1.0x para ahorrar recursos.
              </p>
            </div>

            <label className="relative inline-flex items-center cursor-pointer shrink-0 mt-1">
              <input
                id="enable_throttling"
                type="checkbox"
                checked={formData.enable_throttling}
                onChange={(e) =>
                  setFormData({ ...formData, enable_throttling: e.target.checked })
                }
                className="sr-only peer"
              />
              <div className="w-11 h-6 bg-zinc-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-zinc-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#E50914]"></div>
            </label>
          </div>

          {/* 5. AUDIO BOOST WHEN DOWNMIXING */}
          <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-sm space-y-3">
            <div className="space-y-1">
              <label className="text-sm font-bold text-white flex items-center gap-2">
                <Volume2 className="w-4 h-4 text-zinc-300" />
                Audio boost when downmixing
              </label>
              <p className="text-xs text-zinc-400">
                Aumento de audio al mezclar a estéreo. Establece en 1 para mantener el volumen original.
              </p>
            </div>

            <div className="flex items-center gap-4 pt-1">
              <input
                type="range"
                min="1"
                max="10"
                step="1"
                value={formData.audio_boost_downmix}
                onChange={(e) =>
                  setFormData({ ...formData, audio_boost_downmix: parseInt(e.target.value, 10) })
                }
                className="w-full max-w-xs h-2 bg-zinc-700 rounded-lg appearance-none cursor-pointer accent-[#E50914]"
              />

              <div className="flex items-center gap-2">
                <input
                  type="number"
                  min="1"
                  max="10"
                  value={formData.audio_boost_downmix}
                  onChange={(e) => {
                    const val = parseInt(e.target.value, 10);
                    if (!isNaN(val) && val >= 1 && val <= 10) {
                      setFormData({ ...formData, audio_boost_downmix: val });
                    }
                  }}
                  className="w-16 px-3 py-1.5 text-center text-sm font-mono font-bold rounded-xl bg-[#242424] border border-zinc-700 text-white focus:outline-none focus:ring-2 focus:ring-[#E50914]"
                />
                <span className="text-xs text-zinc-400 font-medium">x factor</span>
              </div>
            </div>
          </div>

          {/* 6. ALLOW SUBTITLE EXTRACTION ON THE FLY */}
          <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-sm flex items-start justify-between gap-4">
            <div className="space-y-1">
              <div className="flex items-center gap-2">
                <label
                  htmlFor="allow_subtitle_extraction"
                  className="text-sm font-bold text-white cursor-pointer"
                >
                  Allow subtitle extraction on the fly
                </label>
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/30">
                  <AlertTriangle className="w-3 h-3" />
                  Rendimiento
                </span>
              </div>
              <p className="text-xs text-zinc-400 leading-relaxed max-w-2xl">
                Permite al servidor extraer subtítulos incrustados de archivos MKV en tiempo real. Advertencia: Puede causar demoras en el inicio de la reproducción si el almacenamiento tiene alta latencia.
              </p>
            </div>

            <label className="relative inline-flex items-center cursor-pointer shrink-0 mt-1">
              <input
                id="allow_subtitle_extraction"
                type="checkbox"
                checked={formData.allow_subtitle_extraction_on_the_fly}
                onChange={(e) =>
                  setFormData({
                    ...formData,
                    allow_subtitle_extraction_on_the_fly: e.target.checked,
                  })
                }
                className="sr-only peer"
              />
              <div className="w-11 h-6 bg-zinc-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-zinc-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#E50914]"></div>
            </label>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* PESTAÑA 2: TONE MAPPING */}
      {/* ========================================================================= */}
      {activeTab === 'tone_mapping' && (
        <div className="space-y-6">
          {/* Tarjeta de Introducción a Tone Mapping */}
          <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-sm relative overflow-hidden">
            <div className="absolute right-0 top-0 -mt-4 -mr-4 w-40 h-40 bg-[#E50914]/10 rounded-full blur-3xl pointer-events-none" />
            <div className="flex items-start gap-4">
              <div className="p-3 rounded-2xl bg-gradient-to-br from-[#E50914]/20 to-purple-600/20 text-[#E50914] border border-[#E50914]/30 shrink-0">
                <Sparkles className="w-6 h-6" />
              </div>
              <div className="space-y-2">
                <h2 className="text-base font-bold text-white">
                  Mapeo de Tonos de Alto Rango Dinámico (HDR a SDR)
                </h2>
                <p className="text-xs sm:text-sm text-zinc-300 leading-relaxed max-w-3xl">
                  El mapeo de tonos (Tone Mapping) transforma el espacio de color extendido y el brillo de películas HDR (HDR10, HLG, Dolby Vision) al espacio estándar SDR (Rec. 709). Esto evita que la imagen luzca descolorida, oscura o lavada al reproducirse en televisores, monitores o teléfonos móviles convencionales.
                </p>
              </div>
            </div>
          </div>

          {/* Switch Principal: Enable Software Tone Mapping */}
          <div className="bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-sm flex items-start justify-between gap-4">
            <div className="space-y-1">
              <label
                htmlFor="enable_software_tone_mapping"
                className="text-sm font-bold text-white cursor-pointer"
              >
                Enable Software Tone Mapping
              </label>
              <p className="text-xs text-zinc-400 leading-relaxed max-w-2xl">
                Habilita los filtros colorimétricos en el motor de transcodificación para convertir perfiles HDR a SDR cuando el cliente receptor no soporte reproducción nativa de alto rango dinámico.
              </p>
            </div>

            <label className="relative inline-flex items-center cursor-pointer shrink-0 mt-1">
              <input
                id="enable_software_tone_mapping"
                type="checkbox"
                checked={formData.enable_software_tone_mapping}
                onChange={(e) =>
                  setFormData({
                    ...formData,
                    enable_software_tone_mapping: e.target.checked,
                  })
                }
                className="sr-only peer"
              />
              <div className="w-11 h-6 bg-zinc-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-zinc-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#E50914]"></div>
            </label>
          </div>

          {/* Subsección: Parámetros de Software Tone Mapping */}
          <div
            className={`bg-[#181818] border border-zinc-800/80 rounded-2xl p-6 shadow-sm space-y-6 transition-all duration-300 ${
              formData.enable_software_tone_mapping
                ? 'opacity-100'
                : 'opacity-40 pointer-events-none'
            }`}
          >
            <div className="border-b border-zinc-800 pb-3">
              <h3 className="text-sm font-bold text-white flex items-center gap-2">
                <Sliders className="w-4 h-4 text-[#E50914]" />
                Software Tone Mapping Parameters
              </h3>
              <p className="text-xs text-zinc-400 mt-0.5">
                Ajuste fino de la resolución máxima y el algoritmo matemático de conversión de luminancia.
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              {/* Video Resolution Limit */}
              <div className="space-y-2">
                <label className="block text-xs font-bold uppercase tracking-wider text-zinc-300">
                  Video Resolution Limit
                </label>
                <select
                  value={formData.tone_mapping_resolution_limit}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      tone_mapping_resolution_limit: e.target.value,
                    })
                  }
                  className="w-full px-4 py-2.5 text-sm rounded-xl bg-[#242424] border border-zinc-700/80 text-white focus:outline-none focus:ring-2 focus:ring-[#E50914] cursor-pointer"
                >
                  <option value="no_limit">No Limit (Sin límite - Procesar hasta 4K UHD)</option>
                  <option value="1080p">1080p (Full HD - Recomendado para servidores estándar)</option>
                  <option value="720p">720p (HD - Para procesadores con recursos limitados)</option>
                  <option value="480p">480p (SD - Mínimo consumo térmico)</option>
                </select>
                <p className="text-[11px] text-zinc-400">
                  Limita la resolución de video a la que se aplicará el mapeo de tonos para evitar saturar el hardware.
                </p>
              </div>

              {/* Tone Mapping Algorithm */}
              <div className="space-y-2">
                <label className="block text-xs font-bold uppercase tracking-wider text-zinc-300">
                  Tone Mapping Algorithm
                </label>
                <select
                  value={formData.tone_mapping_algorithm}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      tone_mapping_algorithm: e.target.value,
                    })
                  }
                  className="w-full px-4 py-2.5 text-sm rounded-xl bg-[#242424] border border-zinc-700/80 text-white focus:outline-none focus:ring-2 focus:ring-[#E50914] cursor-pointer"
                >
                  <option value="hable">Hable (Por defecto - Fílmico balanceado)</option>
                  <option value="mobius">Mobius (Transición suave sombras y medios tonos)</option>
                  <option value="reinhard">Reinhard (Compresión fotográfica clásica)</option>
                  <option value="bt.2390">ITU-R BT.2390 (Estándar internacional broadcast)</option>
                </select>
                <p className="text-[11px] text-zinc-400">
                  Curva de transferencia electro-óptica aplicada a los valores de luminancia pico.
                </p>
              </div>
            </div>

            {/* Tarjetas comparativas de algoritmos */}
            <div className="pt-2">
              <span className="text-xs font-bold text-zinc-400 uppercase tracking-wider block mb-3">
                Algoritmos de Mapeo Disponibles
              </span>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {TONE_MAPPING_ALGORITHMS.map((algo) => {
                  const isSelected = formData.tone_mapping_algorithm === algo.id;
                  return (
                    <div
                      key={algo.id}
                      onClick={() =>
                        formData.enable_software_tone_mapping &&
                        setFormData({ ...formData, tone_mapping_algorithm: algo.id })
                      }
                      className={`p-3.5 rounded-xl border text-left transition-all cursor-pointer ${
                        isSelected
                          ? 'bg-[#E50914]/10 border-[#E50914]/60 text-white shadow-md shadow-red-950/20'
                          : 'bg-[#1f1f1f] border-zinc-800 text-zinc-400 hover:text-zinc-200 hover:border-zinc-700'
                      }`}
                    >
                      <div className="flex items-center justify-between mb-1">
                        <span className="font-bold text-xs text-white flex items-center gap-1.5">
                          {algo.name}
                          {algo.recommended && (
                            <span className="text-[10px] bg-emerald-500/20 text-emerald-400 px-1.5 py-0.2 rounded border border-emerald-500/30">
                              Recomendado
                            </span>
                          )}
                        </span>
                        {isSelected && <Check className="w-3.5 h-3.5 text-[#E50914]" />}
                      </div>
                      <p className="text-[11px] text-zinc-400 leading-relaxed">{algo.desc}</p>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* BARRA INFERIOR DE GUARDADO PROMINENTE */}
      {/* ========================================================================= */}
      <div className="sticky bottom-4 z-20 p-4 rounded-2xl bg-[#181818]/95 backdrop-blur-md border border-zinc-800 flex flex-col sm:flex-row items-center justify-between gap-4 shadow-2xl">
        <div className="flex items-center gap-2 text-xs text-zinc-400 text-center sm:text-left">
          <Info className="w-4 h-4 text-zinc-400 shrink-0 hidden sm:block" />
          <span>
            Los cambios se aplicarán inmediatamente a todas las sesiones de streaming activas del nodo FlixHN.
          </span>
        </div>

        <div className="flex items-center gap-3 w-full sm:w-auto justify-end">
          <button
            type="button"
            onClick={() => setShowResetConfirmModal(true)}
            className="px-4 py-2 text-xs font-semibold rounded-xl bg-zinc-800 hover:bg-zinc-700 text-zinc-300 transition-colors cursor-pointer"
          >
            Revertir cambios
          </button>

          <button
            type="button"
            onClick={handleSave}
            disabled={saving}
            className="flex-1 sm:flex-none flex items-center justify-center gap-2 px-7 py-2.5 rounded-xl text-xs sm:text-sm font-bold text-white bg-[#E50914] hover:bg-[#F40612] transition-all shadow-lg shadow-red-950/50 cursor-pointer disabled:opacity-50 active:scale-95"
          >
            {saving ? (
              <>
                <Loader2 className="w-4 h-4 animate-spin" />
                <span>Guardando cambios...</span>
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

      {/* ========================================================================= */}
      {/* MODAL: CONFIGURACIÓN AVANZADA DE ENCODER (H.264 o H.265) */}
      {/* ========================================================================= */}
      {editingEncoder && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-in fade-in">
          <div className="bg-[#1a1a1a] border border-zinc-800 rounded-2xl max-w-lg w-full p-6 space-y-6 shadow-2xl text-zinc-100">
            {/* Modal Header */}
            <div className="flex items-center justify-between pb-4 border-b border-zinc-800">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-xl bg-[#E50914]/10 text-[#E50914] border border-[#E50914]/20">
                  <Sliders className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-base font-bold text-white">
                    Configuración de Software Encoder
                  </h3>
                  <p className="text-xs text-zinc-400">
                    Codec: <strong className="text-white uppercase">{editingEncoder === 'h264' ? 'H.264 (AVC)' : 'H.265 (HEVC)'}</strong>
                  </p>
                </div>
              </div>

              <button
                type="button"
                onClick={() => setEditingEncoder(null)}
                className="p-1.5 text-zinc-400 hover:text-white rounded-lg hover:bg-zinc-800 transition-colors cursor-pointer"
                aria-label="Cerrar modal"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Modal Body */}
            <div className="space-y-4">
              {/* Preset Selector */}
              <div className="space-y-1.5">
                <label className="block text-xs font-bold uppercase tracking-wider text-zinc-300">
                  Preset de Velocidad
                </label>
                <select
                  value={modalEncoderData.preset}
                  onChange={(e) =>
                    setModalEncoderData({ ...modalEncoderData, preset: e.target.value })
                  }
                  className="w-full px-3.5 py-2.5 text-sm rounded-xl bg-[#242424] border border-zinc-700 text-white focus:outline-none focus:ring-2 focus:ring-[#E50914] cursor-pointer"
                >
                  {PRESET_OPTIONS.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                      {opt.label}
                    </option>
                  ))}
                </select>
                <p className="text-[11px] text-zinc-400">
                  {PRESET_OPTIONS.find((p) => p.value === modalEncoderData.preset)?.desc}
                </p>
              </div>

              {/* CRF Slider & Number Input */}
              <div className="space-y-2 pt-2">
                <div className="flex items-center justify-between">
                  <label className="text-xs font-bold uppercase tracking-wider text-zinc-300">
                    Factor de Calidad (CRF)
                  </label>
                  <span className="text-xs font-mono font-bold px-2 py-0.5 rounded bg-[#E50914]/20 text-[#E50914] border border-[#E50914]/30">
                    CRF: {modalEncoderData.crf}
                  </span>
                </div>

                <div className="flex items-center gap-3">
                  <input
                    type="range"
                    min="0"
                    max="51"
                    value={modalEncoderData.crf}
                    onChange={(e) =>
                      setModalEncoderData({
                        ...modalEncoderData,
                        crf: parseInt(e.target.value, 10),
                      })
                    }
                    className="w-full h-2 bg-zinc-700 rounded-lg appearance-none cursor-pointer accent-[#E50914]"
                  />
                  <input
                    type="number"
                    min="0"
                    max="51"
                    value={modalEncoderData.crf}
                    onChange={(e) => {
                      const val = parseInt(e.target.value, 10);
                      if (!isNaN(val) && val >= 0 && val <= 51) {
                        setModalEncoderData({ ...modalEncoderData, crf: val });
                      }
                    }}
                    className="w-16 px-2.5 py-1 text-center text-sm font-mono font-bold rounded-xl bg-[#242424] border border-zinc-700 text-white focus:outline-none focus:ring-2 focus:ring-[#E50914]"
                  />
                </div>

                <div className="flex justify-between text-[10px] text-zinc-500 font-mono">
                  <span>0 (Sin Pérdida)</span>
                  <span className="text-emerald-400 font-semibold">18-28 (Recomendado)</span>
                  <span>51 (Peor Calidad)</span>
                </div>
              </div>

              {/* Profile Selector */}
              <div className="space-y-1.5 pt-2">
                <label className="block text-xs font-bold uppercase tracking-wider text-zinc-300">
                  Perfil de Codificación (Profile)
                </label>
                <select
                  value={modalEncoderData.profile}
                  onChange={(e) =>
                    setModalEncoderData({ ...modalEncoderData, profile: e.target.value })
                  }
                  className="w-full px-3.5 py-2.5 text-sm rounded-xl bg-[#242424] border border-zinc-700 text-white focus:outline-none focus:ring-2 focus:ring-[#E50914] cursor-pointer"
                >
                  {editingEncoder === 'h264'
                    ? H264_PROFILES.map((p) => (
                        <option key={p.value} value={p.value}>
                          {p.label}
                        </option>
                      ))
                    : H265_PROFILES.map((p) => (
                        <option key={p.value} value={p.value}>
                          {p.label}
                        </option>
                      ))}
                </select>
              </div>
            </div>

            {/* Modal Footer */}
            <div className="flex items-center justify-end gap-3 pt-4 border-t border-zinc-800">
              <button
                type="button"
                onClick={() => setEditingEncoder(null)}
                className="px-4 py-2 text-xs font-semibold rounded-xl bg-zinc-800 hover:bg-zinc-700 text-zinc-300 transition-colors cursor-pointer"
              >
                Cancelar
              </button>
              <button
                type="button"
                onClick={applyEncoderModal}
                className="flex items-center gap-2 px-5 py-2 text-xs font-bold text-white bg-[#E50914] hover:bg-[#F40612] rounded-xl shadow-md transition-all cursor-pointer active:scale-95"
              >
                <Check className="w-4 h-4" />
                <span>Aplicar cambios</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL: GUÍA DE ACELERACIÓN POR HARDWARE (DOCS) */}
      {/* ========================================================================= */}
      {showHwDocsModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-in fade-in">
          <div className="bg-[#1a1a1a] border border-zinc-800 rounded-2xl max-w-xl w-full p-6 space-y-5 shadow-2xl text-zinc-100 max-h-[85vh] overflow-y-auto">
            <div className="flex items-center justify-between pb-3 border-b border-zinc-800">
              <div className="flex items-center gap-2.5">
                <Zap className="w-5 h-5 text-amber-500" />
                <h3 className="text-base font-bold text-white">
                  Aceleración por Hardware en Servidores FlixHN
                </h3>
              </div>
              <button
                type="button"
                onClick={() => setShowHwDocsModal(false)}
                className="p-1 text-zinc-400 hover:text-white rounded-lg hover:bg-zinc-800 transition-colors cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-4 text-xs text-zinc-300 leading-relaxed">
              <div className="p-3 rounded-xl bg-zinc-900 border border-zinc-800 space-y-1">
                <strong className="text-white text-sm block">VAAPI (Linux / Intel / AMD)</strong>
                <p>
                  Para habilitar VAAPI en contenedores Docker de FlixHN, debes mapear el dispositivo de renderizado en tu <code>docker-compose.yml</code>:
                </p>
                <pre className="font-mono text-[11px] bg-black/60 p-2 rounded border border-zinc-800 text-emerald-400 overflow-x-auto">
devices:&#10;  - /dev/dri:/dev/dri
                </pre>
              </div>

              <div className="p-3 rounded-xl bg-zinc-900 border border-zinc-800 space-y-1">
                <strong className="text-white text-sm block">NVIDIA NVENC (GeForce / Tesla)</strong>
                <p>
                  Requiere instalar el paquete <code>nvidia-container-toolkit</code> en el host de Linux y configurar el runtime en Docker:
                </p>
                <pre className="font-mono text-[11px] bg-black/60 p-2 rounded border border-zinc-800 text-emerald-400 overflow-x-auto">
deploy:&#10;  resources:&#10;    reservations:&#10;      devices:&#10;        - driver: nvidia&#10;          count: all&#10;          capabilities: [gpu, video]
                </pre>
              </div>

              <div className="p-3 rounded-xl bg-zinc-900 border border-zinc-800 space-y-1">
                <strong className="text-white text-sm block">Intel QuickSync (QSV)</strong>
                <p>
                  Disponible en procesadores Intel Core (6ª generación en adelante) con GPU Intel UHD/Iris integrada. Requiere permisos sobre <code>/dev/dri/renderD128</code> en el contenedor.
                </p>
              </div>
            </div>

            <div className="flex justify-end pt-3 border-t border-zinc-800">
              <button
                type="button"
                onClick={() => setShowHwDocsModal(false)}
                className="px-5 py-2 text-xs font-bold text-white bg-[#E50914] hover:bg-[#F40612] rounded-xl cursor-pointer"
              >
                Entendido
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ========================================================================= */}
      {/* MODAL: CONFIRMACIÓN PARA RESTABLECER VALORES PREDETERMINADOS */}
      {/* ========================================================================= */}
      {showResetConfirmModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-in fade-in">
          <div className="bg-[#1a1a1a] border border-zinc-800 rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl text-zinc-100">
            <div className="flex items-center gap-3 text-amber-500">
              <AlertTriangle className="w-6 h-6" />
              <h3 className="text-base font-bold text-white">
                ¿Restablecer configuración?
              </h3>
            </div>

            <p className="text-xs text-zinc-300 leading-relaxed">
              Todos los valores del motor de transcodificación y mapeo de tonos volverán a sus parámetros recomendados por defecto de FlixHN. Tendrás la opción de revisarlos antes de presionar Guardar.
            </p>

            <div className="flex items-center justify-end gap-3 pt-3 border-t border-zinc-800">
              <button
                type="button"
                onClick={() => setShowResetConfirmModal(false)}
                className="px-4 py-2 text-xs font-semibold rounded-xl bg-zinc-800 hover:bg-zinc-700 text-zinc-300 transition-colors cursor-pointer"
              >
                Cancelar
              </button>
              <button
                type="button"
                onClick={handleConfirmReset}
                className="px-5 py-2 text-xs font-bold text-white bg-[#E50914] hover:bg-[#F40612] rounded-xl shadow-md transition-all cursor-pointer active:scale-95"
              >
                Sí, restablecer
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
