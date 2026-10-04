import React, { useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { useTheme } from '../context/ThemeContext';
import { Plus, Sun, Moon } from 'lucide-react';

const ProfileSelector = () => {
  const { user, selectProfile, addProfile } = useAuth();
  const { theme, toggleTheme } = useTheme();
  const [isAdding, setIsAdding] = useState(false);
  const [newProfileName, setNewProfileName] = useState('');
  const [isKids, setIsKids] = useState(false);
  const [selectedColor, setSelectedColor] = useState('#E50914');
  const [error, setError] = useState('');

  const colors = ['#E50914', '#0071EB', '#2BD465', '#F5A623', '#9B51E0', '#E50980'];

  const handleCreate = async (e) => {
    e.preventDefault();
    if (!newProfileName.trim()) return;

    try {
      await addProfile(newProfileName.trim(), selectedColor, isKids);
      setNewProfileName('');
      setIsAdding(false);
      setError('');
    } catch (err) {
      setError(err.response?.data?.message || 'Error al agregar perfil.');
    }
  };

  return (
    <div className="min-h-screen w-full bg-gray-100 dark:bg-[#141414] flex flex-col items-center justify-center p-4 select-none animate-fadeIn transition-colors duration-200 relative">
      {/* Logotipo Superior */}
      <div className="absolute top-8 left-8 md:left-14 flex items-center gap-1">
        <span className="font-display text-3xl md:text-4xl tracking-wider text-[#E50914] font-black">
          FLIX
        </span>
        <span className="bg-[#E50914] text-white text-xs md:text-sm font-black px-1.5 py-0.5 rounded tracking-widest">
          HN
        </span>
      </div>

      {/* Botón Theme Toggle Superior */}
      <div className="absolute top-8 right-8 md:right-14">
        <button
          type="button"
          onClick={toggleTheme}
          title={theme === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
          className="p-2 rounded-full border bg-white/90 dark:bg-neutral-900/90 text-zinc-500 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-white border-gray-200 dark:border-neutral-700/80 shadow-sm transition-colors cursor-pointer"
          aria-label={theme === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
        >
          {theme === 'dark' ? (
            <Sun className="w-5 h-5 text-yellow-400" />
          ) : (
            <Moon className="w-5 h-5 text-zinc-700 dark:text-zinc-300" />
          )}
        </button>
      </div>

      <div className="max-w-4xl text-center space-y-8 z-10 pt-16 sm:pt-0">
        <h1 className="text-3xl md:text-5xl font-medium text-gray-900 dark:text-white tracking-wide">
          ¿Quién está viendo ahora?
        </h1>

        {/* Lista de Perfiles */}
        <div className="flex flex-wrap items-center justify-center gap-6 md:gap-10 pt-4">
          {user?.profiles?.map((profile) => (
            <div
              key={profile.id}
              onClick={() => selectProfile(profile)}
              className="group flex flex-col items-center gap-3 cursor-pointer"
            >
              <div
                className="w-24 h-24 sm:w-32 sm:h-32 md:w-36 md:h-36 rounded-md flex items-center justify-center text-4xl sm:text-5xl font-black text-white shadow-xl transition-all duration-200 group-hover:ring-4 group-hover:ring-white group-hover:scale-105 relative overflow-hidden"
                style={{ backgroundColor: profile.avatar_color || '#E50914' }}
              >
                {profile.name.charAt(0).toUpperCase()}

                {profile.is_kids && (
                  <span className="absolute bottom-1 bg-black/60 text-[10px] uppercase font-bold tracking-widest px-2 py-0.5 rounded text-yellow-300">
                    Niños
                  </span>
                )}
              </div>
              <span className="text-gray-600 dark:text-gray-400 text-sm sm:text-base md:text-lg group-hover:text-gray-900 dark:group-hover:text-white transition-colors font-medium">
                {profile.name}
              </span>
            </div>
          ))}

          {/* Botón para Añadir Perfil (Límite 5) */}
          {user?.profiles?.length < 5 && !isAdding && (
            <div
              onClick={() => setIsAdding(true)}
              className="group flex flex-col items-center gap-3 cursor-pointer"
            >
              <div className="w-24 h-24 sm:w-32 sm:h-32 md:w-36 md:h-36 rounded-md border-2 border-dashed border-gray-400 dark:border-gray-600 flex items-center justify-center text-gray-500 dark:text-gray-400 group-hover:border-[#E50914] group-hover:text-[#E50914] dark:group-hover:border-white dark:group-hover:text-white group-hover:scale-105 transition-all">
                <Plus className="w-12 h-12 stroke-[1.5]" />
              </div>
              <span className="text-gray-600 dark:text-gray-400 text-sm sm:text-base md:text-lg group-hover:text-gray-900 dark:group-hover:text-white transition-colors font-medium">
                Añadir perfil
              </span>
            </div>
          )}
        </div>

        {/* Modal / Formulario para Añadir Perfil */}
        {isAdding && (
          <div className="mt-8 p-6 bg-white dark:bg-[#181818] border border-gray-200 dark:border-neutral-700 rounded-xl max-w-md mx-auto space-y-4 text-left shadow-2xl animate-fadeIn transition-colors">
            <h3 className="text-lg font-bold text-gray-900 dark:text-white">Nuevo Perfil</h3>

            {error && <p className="text-xs text-red-500">{error}</p>}

            <form onSubmit={handleCreate} className="space-y-4">
              <div>
                <label className="text-xs text-gray-700 dark:text-gray-300 block mb-1">Nombre del perfil</label>
                <input
                  type="text"
                  required
                  maxLength={50}
                  value={newProfileName}
                  onChange={(e) => setNewProfileName(e.target.value)}
                  placeholder="Ej: Sala de Estar, Ana..."
                  className="w-full bg-gray-50 dark:bg-[#242424] text-gray-900 dark:text-white border border-gray-300 dark:border-neutral-600 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#E50914]"
                />
              </div>

              <div>
                <label className="text-xs text-gray-700 dark:text-gray-300 block mb-1">Color del Avatar</label>
                <div className="flex items-center gap-2">
                  {colors.map((c) => (
                    <button
                      type="button"
                      key={c}
                      onClick={() => setSelectedColor(c)}
                      className={`w-7 h-7 rounded-full transition-transform ${
                        selectedColor === c ? 'scale-125 ring-2 ring-gray-900 dark:ring-white' : 'opacity-80 hover:opacity-100'
                      }`}
                      style={{ backgroundColor: c }}
                    />
                  ))}
                </div>
              </div>

              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="kidsCheck"
                  checked={isKids}
                  onChange={(e) => setIsKids(e.target.checked)}
                  className="accent-[#E50914] w-4 h-4 rounded cursor-pointer"
                />
                <label htmlFor="kidsCheck" className="text-xs text-gray-700 dark:text-gray-300 cursor-pointer">
                  ¿Es un perfil para niños? (Solo contenidos infantiles)
                </label>
              </div>

              <div className="flex items-center justify-end gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setIsAdding(false)}
                  className="px-4 py-2 text-xs font-semibold text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white transition-colors"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="px-5 py-2 text-xs font-bold bg-[#E50914] hover:bg-[#F40612] text-white rounded-lg transition-colors shadow-md"
                >
                  Guardar Perfil
                </button>
              </div>
            </form>
          </div>
        )}

        <div className="pt-8">
          <span className="text-xs text-gray-500 font-mono">
            Suscriptor ISP: {user?.customer_name || user?.username} • Máx. {user?.max_screens} dispositivos simultáneos
          </span>
        </div>
      </div>
    </div>
  );
};

export default ProfileSelector;
