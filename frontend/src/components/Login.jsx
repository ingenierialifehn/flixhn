import React, { useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { Lock, User, Server } from 'lucide-react';

const Login = () => {
  const { login } = useAuth();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      await login(username.trim(), password);
    } catch (err) {
      if (err.response?.data?.errors?.username) {
        setError(err.response.data.errors.username[0]);
      } else if (err.response?.data?.message) {
        setError(err.response.data.message);
      } else {
        setError('Credenciales incorrectas o no se pudo conectar al servidor FlixHN del ISP.');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="relative min-h-screen w-full bg-[#141414] text-white flex flex-col justify-between select-none">
      {/* Fondo con imagen tenue estilo Netflix */}
      <div
        className="absolute inset-0 bg-cover bg-center opacity-30 pointer-events-none"
        style={{
          backgroundImage:
            'url("https://images.unsplash.com/photo-1574375927938-d5a98e8ffe85?q=80&w=1920&auto=format&fit=crop")',
        }}
      />
      <div className="absolute inset-0 bg-gradient-to-t from-[#141414] via-[#141414]/80 to-[#141414]/60 pointer-events-none" />

      {/* Cabecera con Logo FlixHN y Badge de Red ISP */}
      <header className="relative z-10 px-6 md:px-14 py-6 flex items-center justify-between">
        <div className="flex items-center gap-1">
          <span className="font-display text-4xl md:text-5xl tracking-wider text-[#E50914] font-black">
            FLIX
          </span>
          <span className="bg-[#E50914] text-white text-sm font-black px-1.5 py-0.5 rounded tracking-widest">
            HN
          </span>
        </div>

        <div className="flex items-center gap-2 bg-neutral-900/90 border border-neutral-800 px-3 py-1.5 rounded-full text-xs text-zinc-300 shadow-sm">
          <Server className="w-3.5 h-3.5 text-green-500" />
          <span className="hidden sm:inline">Red Local ISP On-Net</span>
          <span className="sm:hidden">On-Net</span>
        </div>
      </header>

      {/* Tarjeta Central de Inicio de Sesión Restaurada */}
      <main className="relative z-10 flex items-center justify-center p-4">
        <div className="w-full max-w-md p-8 md:p-10 rounded-lg bg-black/75 backdrop-blur-md border border-zinc-800 shadow-2xl">
          <h1 className="text-3xl font-bold text-white mb-6">Inicia sesión</h1>

          {/* Mensaje de error si existe */}
          {error && (
            <div className="mb-4 p-3 rounded bg-red-500/10 border border-red-500/50 text-red-400 text-sm">
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-xs font-medium text-zinc-400 mb-1">
                Usuario del Contrato ISP
              </label>
              <div className="relative">
                <span className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-zinc-500">
                  <User className="w-5 h-5" />
                </span>
                <input
                  type="text"
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  placeholder="admin o cliente1"
                  required
                  className="w-full pl-10 pr-3 py-3 bg-[#333333] border border-transparent focus:border-zinc-500 rounded text-white text-sm outline-none placeholder-zinc-500 transition-colors"
                />
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-zinc-400 mb-1">
                Contraseña
              </label>
              <div className="relative">
                <span className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-zinc-500">
                  <Lock className="w-5 h-5" />
                </span>
                <input
                  type="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Tu contraseña asignada"
                  required
                  className="w-full pl-10 pr-3 py-3 bg-[#333333] border border-transparent focus:border-zinc-500 rounded text-white text-sm outline-none placeholder-zinc-500 transition-colors"
                />
              </div>
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full py-3 mt-4 bg-[#E50914] hover:bg-[#b80710] text-white font-semibold rounded transition-colors flex items-center justify-center text-sm shadow-md disabled:opacity-50"
            >
              {loading ? 'Accediendo...' : 'Acceder a FlixHN'}
            </button>
          </form>
        </div>
      </main>

      {/* Pie de página informativo */}
      <footer className="relative z-10 py-6 text-center text-xs text-zinc-500 border-t border-zinc-900">
        FlixHN • Servidor Local de Streaming para Redes de Fibra Óptica e ISP On-Net.
      </footer>
    </div>
  );
};

export default Login;
