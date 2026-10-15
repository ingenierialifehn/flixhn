import fs from 'node:fs';

if (!globalThis.__fs_shim_applied) {
  globalThis.__fs_shim_applied = true;

  // 1. Synchronous operations retry helper
  function retrySync(fn, fallbackVal, maxRetries = 25) {
    let retries = maxRetries;
    while (true) {
      try {
        return fn();
      } catch (err) {
        if ((err?.code === 'EIO' || err?.code === 'EBUSY') && retries > 0) {
          retries--;
          const delay = Math.min(10 + (maxRetries - retries) * 5, 100);
          const end = Date.now() + delay;
          while (Date.now() < end) {} // sync backoff
        } else if ((err?.code === 'EIO' || err?.code === 'EBUSY') && fallbackVal !== undefined) {
          return fallbackVal;
        } else {
          throw err;
        }
      }
    }
  }

  // 2. Async operations retry helper with progressive backoff
  async function retryAsync(fn, fallbackVal, maxRetries = 30) {
    let retries = maxRetries;
    while (true) {
      try {
        return await fn();
      } catch (err) {
        if ((err?.code === 'EIO' || err?.code === 'EBUSY') && retries > 0) {
          retries--;
          const delay = Math.min(20 + (maxRetries - retries) * 15, 300);
          await new Promise((r) => setTimeout(r, delay));
        } else if ((err?.code === 'EIO' || err?.code === 'EBUSY') && fallbackVal !== undefined) {
          return fallbackVal;
        } else {
          throw err;
        }
      }
    }
  }

  // 3. openSync patch
  if (fs.openSync) {
    const origOpenSync = fs.openSync;
    fs.openSync = function (...args) {
      return retrySync(() => origOpenSync.apply(fs, args));
    };
  }

  // 4. realpathSync & realpathSync.native patch
  if (fs.realpathSync) {
    const origRealpathSync = fs.realpathSync;
    const origRealpathSyncNative = fs.realpathSync.native || origRealpathSync;

    const wrappedNative = function (p, options) {
      return retrySync(() => origRealpathSyncNative.call(fs, p, options), typeof p === 'string' ? p : undefined);
    };

    const wrappedSync = function (p, options) {
      return retrySync(() => origRealpathSync.call(fs, p, options), typeof p === 'string' ? p : undefined);
    };

    wrappedSync.native = wrappedNative;
    fs.realpathSync = wrappedSync;
  }

  // 5. statSync & lstatSync patch
  if (fs.statSync) {
    const origStatSync = fs.statSync;
    fs.statSync = function (...args) {
      return retrySync(() => origStatSync.apply(fs, args));
    };
  }

  if (fs.lstatSync) {
    const origLstatSync = fs.lstatSync;
    fs.lstatSync = function (...args) {
      return retrySync(() => origLstatSync.apply(fs, args));
    };
  }

  // 6. readFileSync & readdirSync patch
  if (fs.readFileSync) {
    const origReadFileSync = fs.readFileSync;
    fs.readFileSync = function (...args) {
      return retrySync(() => origReadFileSync.apply(fs, args));
    };
  }

  if (fs.readdirSync) {
    const origReaddirSync = fs.readdirSync;
    fs.readdirSync = function (...args) {
      return retrySync(() => origReaddirSync.apply(fs, args));
    };
  }

  // 7. Safe patch helper
  const safePatchAsync = (target, prop, fallbackVal) => {
    if (!target) return;
    try {
      const orig = target[prop];
      if (typeof orig === 'function') {
        target[prop] = function (...args) {
          return retryAsync(() => orig.apply(target, args), fallbackVal ? (typeof args[0] === 'string' ? args[0] : args[0]?.toString()) : undefined);
        };
      }
    } catch {
      // Ignore read-only namespace targets
    }
  };

  // Patch fs.promises methods (open, readFile, stat, lstat, realpath)
  safePatchAsync(fs.promises, 'open');
  safePatchAsync(fs.promises, 'readFile');
  safePatchAsync(fs.promises, 'stat');
  safePatchAsync(fs.promises, 'lstat');
  safePatchAsync(fs.promises, 'realpath', true);
}
