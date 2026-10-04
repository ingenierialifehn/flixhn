import React, { createContext, useContext, useState, useEffect, useCallback, useMemo } from 'react';
import api from '../api/axios';

const AuthContext = createContext(null);

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(() => {
    try {
      const saved = localStorage.getItem('flixhn_user');
      return saved && saved !== 'undefined' ? JSON.parse(saved) : null;
    } catch {
      localStorage.removeItem('flixhn_user');
      return null;
    }
  });
  const [token, setToken] = useState(() => localStorage.getItem('flixhn_token') || localStorage.getItem('token') || null);
  const [activeProfile, setActiveProfile] = useState(() => {
    try {
      const saved = localStorage.getItem('flixhn_profile');
      return saved && saved !== 'undefined' ? JSON.parse(saved) : null;
    } catch {
      localStorage.removeItem('flixhn_profile');
      return null;
    }
  });
  const [clientIp, setClientIp] = useState(null);
  const [isOnNet, setIsOnNet] = useState(true);
  const [loading, setLoading] = useState(true);

  // Escuchar eventos de sesión no autorizada (401 de Axios)
  useEffect(() => {
    const handleUnauthorized = () => {
      setToken(null);
      setUser(null);
      setActiveProfile(null);
      setLoading(false);
    };
    window.addEventListener('flixhn_unauthorized', handleUnauthorized);
    return () => window.removeEventListener('flixhn_unauthorized', handleUnauthorized);
  }, []);

  // Comprobar token al inicio y blindaje de sesión
  useEffect(() => {
    if (token) {
      api.get('/auth/me')
        .then((res) => {
          if (res.data?.user) {
            setUser(res.data.user);
            setClientIp(res.data.client_ip || null);
            setIsOnNet(res.data.is_on_net ?? true);
            localStorage.setItem('flixhn_user', JSON.stringify(res.data.user));
          } else {
            throw new Error('Respuesta inválida de usuario');
          }
        })
        .catch((err) => {
          // Blindaje estricto de sesión (Punto 10):
          // Solo destruir token si el backend responde con un 401 explícito
          if (err.response && err.response.status === 401) {
            setToken(null);
            setUser(null);
            setActiveProfile(null);
            localStorage.removeItem('flixhn_token');
            localStorage.removeItem('token');
            localStorage.removeItem('flixhn_user');
            localStorage.removeItem('flixhn_profile');
          } else {
            console.warn('Revalidación silenciosa: Red inaccesible o error no fatal. Conservando sesión local.');
          }
        })
        .finally(() => {
          setLoading(false);
        });
    } else {
      setLoading(false);
    }
  }, [token]);

  const selectProfile = useCallback((profile) => {
    setActiveProfile(profile);
    localStorage.setItem('flixhn_profile', JSON.stringify(profile));
  }, []);

  const clearProfile = useCallback(() => {
    setActiveProfile(null);
    localStorage.removeItem('flixhn_profile');
  }, []);

  const login = useCallback(async (username, password) => {
    let res;
    try {
      res = await api.post('/login', { username, password });
    } catch (err) {
      if (err.response?.status === 404) {
        res = await api.post('/auth/login', { username, password });
      } else {
        throw err;
      }
    }
    const { token: newToken, user: userData, client_ip } = res.data;

    setToken(newToken);
    setUser(userData);
    setClientIp(client_ip);

    localStorage.setItem('flixhn_token', newToken);
    localStorage.setItem('token', newToken);
    localStorage.setItem('flixhn_user', JSON.stringify(userData));

    // Si tiene un solo perfil, auto-seleccionar; de lo contrario mostrar selector
    if (userData.profiles && userData.profiles.length === 1) {
      selectProfile(userData.profiles[0]);
    } else {
      setActiveProfile(null);
      localStorage.removeItem('flixhn_profile');
    }

    return userData;
  }, [selectProfile]);

  const logout = useCallback(async () => {
    try {
      if (token) {
        await api.post('/auth/logout');
      }
    } catch (e) {
      // Ignorar errores al cerrar sesión
    } finally {
      setToken(null);
      setUser(null);
      setActiveProfile(null);
      localStorage.removeItem('flixhn_token');
      localStorage.removeItem('token');
      localStorage.removeItem('flixhn_user');
      localStorage.removeItem('flixhn_profile');
    }
  }, [token]);

  const addProfile = useCallback(async (name, avatar_color, is_kids) => {
    const res = await api.post('/profiles', { name, avatar_color, is_kids });
    const newProfile = res.data.profile;
    setUser((prevUser) => {
      const updatedProfiles = [...(prevUser?.profiles || []), newProfile];
      const updatedUser = { ...prevUser, profiles: updatedProfiles };
      localStorage.setItem('flixhn_user', JSON.stringify(updatedUser));
      return updatedUser;
    });
    return newProfile;
  }, []);

  const value = useMemo(
    () => ({
      user,
      token,
      activeProfile,
      clientIp,
      isOnNet,
      loading,
      login,
      logout,
      selectProfile,
      clearProfile,
      addProfile,
    }),
    [
      user,
      token,
      activeProfile,
      clientIp,
      isOnNet,
      loading,
      login,
      logout,
      selectProfile,
      clearProfile,
      addProfile,
    ]
  );

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => useContext(AuthContext);
