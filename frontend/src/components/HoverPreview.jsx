import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import HoverPreviewCard from './HoverPreviewCard';

const HoverPreview = (props) => {
  return <HoverPreviewCard {...props} />;
};

export { HoverPreviewCard };
export default HoverPreview;
