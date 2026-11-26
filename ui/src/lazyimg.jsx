import { useEffect, useRef, useState } from 'react';

// One image component for the whole app: it asks for the picture only when it is about to be seen, at most MAX pictures
// download at the same time (the rest wait in a queue, nearest to the screen first), a shimmer shows while it loads,
// and a broken or blocked picture falls back to a quiet placeholder instead of the browser's broken-image icon.
const MAX = 6; let active = 0; const queue = [];
const pump = () => { while (active < MAX && queue.length) { const job = queue.shift(); if (job.cancelled) continue; active++; job.start(() => { active--; pump(); }); } };
const enqueue = (job) => { queue.push(job); pump(); return () => { job.cancelled = true; }; };
const BLANK = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

export function LazyImg({ src, alt = '', className = '', style, onLoad, ...rest }) {
  const ref = useRef(null); const [state, setState] = useState(src ? 'idle' : 'error'); const [shown, setShown] = useState(null); const [tries, setTries] = useState(0);
  useEffect(() => { setState(src ? 'idle' : 'error'); setShown(null); setTries(0); }, [src]);
  useEffect(() => {
    if (!src || state !== 'idle') return; const el = ref.current; if (!el) return; let cancelQueue = () => {};
    const go = () => { cancelQueue = enqueue({ start: done => { const probe = new Image(); probe.referrerPolicy = 'no-referrer'; probe.decoding = 'async'; probe.onload = () => { setShown(src); setState('loaded'); done(); }; probe.onerror = () => { if (tries < 1) { setTries(t => t + 1); setState('idle'); } else setState('error'); done(); }; setState('loading'); probe.src = src; } }); };
    const io = new IntersectionObserver(es => { if (es.some(e => e.isIntersecting)) { io.disconnect(); go(); } }, { rootMargin: '400px 0px' }); io.observe(el);
    return () => { io.disconnect(); cancelQueue(); };
  }, [src, state, tries]);
  return <img ref={ref} src={state === 'loaded' ? shown : BLANK} alt={alt} decoding="async" referrerPolicy="no-referrer" className={`lz lz-${state}${className ? ' ' + className : ''}`} style={style} onLoad={e => { if (state === 'loaded' && onLoad) onLoad(e); }} {...rest} />;
}
