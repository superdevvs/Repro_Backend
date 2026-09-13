/* Option 18: render the saved perspective motion into the browser tab icon. */
(() => {
  const link = document.querySelector('link[data-re-favicon]');
  if (!link) return;
  const staticHref = link.href;
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const controller = new AbortController();
  const canvas = document.createElement('canvas');
  const frames = new Map();
  let context;
  let sprite;
  let manifest;
  let request = 0;
  let startedAt = 0;
  let lastFrame = -1;
  let loading = false;
  let failed = false;
  let disposed = false;

  function stop() {
    cancelAnimationFrame(request);
    request = 0;
    lastFrame = -1;
    link.type = 'image/svg+xml';
    link.href = staticHref;
  }

  function tick(now) {
    request = 0;
    if (disposed || document.hidden || motion.matches) {
      stop();
      return;
    }
    const { frameSize, count, columns, durationMs } = manifest;
    const frame = Math.floor(((now - startedAt) % durationMs) / durationMs * count);
    if (frame !== lastFrame) {
      try {
        if (!frames.has(frame)) {
          context.clearRect(0, 0, frameSize, frameSize);
          context.drawImage(sprite, (frame % columns) * frameSize,
            Math.floor(frame / columns) * frameSize, frameSize, frameSize,
            0, 0, frameSize, frameSize);
          frames.set(frame, canvas.toDataURL('image/png'));
        }
        link.type = 'image/png';
        link.href = frames.get(frame);
        lastFrame = frame;
      } catch {
        failed = true;
        stop();
        return;
      }
    }
    request = requestAnimationFrame(tick);
  }

  async function resume() {
    if (disposed || failed || motion.matches || document.hidden) return;
    if (!manifest) {
      if (loading) return;
      loading = true;
      try {
        const response = await fetch('/brand/re/favicon-animation.json', { signal: controller.signal });
        if (!response.ok) throw new Error('Favicon unavailable');
        const data = await response.json();
        const { frameSize, count, columns, durationMs } = data;
        if (!Number.isInteger(frameSize) || frameSize < 16 || frameSize > 128 ||
            !Number.isInteger(count) || count < 2 || count > 240 ||
            !Number.isInteger(columns) || columns < 1 || columns > count ||
            !Number.isFinite(durationMs) || durationMs < 500 || durationMs > 30000) {
          throw new Error('Invalid favicon animation');
        }
        sprite = new Image();
        await new Promise((resolve, reject) => {
          sprite.onload = resolve;
          sprite.onerror = reject;
          sprite.src = '/brand/re/favicon-sprite.png';
        });
        if (disposed) return;
        if (sprite.naturalWidth < columns * frameSize ||
            sprite.naturalHeight < Math.ceil(count / columns) * frameSize) {
          throw new Error('Incomplete favicon sprite');
        }
        canvas.width = canvas.height = frameSize;
        context = canvas.getContext('2d');
        if (!context) throw new Error('Canvas unavailable');
        manifest = data;
      } catch {
        failed = true;
        stop();
        return;
      } finally {
        loading = false;
      }
    }
    if (disposed || motion.matches || document.hidden || request) return;
    startedAt = performance.now();
    request = requestAnimationFrame(tick);
  }

  function sync() {
    stop();
    void resume();
  }

  function onPageHide(event) {
    stop();
    if (event.persisted) return;
    disposed = true;
    controller.abort();
    motion.removeEventListener('change', sync);
    document.removeEventListener('visibilitychange', sync);
    window.removeEventListener('pageshow', sync);
    window.removeEventListener('pagehide', onPageHide);
    frames.clear();
  }

  motion.addEventListener('change', sync);
  document.addEventListener('visibilitychange', sync);
  window.addEventListener('pageshow', sync);
  window.addEventListener('pagehide', onPageHide);
  void resume();
})();
