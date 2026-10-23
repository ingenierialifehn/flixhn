import React, { useRef, useState, useEffect, useMemo, useCallback } from 'react';
import HoverPreviewCard from './HoverPreviewCard';

/**
 * Componente HoverPreview (Alias y Wrapper seguro de HoverPreviewCard)
 * Garantiza cumplimiento de las reglas de Hooks y compatibilidad con importaciones directas.
 */
const HoverPreview = (props) => {
  const containerRef = useRef(null);

  // Verificación defensiva previa
  if (!props || !props.rect || (!props.item && !props.title)) {
    return null;
  }

  return (
    <div ref={containerRef} className="hover-preview-root">
      <HoverPreviewCard {...props} />
    </div>
  );
};

export { HoverPreviewCard };
export default HoverPreview;
