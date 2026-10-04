import React, { useState } from 'react';
import api from '../../services/api';
import { Users, Plus, CheckCircle, XCircle, Key, Trash2, X } from 'lucide-react';

const UsersManagement = ({ subscribers = [], onRefresh }) => {
  const [showAddSubscriber, setShowAddSubscriber] = useState(false);
  const [showResetPass, setShowResetPass] = useState(null); // userId
  const [newPassword, setNewPassword] = useState('');
  const [loading, setLoading] = useState(false);

  const [newSub, setNewSub] = useState({
    username: '',
    password: '',
    customer_name: '',
    assigned_ip: '',
    max_screens: 2,
  });

  const handleCreateSubscriber = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      await api.post('/admin/users', newSub);
      setShowAddSubscriber(false);
      setNewSub({
        username: '',
        password: '',
        customer_name: '',
        assigned_ip: '',
        max_screens: 2,
      });
      if (onRefresh) onRefresh();
    } catch (err) {
      alert(err.response?.data?.message || 'Error al registrar suscriptor');
    } finally {
      setLoading(false);
    }
  };

  const handleToggleActive = async (id) => {
    try {
      await api.post(`/admin/users/${id}/toggle-active`);
      if (onRefresh) onRefresh();
    } catch (err) {
      alert('Error modificando estado del suscriptor');
    }
  };

  const handleResetPassword = async (e) => {
    e.preventDefault();
    if (!newPassword || newPassword.length < 6) {
      alert('La contraseña debe tener mínimo 6 caracteres');
      return;
    }
    try {
      await api.post(`/admin/users/${showResetPass}/reset-password`, {
        password: newPassword,
      });
      alert('Contraseña restablecida correctamente.');
      setShowResetPass(null);
      setNewPassword('');
    } catch (err) {
      alert('Error al restablecer contraseña');
    }
  };

  const handleDeleteUser = async (id) => {
    if (!window.confirm('¿Seguro que deseas eliminar este suscriptor?')) return;
    try {
      await api.delete(`/admin/users/${id}`);
      if (onRefresh) onRefresh();
    } catch (err) {
      alert('Error al eliminar usuario');
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
          <h2 className="text-xl md:text-2xl font-black text-gray-900 dark:text-white tracking-wide flex items-center gap-2">
            <Users className="w-6 h-6 text-[#E50914]" />
            Usuarios / Contratos de Abonados
          </h2>
          <p className="text-xs text-gray-500 dark:text-zinc-400">
            Gestión de cuentas de clientes ISP, límites de pantallas simultáneas y perfiles
          </p>
        </div>

        <button
          onClick={() => setShowAddSubscriber(true)}
          className="bg-[#E50914] hover:bg-[#F40612] text-white px-4 py-2 rounded-lg text-xs font-bold flex items-center gap-2 transition-all shadow-md active:scale-95"
        >
          <Plus className="w-4 h-4" />
          Nuevo Suscriptor
        </button>
      </div>

      {/* Tabla de Suscriptores */}
      <div className="overflow-x-auto admin-card bg-[#181818] rounded-xl border border-zinc-800 shadow-xl transition-colors">
        <table className="w-full text-left text-xs">
          <thead className="bg-gray-50 dark:bg-zinc-900/90 text-gray-500 dark:text-zinc-400 uppercase border-b border-gray-200 dark:border-zinc-800 font-semibold tracking-wider">
            <tr>
              <th className="p-3.5">Usuario (Login)</th>
              <th className="p-3.5">Nombre / Contrato ISP</th>
              <th className="p-3.5">Rol</th>
              <th className="p-3.5">IP Asignada ISP</th>
              <th className="p-3.5">Pantallas</th>
              <th className="p-3.5">Perfiles</th>
              <th className="p-3.5">Estado</th>
              <th className="p-3.5 text-right">Acciones</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-zinc-800/80">
            {(subscribers || []).map((u) => (
              <tr key={u?.id} className="hover:bg-gray-50 dark:hover:bg-zinc-800/40 transition-colors">
                <td className="p-3.5 font-mono font-bold text-gray-900 dark:text-white">{u?.username}</td>
                <td className="p-3.5 text-gray-700 dark:text-zinc-200">{u?.customer_name || 'N/D'}</td>
                <td className="p-3.5">
                  <span className={`px-2 py-0.5 rounded font-mono uppercase text-[10px] font-semibold ${
                    u?.role === 'admin'
                      ? 'bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-800/60 font-bold'
                      : 'bg-gray-100 dark:bg-zinc-800 text-gray-700 dark:text-zinc-300'
                  }`}>
                    {u?.role}
                  </span>
                </td>
                <td className="p-3.5 font-mono text-gray-600 dark:text-zinc-300">{u?.assigned_ip || 'Cualquiera (DHCP)'}</td>
                <td className="p-3.5 font-mono text-gray-600 dark:text-zinc-300">{u?.max_screens || 2} pantallas</td>
                <td className="p-3.5">
                  <div className="flex items-center gap-1">
                    {(u?.profiles || []).map((p) => (
                      <span
                        key={p?.id}
                        className="w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold text-white shadow-sm"
                        style={{ backgroundColor: p?.avatar_color || '#E50914' }}
                        title={p?.name}
                      >
                        {p?.name?.charAt(0) || 'U'}
                      </span>
                    ))}
                  </div>
                </td>
                <td className="p-3.5">
                  {u.is_active ? (
                    <span className="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400 font-semibold">
                      <CheckCircle className="w-3.5 h-3.5" /> Activo
                    </span>
                  ) : (
                    <span className="flex items-center gap-1.5 text-red-600 dark:text-red-400 font-semibold">
                      <XCircle className="w-3.5 h-3.5" /> Suspendido
                    </span>
                  )}
                </td>
                <td className="p-3.5 text-right space-x-2">
                  <button
                    onClick={() => handleToggleActive(u.id)}
                    className="text-xs text-amber-700 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 font-semibold px-2 py-1 rounded bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/40 transition-colors"
                  >
                    {u.is_active ? 'Suspender' : 'Activar'}
                  </button>
                  <button
                    onClick={() => setShowResetPass(u.id)}
                    className="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 p-1.5 rounded hover:bg-gray-100 dark:hover:bg-zinc-800 transition-colors"
                    title="Restablecer contraseña"
                  >
                    <Key className="w-3.5 h-3.5 inline" />
                  </button>
                  {u.role !== 'admin' && (
                    <button
                      onClick={() => handleDeleteUser(u.id)}
                      className="text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 p-1.5 rounded hover:bg-gray-100 dark:hover:bg-zinc-800 transition-colors"
                      title="Eliminar usuario"
                    >
                      <Trash2 className="w-3.5 h-3.5 inline" />
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Modal Nuevo Suscriptor */}
      {showAddSubscriber && (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white dark:bg-[#181818] border border-gray-200 dark:border-zinc-800 rounded-xl max-w-md w-full p-6 space-y-4 shadow-2xl">
            <div className="flex items-center justify-between border-b border-gray-200 dark:border-zinc-800 pb-3">
              <h3 className="font-bold text-base text-gray-900 dark:text-white">Nuevo Suscriptor / Contrato ISP</h3>
              <button onClick={() => setShowAddSubscriber(false)} className="text-gray-400 dark:text-zinc-400 hover:text-gray-900 dark:hover:text-white">
                <X className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleCreateSubscriber} className="space-y-3 text-xs">
              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">Nombre Completo del Cliente / Hogar</label>
                <input
                  type="text"
                  required
                  value={newSub.customer_name}
                  onChange={(e) => setNewSub({ ...newSub, customer_name: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                  placeholder="Ej: Familia Morales (Contrato #1042)"
                />
              </div>

              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">Nombre de Usuario (Login)</label>
                <input
                  type="text"
                  required
                  value={newSub.username}
                  onChange={(e) => setNewSub({ ...newSub, username: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white font-mono focus:outline-none focus:border-[#E50914]"
                  placeholder="ej: cliente1042"
                />
              </div>

              <div>
                <label className="text-gray-600 dark:text-zinc-400 block mb-1">Contraseña Inicial</label>
                <input
                  type="password"
                  required
                  value={newSub.password}
                  onChange={(e) => setNewSub({ ...newSub, password: e.target.value })}
                  className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white font-mono focus:outline-none focus:border-[#E50914]"
                  placeholder="Mínimo 6 caracteres"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-gray-600 dark:text-zinc-400 block mb-1">IP Asignada ISP (Opcional)</label>
                  <input
                    type="text"
                    value={newSub.assigned_ip}
                    onChange={(e) => setNewSub({ ...newSub, assigned_ip: e.target.value })}
                    className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white font-mono focus:outline-none focus:border-[#E50914]"
                    placeholder="192.168.100.XX"
                  />
                </div>
                <div>
                  <label className="text-gray-600 dark:text-zinc-400 block mb-1">Pantallas Máx.</label>
                  <input
                    type="number"
                    min="1"
                    max="10"
                    value={newSub.max_screens}
                    onChange={(e) => setNewSub({ ...newSub, max_screens: parseInt(e.target.value) || 2 })}
                    className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white focus:outline-none focus:border-[#E50914]"
                  />
                </div>
              </div>

              <div className="flex justify-end gap-2 pt-4 border-t border-gray-200 dark:border-zinc-800">
                <button
                  type="button"
                  onClick={() => setShowAddSubscriber(false)}
                  className="px-4 py-2 bg-gray-100 dark:bg-zinc-800 hover:bg-gray-200 dark:hover:bg-zinc-700 rounded text-gray-700 dark:text-white transition-colors"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={loading}
                  className="px-4 py-2 bg-[#E50914] hover:bg-[#F40612] rounded text-white font-bold disabled:opacity-50 transition-colors shadow-sm"
                >
                  {loading ? 'Guardando...' : 'Crear Suscriptor'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Reset Password */}
      {showResetPass && (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white dark:bg-[#181818] border border-gray-200 dark:border-zinc-800 rounded-xl max-w-sm w-full p-5 space-y-4 shadow-2xl">
            <h3 className="font-bold text-sm text-gray-900 dark:text-white">Restablecer Contraseña</h3>
            <form onSubmit={handleResetPassword} className="space-y-3 text-xs">
              <input
                type="password"
                required
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                placeholder="Nueva Contraseña..."
                className="w-full bg-gray-50 dark:bg-zinc-900 border border-gray-300 dark:border-zinc-800 rounded px-3 py-2 text-gray-900 dark:text-white font-mono focus:outline-none focus:border-[#E50914]"
              />
              <div className="flex justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => {
                    setShowResetPass(null);
                    setNewPassword('');
                  }}
                  className="px-3 py-1.5 bg-gray-100 dark:bg-zinc-800 hover:bg-gray-200 dark:hover:bg-zinc-700 rounded text-gray-700 dark:text-white transition-colors"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="px-3 py-1.5 bg-[#E50914] hover:bg-[#F40612] rounded text-white font-bold transition-colors shadow-sm"
                >
                  Guardar
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default UsersManagement;
