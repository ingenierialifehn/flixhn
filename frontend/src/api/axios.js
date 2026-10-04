import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
});

// Interceptor para inyectar token Sanctum
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('flixhn_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
}, (error) => {
  return Promise.reject(error);
});

// Interceptor para manejar expiración o suspensión
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response && error.response.status === 401) {
      localStorage.removeItem('flixhn_token');
      localStorage.removeItem('flixhn_user');
      localStorage.removeItem('flixhn_profile');
      window.dispatchEvent(new Event('flixhn_unauthorized'));
    }
    return Promise.reject(error);
  }
);

export default api;
