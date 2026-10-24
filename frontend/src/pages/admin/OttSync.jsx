import React, { useRef, useState, useEffect, useMemo, useCallback } from 'react';
import OttSyncManagement from './OttSyncManagement';
import api from '../../services/api';

/**
 * Componente OttSync (Página administrativa de Sincronización OTT)
 * Verifica plataformas en estado 'connected' para restringir descargas e ingesta física a proveedores activos.
 */
const OttSync = (props) => {
  const containerRef = useRef(null);
  const [connectedPlatforms, setConnectedPlatforms] = useState([]);

  useEffect(() => {
    api
      .get('/admin/ott-providers')
      .then((res) => {
        if (res.data?.providers && Array.isArray(res.data.providers)) {
          const connected = res.data.providers.filter((p) => p.status === 'connected');
          setConnectedPlatforms(connected);
        }
      })
      .catch(() => {});
  }, []);

  return (
    <div ref={containerRef} className="ottsync-page-root w-full h-full">
      <OttSyncManagement {...props} connectedPlatforms={connectedPlatforms} />
    </div>
  );
};

export default OttSync;
