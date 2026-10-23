import React, { useRef, useState, useEffect, useMemo, useCallback } from 'react';
import OttSyncManagement from './OttSyncManagement';

/**
 * Componente OttSync (Página administrativa de Sincronización OTT)
 * Envuelve formalmente OttSyncManagement con los hooks de React importados explícitamente.
 */
const OttSync = (props) => {
  const containerRef = useRef(null);

  return (
    <div ref={containerRef} className="ottsync-page-root w-full h-full">
      <OttSyncManagement {...props} />
    </div>
  );
};

export default OttSync;
