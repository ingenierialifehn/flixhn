// Concurrency and retry limiter for Docker on Windows NTFS mounts to prevent EIO
import fs from 'node:fs';

if (!globalThis.__fs_shim_applied) {
  globalThis.__fs_shim_applied = true;

  // 1. Synchronous operations retry helper
  function retrySync(fn, fallbackVal, maxRetries = 15) {
    let retries = maxRetries;
    while (true) {
      try {
        return fn();
      } catch (err) {
        if ((err?.code === 'EIO' || err?.code === 'EBUSY') && retries > 0) {
          retries--;
          const end = Date.now() + 5;
          while (Date.now() < end) {} // sync backoff
        } else if ((err?.code === 'EIO' || err?.code === 'EBUSY') && fallbackVal !== undefined) {
          return fallbackVal;
        } else {
          throw err;
        }
      }
    }
  }

  // 2. Async operations retry helper
  async function retryAsync(fn, fallbackVal, maxRetries = 15) {
    let retries = maxRetries;
    while (true) {
      try {
        return await fn();
      } catch (err) {
        if ((err?.code === 'EIO' || err?.code === 'EBUSY') && retries > 0) {
          retries--;
          await new Promise((r) => setTimeout(r, 20));
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

  // 6. readFileSync patch
  if (fs.readFileSync) {
    const origReadFileSync = fs.readFileSync;
    fs.readFileSync = function (...args) {
      return retrySync(() => origReadFileSync.apply(fs, args));
    };
  }

  // 7. Promises readFile concurrency limiter & retry
  const origReadFile = fs.promises.readFile;
  let active = 0;
  const queue = [];

  function runQueue() {
    while (active < 4 && queue.length > 0) {
      active++;
      const { fn, resolve, reject } = queue.shift();
      fn().then(resolve, reject).finally(() => {
        active--;
        runQueue();
      });
    }
  }

  fs.promises.readFile = function limitedReadFile(...args) {
    return new Promise((resolve, reject) => {
      queue.push({
        fn: () => retryAsync(() => origReadFile.apply(fs.promises, args)),
        resolve,
        reject,
      });
      runQueue();
    });
  };

  // 8. Promises stat / lstat / realpath / open
  if (fs.promises) {
    if (fs.promises.open) {
      const origOpen = fs.promises.open;
      fs.promises.open = function (...args) {
        return retryAsync(() => origOpen.apply(fs.promises, args));
      };
    }

    if (fs.promises.stat) {
      const origStat = fs.promises.stat;
      fs.promises.stat = function (...args) {
        return retryAsync(() => origStat.apply(fs.promises, args));
      };
    }

    if (fs.promises.lstat) {
      const origLstat = fs.promises.lstat;
      fs.promises.lstat = function (...args) {
        return retryAsync(() => origLstat.apply(fs.promises, args));
      };
    }

    if (fs.promises.realpath) {
      const origRealpath = fs.promises.realpath;
      fs.promises.realpath = function (p, options) {
        return retryAsync(
          () => origRealpath.call(fs.promises, p, options),
          typeof p === 'string' ? p : p?.toString()
        );
      };
    }
  }
}
