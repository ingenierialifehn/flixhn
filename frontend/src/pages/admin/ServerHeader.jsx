import React, { useState, useEffect } from 'react';
import {
  Server,
  Wifi,
  Network,
  HardDrive,
  Cpu,
  Activity,
  Clock,
  ShieldCheck,
  Pencil,
  Check,
  X,
  RotateCw,
  Square,
  Play,
  Loader2,
} from 'lucide-react';
import api from '../../services/api';

const ServerHeader = ({ server = null }) => {
  const initialHostname = server?.hostname || 'server01-PowerEdge-R720';
  const version = server?.version || (server?.php_version ? `PHP ${server.php_version} • Laravel ${server?.laravel_version}` : 'FlixHN Core');
  const initialStatus = server?.on_net_status || 'En línea';
  const lanIp = server?.lan_ip || 'No asignada';
  const wanIp = server?.wan_ip || 'No detectada';
  const cpuUsage = server?.cpu_usage || 'No disponible';
  const ramUsage = server?.memory_usage || server?.ram_usage || 'No disponible';
  const storageText = server?.storage_used && server?.storage_total
    ? `${server.storage_used} / ${server.storage_total}`
    : (server?.storage_total ? server.storage_total : 'No disponible');
  const uptimeText = server?.uptime ? `Uptime: ${server.uptime}` : 'Uptime: En línea';

  // Estados locales para reactividad inmediata sin recarga
  const [hostname, setHostname] = useState(initialHostname);
  const [onNetStatus, setOnNetStatus] = useState(initialStatus);

  // Estados de edición del nombre del servidor (Punto 8)
  const [isEditingName, setIsEditingName] = useState(false);
  const [nameInput, setNameInput] = useState(initialHostname);
  const [savingName, setSavingName] = useState(false);

  // Estados de acciones de servidor (Punto 7)
  const [actionLoading, setActionLoading] = useState(null); // 'restart' | 'stop' | 'start'
  const [toast, setToast] = useState(null); // { message: string, type: 'success' | 'error' | 'warning' }

  useEffect(() => {
    if (server?.hostname) {
      setHostname(server.hostname);
      setNameInput(server.hostname);
    }
    if (server?.on_net_status) {
      setOnNetStatus(server.on_net_status);
    }
  }, [server?.hostname, server?.on_net_status]);

  // Limpiar toast automáticamente
  useEffect(() => {
    if (!toast) return;
    const timer = setTimeout(() => setToast(null), 4000);
    return () => clearTimeout(timer);
  }, [toast]);

  // Manejador para guardar nuevo nombre de servidor (Punto 8)
  const handleSaveName = async (e) => {
    if (e) e.preventDefault();
    const trimmed = nameInput.trim();
    if (!trimmed || trimmed === hostname) {
      setIsEditingName(false);
      return;
    }

    setSavingName(true);
    try {
      const res = await api.patch('/admin/server/name', { name: trimmed });
      const newName = res.data?.name || trimmed;
      setHostname(newName);
      setIsEditingName(false);
      setToast({
        type: 'success',
        message: `Nombre del servidor actualizado a "${newName}".`,
      });
    } catch (err) {
      setToast({
        type: 'error',
        message: err.response?.data?.message || 'Error al actualizar el nombre del servidor.',
      });
    } finally {
      setSavingName(false);
    }
  };

  // Manejador para acciones del servidor: restart, stop, start (Punto 7)
  const handleServerAction = async (action) => {
    setActionLoading(action);
    try {
      const res = await api.post(`/admin/server/${action}`);
      const serverStatus = res.data?.server_status;

      if (serverStatus === 'restarting' || action === 'restart') {
        setOnNetStatus('Reiniciando');
        setToast({
          type: 'warning',
          message: res.data?.message || 'Reiniciando el servicio de streaming FlixHN...',
        });
      } else if (serverStatus === 'stopped' || action === 'stop') {
        setOnNetStatus('Detenido');
        setToast({
          type: 'error',
          message: res.data?.message || 'Servicio de streaming detenido.',
        });
      } else if (serverStatus === 'online' || action === 'start') {
        setOnNetStatus('En línea');
        setToast({
          type: 'success',
          message: res.data?.message || 'Servicio de streaming iniciado y en línea.',
        });
      }
    } catch (err) {
      setToast({
        type: 'error',
        message: err.response?.data?.message || `Error al ejecutar acción '${action}'.`,
      });
    } finally {
      setActionLoading(null);
    }
  };

  return (
    <div className="admin-card bg-[#181818] border border-zinc-800 rounded-xl p-5 md:p-6 shadow-xl relative overflow-hidden transition-colors">
      {/* Luz tenue de fondo en esquina */}
      <div className="absolute top-0 right-0 w-96 h-96 bg-red-600/5 rounded-full blur-3xl pointer-events-none -mr-20 -mt-20" />

      {/* Alerta Toast Flotante */}
      {toast && (
        <div className="absolute top-4 right-4 z-50 animate-fadeIn select-none">
          <div
            className={`px-4 py-2.5 rounded-lg text-xs font-semibold shadow-2xl border flex items-center gap-2 backdrop-blur-md ${
              toast.type === 'success'
                ? 'bg-emerald-950/90 text-emerald-300 border-emerald-700/80'
                : toast.type === 'warning'
                ? 'bg-amber-950/90 text-amber-300 border-amber-700/80'
                : 'bg-red-950/90 text-red-300 border-red-700/80'
            }`}
          >
            <span>{toast.message}</span>
            <button
              onClick={() => setToast(null)}
              className="text-zinc-400 hover:text-white ml-1 cursor-pointer"
              type="button"
            >
              <X className="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      )}

      {/* Fila Principal: Identificación del Servidor y Estado */}
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-5 border-b border-zinc-800 pb-5">
        <div className="flex items-start gap-4">
          <div className="p-3 bg-red-500/10 border border-red-500/20 rounded-xl text-[#E50914] shadow-inner shrink-0">
            <Server className="w-7 h-7" />
          </div>
          <div className="space-y-1.5 min-w-0">
            {/* Nombre editable del servidor (Punto 8) */}
            <div className="flex flex-wrap items-center gap-2.5">
              {isEditingName ? (
                <form onSubmit={handleSaveName} className="flex items-center gap-2">
                  <input
                    type="text"
                    value={nameInput}
                    onChange={(e) => setNameInput(e.target.value)}
                    autoFocus
                    disabled={savingName}
                    className="bg-zinc-900 border border-zinc-700 text-white font-black text-lg md:text-xl px-2.5 py-1 rounded-md focus:outline-none focus:border-[#E50914] tracking-wide"
                    placeholder="Nombre del Servidor"
                  />
                  <button
                    type="submit"
                    disabled={savingName}
                    className="p-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-md cursor-pointer transition-colors"
                    title="Guardar Nombre"
                  >
                    {savingName ? <Loader2 className="w-4 h-4 animate-spin" /> : <Check className="w-4 h-4" />}
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      setNameInput(hostname);
                      setIsEditingName(false);
                    }}
                    className="p-1.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 rounded-md cursor-pointer transition-colors"
                    title="Cancelar"
                  >
                    <X className="w-4 h-4" />
                  </button>
                </form>
              ) : (
                <div className="flex items-center gap-2 group">
                  <h1 className="text-xl md:text-2xl font-black text-white tracking-wide truncate max-w-md">
                    {hostname}
                  </h1>
                  <button
                    onClick={() => setIsEditingName(true)}
                    className="p-1 text-zinc-500 hover:text-white rounded hover:bg-zinc-800 transition-colors cursor-pointer"
                    title="Editar nombre del servidor"
                    type="button"
                  >
                    <Pencil className="w-4 h-4" />
                  </button>
                </div>
              )}

              {/* Badge de Estado del Servidor */}
              <span
                className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold border ${
                  onNetStatus === 'Detenido'
                    ? 'bg-red-950/60 text-red-400 border-red-800/80'
                    : onNetStatus === 'Reiniciando'
                    ? 'bg-amber-950/60 text-amber-400 border-amber-800/80'
                    : 'bg-emerald-950/60 text-emerald-400 border-emerald-800/80'
                }`}
              >
                <span
                  className={`w-2 h-2 rounded-full ${
                    onNetStatus === 'Detenido'
                      ? 'bg-red-500'
                      : onNetStatus === 'Reiniciando'
                      ? 'bg-amber-400 animate-spin'
                      : 'bg-emerald-500 animate-pulse'
                  }`}
                />
                {onNetStatus}
              </span>
            </div>

            <div className="flex flex-wrap items-center gap-3 text-xs text-zinc-400">
              <span className="font-mono text-zinc-300">{version}</span>
              <span className="text-zinc-600">•</span>
              <span className="flex items-center gap-1 text-zinc-300">
                <ShieldCheck className="w-3.5 h-3.5 text-zinc-400" />
                FlixHN Node
              </span>
              <span className="text-zinc-600">•</span>
              <span className="flex items-center gap-1 text-zinc-400">
                <Clock className="w-3.5 h-3.5 text-zinc-500" />
                {uptimeText}
              </span>
            </div>
          </div>
        </div>

        {/* Lado Derecho: IPs y Botonera de Control de Servidor (Punto 7) */}
        <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3.5">
          {/* Botonera de Control de Servidor */}
          <div className="flex items-center gap-1.5 p-1.5 bg-zinc-900/90 border border-zinc-800 rounded-lg">
            {/* Iniciar / Encender */}
            <button
              onClick={() => handleServerAction('start')}
              disabled={actionLoading !== null}
              className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-bold transition-all cursor-pointer select-none ${
                onNetStatus === 'En línea'
                  ? 'bg-emerald-950/60 text-emerald-400 border border-emerald-800/80 hover:bg-emerald-900/60'
                  : 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-md'
              }`}
              title="Iniciar servicio de streaming"
              type="button"
            >
              {actionLoading === 'start' ? (
                <Loader2 className="w-3.5 h-3.5 animate-spin" />
              ) : (
                <Play className="w-3.5 h-3.5 fill-current" />
              )}
              <span>Iniciar</span>
            </button>

            {/* Reiniciar Servicio (Ámbar) */}
            <button
              onClick={() => handleServerAction('restart')}
              disabled={actionLoading !== null}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-bold bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 transition-all cursor-pointer select-none active:scale-95"
              title="Reiniciar servicio de streaming"
              type="button"
            >
              {actionLoading === 'restart' ? (
                <Loader2 className="w-3.5 h-3.5 animate-spin" />
              ) : (
                <RotateCw className="w-3.5 h-3.5" />
              )}
              <span>Reiniciar</span>
            </button>

            {/* Detener Servicio (Rojo tenue) */}
            <button
              onClick={() => handleServerAction('stop')}
              disabled={actionLoading !== null}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-bold bg-red-950/40 hover:bg-red-900/50 text-red-400 border border-red-800/50 transition-all cursor-pointer select-none active:scale-95"
              title="Detener servicio de streaming"
              type="button"
            >
              {actionLoading === 'stop' ? (
                <Loader2 className="w-3.5 h-3.5 animate-spin" />
              ) : (
                <Square className="w-3.5 h-3.5 fill-current" />
              )}
              <span>Detener</span>
            </button>
          </div>

          {/* IPs de Acceso LAN y WAN */}
          <div className="flex flex-wrap sm:flex-nowrap items-center gap-2.5">
            <div className="bg-zinc-900/90 border border-zinc-800 px-3 py-1.5 rounded-lg space-y-0.5 min-w-[145px]">
              <div className="flex items-center gap-1.5 text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">
                <Network className="w-3 h-3 text-[#E50914]" />
                LAN Nodo
              </div>
              <p className="font-mono text-xs text-zinc-100 font-semibold">{lanIp}</p>
            </div>

            <div className="bg-zinc-900/90 border border-zinc-800 px-3 py-1.5 rounded-lg space-y-0.5 min-w-[145px]">
              <div className="flex items-center gap-1.5 text-[10px] font-semibold text-zinc-400 uppercase tracking-wider">
                <Wifi className="w-3 h-3 text-blue-400" />
                WAN / Tránsito
              </div>
              <p className="font-mono text-xs text-zinc-100 font-semibold">{wanIp}</p>
            </div>
          </div>
        </div>
      </div>

      {/* Fila Secundaria: Métricas de Recursos en Tiempo Real */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-5 text-xs">
        {/* CPU */}
        <div className="bg-zinc-900/90 border border-zinc-800 p-3.5 rounded-lg flex items-center gap-3 shadow-none">
          <div className="p-2 bg-red-500/10 border border-red-500/20 rounded-md text-[#E50914]">
            <Cpu className="w-4 h-4" />
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-zinc-400">Uso CPU Nodo</p>
            <p className="text-sm font-bold text-white mt-0.5">{cpuUsage}</p>
          </div>
        </div>

        {/* Memoria RAM */}
        <div className="bg-zinc-900/90 border border-zinc-800 p-3.5 rounded-lg flex items-center gap-3 shadow-none">
          <div className="p-2 bg-red-500/10 border border-red-500/20 rounded-md text-[#E50914]">
            <Activity className="w-4 h-4 text-emerald-400" />
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-zinc-400">RAM En Uso</p>
            <p className="text-sm font-bold text-white mt-0.5">{ramUsage}</p>
          </div>
        </div>

        {/* Almacenamiento NVMe */}
        <div className="bg-zinc-900/90 border border-zinc-800 p-3.5 rounded-lg flex items-center gap-3 shadow-none">
          <div className="p-2 bg-red-500/10 border border-red-500/20 rounded-md text-[#E50914]">
            <HardDrive className="w-4 h-4 text-purple-400" />
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-zinc-400">Almacenamiento Local</p>
            <p className="text-sm font-bold text-white mt-0.5">{storageText}</p>
          </div>
        </div>

        {/* Conexión Local */}
        <div className="bg-zinc-900/90 border border-zinc-800 p-3.5 rounded-lg flex items-center gap-3 shadow-none">
          <div className="p-2 bg-red-500/10 border border-red-500/20 rounded-md text-[#E50914]">
            <Wifi className="w-4 h-4 text-emerald-400" />
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-zinc-400">Conectividad Local</p>
            <p className="text-sm font-bold text-emerald-400 mt-0.5">Direct Play On-Net</p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ServerHeader;
