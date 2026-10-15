// fs-shim.js
import fs from "node:fs";
if (!globalThis.__fs_shim_applied) {
  let retrySync = function(fn, fallbackVal, maxRetries = 15) {
    let retries = maxRetries;
    while (true) {
      try {
        return fn();
      } catch (err) {
        if ((err?.code === "EIO" || err?.code === "EBUSY") && retries > 0) {
          retries--;
          const end = Date.now() + 5;
          while (Date.now() < end) {
          }
        } else if ((err?.code === "EIO" || err?.code === "EBUSY") && fallbackVal !== void 0) {
          return fallbackVal;
        } else {
          throw err;
        }
      }
    }
  }, runQueue = function() {
    while (active < 4 && queue.length > 0) {
      active++;
      const { fn, resolve, reject } = queue.shift();
      fn().then(resolve, reject).finally(() => {
        active--;
        runQueue();
      });
    }
  };
  globalThis.__fs_shim_applied = true;
  async function retryAsync(fn, fallbackVal, maxRetries = 15) {
    let retries = maxRetries;
    while (true) {
      try {
        return await fn();
      } catch (err) {
        if ((err?.code === "EIO" || err?.code === "EBUSY") && retries > 0) {
          retries--;
          await new Promise((r) => setTimeout(r, 20));
        } else if ((err?.code === "EIO" || err?.code === "EBUSY") && fallbackVal !== void 0) {
          return fallbackVal;
        } else {
          throw err;
        }
      }
    }
  }
  if (fs.openSync) {
    const origOpenSync = fs.openSync;
    fs.openSync = function(...args) {
      return retrySync(() => origOpenSync.apply(fs, args));
    };
  }
  if (fs.realpathSync) {
    const origRealpathSync = fs.realpathSync;
    const origRealpathSyncNative = fs.realpathSync.native || origRealpathSync;
    const wrappedNative = function(p, options) {
      return retrySync(() => origRealpathSyncNative.call(fs, p, options), typeof p === "string" ? p : void 0);
    };
    const wrappedSync = function(p, options) {
      return retrySync(() => origRealpathSync.call(fs, p, options), typeof p === "string" ? p : void 0);
    };
    wrappedSync.native = wrappedNative;
    fs.realpathSync = wrappedSync;
  }
  if (fs.statSync) {
    const origStatSync = fs.statSync;
    fs.statSync = function(...args) {
      return retrySync(() => origStatSync.apply(fs, args));
    };
  }
  if (fs.lstatSync) {
    const origLstatSync = fs.lstatSync;
    fs.lstatSync = function(...args) {
      return retrySync(() => origLstatSync.apply(fs, args));
    };
  }
  if (fs.readFileSync) {
    const origReadFileSync = fs.readFileSync;
    fs.readFileSync = function(...args) {
      return retrySync(() => origReadFileSync.apply(fs, args));
    };
  }
  if (fs.readdirSync) {
    const origReaddirSync = fs.readdirSync;
    fs.readdirSync = function(...args) {
      return retrySync(() => origReaddirSync.apply(fs, args));
    };
  }
  const origReadFile = fs.promises.readFile;
  let active = 0;
  const queue = [];
  fs.promises.readFile = function limitedReadFile(...args) {
    return new Promise((resolve, reject) => {
      queue.push({
        fn: () => retryAsync(() => origReadFile.apply(fs.promises, args)),
        resolve,
        reject
      });
      runQueue();
    });
  };
  if (fs.promises) {
    if (fs.promises.open) {
      const origOpen = fs.promises.open;
      fs.promises.open = function(...args) {
        return retryAsync(() => origOpen.apply(fs.promises, args));
      };
    }
    if (fs.promises.stat) {
      const origStat = fs.promises.stat;
      fs.promises.stat = function(...args) {
        return retryAsync(() => origStat.apply(fs.promises, args));
      };
    }
    if (fs.promises.lstat) {
      const origLstat = fs.promises.lstat;
      fs.promises.lstat = function(...args) {
        return retryAsync(() => origLstat.apply(fs.promises, args));
      };
    }
    if (fs.promises.realpath) {
      const origRealpath = fs.promises.realpath;
      fs.promises.realpath = function(p, options) {
        return retryAsync(
          () => origRealpath.call(fs.promises, p, options),
          typeof p === "string" ? p : p?.toString()
        );
      };
    }
  }
}

// vite.config.js
import path from "node:path";
import { fileURLToPath } from "node:url";
import { defineConfig } from "file:///app/node_modules/vite/dist/node/index.js";
import react from "file:///app/node_modules/@vitejs/plugin-react/dist/index.js";
var __vite_injected_original_import_meta_url = "file:///app/vite.config.js";
var __dirname = path.dirname(fileURLToPath(__vite_injected_original_import_meta_url));
var vite_config_default = defineConfig({
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
      "@api": path.resolve(__dirname, "./src/api"),
      "@services": path.resolve(__dirname, "./src/services")
    },
    extensions: [".mjs", ".js", ".ts", ".jsx", ".tsx", ".json"]
  },
  plugins: [
    react(),
    {
      name: "suppress-watcher-eio",
      configureServer(server) {
        server.watcher.on("error", (err) => {
          if (err?.code === "EIO") {
            return;
          }
          console.error("Vite Watcher Error:", err);
        });
      }
    }
  ],
  cacheDir: "/tmp/.vite",
  optimizeDeps: {
    noDiscovery: true,
    include: ["react", "react-dom", "react-dom/client", "axios", "hls.js", "lucide-react"]
  },
  server: {
    host: "0.0.0.0",
    port: 5173,
    strictPort: true,
    allowedHosts: true,
    watch: {
      usePolling: true,
      interval: 1e3,
      ignored: ["**/node_modules/**", "**/dist/**", "**/.git/**", "/tmp/**"]
    }
  }
});
export {
  vite_config_default as default
};
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsiZnMtc2hpbS5qcyIsICJ2aXRlLmNvbmZpZy5qcyJdLAogICJzb3VyY2VzQ29udGVudCI6IFsiY29uc3QgX192aXRlX2luamVjdGVkX29yaWdpbmFsX2Rpcm5hbWUgPSBcIi9hcHBcIjtjb25zdCBfX3ZpdGVfaW5qZWN0ZWRfb3JpZ2luYWxfZmlsZW5hbWUgPSBcIi9hcHAvZnMtc2hpbS5qc1wiO2NvbnN0IF9fdml0ZV9pbmplY3RlZF9vcmlnaW5hbF9pbXBvcnRfbWV0YV91cmwgPSBcImZpbGU6Ly8vYXBwL2ZzLXNoaW0uanNcIjsvLyBDb25jdXJyZW5jeSBhbmQgcmV0cnkgbGltaXRlciBmb3IgRG9ja2VyIG9uIFdpbmRvd3MgTlRGUyBtb3VudHMgdG8gcHJldmVudCBFSU9cclxuaW1wb3J0IGZzIGZyb20gJ25vZGU6ZnMnO1xyXG5cclxuaWYgKCFnbG9iYWxUaGlzLl9fZnNfc2hpbV9hcHBsaWVkKSB7XHJcbiAgZ2xvYmFsVGhpcy5fX2ZzX3NoaW1fYXBwbGllZCA9IHRydWU7XHJcblxyXG4gIC8vIDEuIFN5bmNocm9ub3VzIG9wZXJhdGlvbnMgcmV0cnkgaGVscGVyXHJcbiAgZnVuY3Rpb24gcmV0cnlTeW5jKGZuLCBmYWxsYmFja1ZhbCwgbWF4UmV0cmllcyA9IDE1KSB7XHJcbiAgICBsZXQgcmV0cmllcyA9IG1heFJldHJpZXM7XHJcbiAgICB3aGlsZSAodHJ1ZSkge1xyXG4gICAgICB0cnkge1xyXG4gICAgICAgIHJldHVybiBmbigpO1xyXG4gICAgICB9IGNhdGNoIChlcnIpIHtcclxuICAgICAgICBpZiAoKGVycj8uY29kZSA9PT0gJ0VJTycgfHwgZXJyPy5jb2RlID09PSAnRUJVU1knKSAmJiByZXRyaWVzID4gMCkge1xyXG4gICAgICAgICAgcmV0cmllcy0tO1xyXG4gICAgICAgICAgY29uc3QgZW5kID0gRGF0ZS5ub3coKSArIDU7XHJcbiAgICAgICAgICB3aGlsZSAoRGF0ZS5ub3coKSA8IGVuZCkge30gLy8gc3luYyBiYWNrb2ZmXHJcbiAgICAgICAgfSBlbHNlIGlmICgoZXJyPy5jb2RlID09PSAnRUlPJyB8fCBlcnI/LmNvZGUgPT09ICdFQlVTWScpICYmIGZhbGxiYWNrVmFsICE9PSB1bmRlZmluZWQpIHtcclxuICAgICAgICAgIHJldHVybiBmYWxsYmFja1ZhbDtcclxuICAgICAgICB9IGVsc2Uge1xyXG4gICAgICAgICAgdGhyb3cgZXJyO1xyXG4gICAgICAgIH1cclxuICAgICAgfVxyXG4gICAgfVxyXG4gIH1cclxuXHJcbiAgLy8gMi4gQXN5bmMgb3BlcmF0aW9ucyByZXRyeSBoZWxwZXJcclxuICBhc3luYyBmdW5jdGlvbiByZXRyeUFzeW5jKGZuLCBmYWxsYmFja1ZhbCwgbWF4UmV0cmllcyA9IDE1KSB7XHJcbiAgICBsZXQgcmV0cmllcyA9IG1heFJldHJpZXM7XHJcbiAgICB3aGlsZSAodHJ1ZSkge1xyXG4gICAgICB0cnkge1xyXG4gICAgICAgIHJldHVybiBhd2FpdCBmbigpO1xyXG4gICAgICB9IGNhdGNoIChlcnIpIHtcclxuICAgICAgICBpZiAoKGVycj8uY29kZSA9PT0gJ0VJTycgfHwgZXJyPy5jb2RlID09PSAnRUJVU1knKSAmJiByZXRyaWVzID4gMCkge1xyXG4gICAgICAgICAgcmV0cmllcy0tO1xyXG4gICAgICAgICAgYXdhaXQgbmV3IFByb21pc2UoKHIpID0+IHNldFRpbWVvdXQociwgMjApKTtcclxuICAgICAgICB9IGVsc2UgaWYgKChlcnI/LmNvZGUgPT09ICdFSU8nIHx8IGVycj8uY29kZSA9PT0gJ0VCVVNZJykgJiYgZmFsbGJhY2tWYWwgIT09IHVuZGVmaW5lZCkge1xyXG4gICAgICAgICAgcmV0dXJuIGZhbGxiYWNrVmFsO1xyXG4gICAgICAgIH0gZWxzZSB7XHJcbiAgICAgICAgICB0aHJvdyBlcnI7XHJcbiAgICAgICAgfVxyXG4gICAgICB9XHJcbiAgICB9XHJcbiAgfVxyXG5cclxuICAvLyAzLiBvcGVuU3luYyBwYXRjaFxyXG4gIGlmIChmcy5vcGVuU3luYykge1xyXG4gICAgY29uc3Qgb3JpZ09wZW5TeW5jID0gZnMub3BlblN5bmM7XHJcbiAgICBmcy5vcGVuU3luYyA9IGZ1bmN0aW9uICguLi5hcmdzKSB7XHJcbiAgICAgIHJldHVybiByZXRyeVN5bmMoKCkgPT4gb3JpZ09wZW5TeW5jLmFwcGx5KGZzLCBhcmdzKSk7XHJcbiAgICB9O1xyXG4gIH1cclxuXHJcbiAgLy8gNC4gcmVhbHBhdGhTeW5jICYgcmVhbHBhdGhTeW5jLm5hdGl2ZSBwYXRjaFxyXG4gIGlmIChmcy5yZWFscGF0aFN5bmMpIHtcclxuICAgIGNvbnN0IG9yaWdSZWFscGF0aFN5bmMgPSBmcy5yZWFscGF0aFN5bmM7XHJcbiAgICBjb25zdCBvcmlnUmVhbHBhdGhTeW5jTmF0aXZlID0gZnMucmVhbHBhdGhTeW5jLm5hdGl2ZSB8fCBvcmlnUmVhbHBhdGhTeW5jO1xyXG5cclxuICAgIGNvbnN0IHdyYXBwZWROYXRpdmUgPSBmdW5jdGlvbiAocCwgb3B0aW9ucykge1xyXG4gICAgICByZXR1cm4gcmV0cnlTeW5jKCgpID0+IG9yaWdSZWFscGF0aFN5bmNOYXRpdmUuY2FsbChmcywgcCwgb3B0aW9ucyksIHR5cGVvZiBwID09PSAnc3RyaW5nJyA/IHAgOiB1bmRlZmluZWQpO1xyXG4gICAgfTtcclxuXHJcbiAgICBjb25zdCB3cmFwcGVkU3luYyA9IGZ1bmN0aW9uIChwLCBvcHRpb25zKSB7XHJcbiAgICAgIHJldHVybiByZXRyeVN5bmMoKCkgPT4gb3JpZ1JlYWxwYXRoU3luYy5jYWxsKGZzLCBwLCBvcHRpb25zKSwgdHlwZW9mIHAgPT09ICdzdHJpbmcnID8gcCA6IHVuZGVmaW5lZCk7XHJcbiAgICB9O1xyXG5cclxuICAgIHdyYXBwZWRTeW5jLm5hdGl2ZSA9IHdyYXBwZWROYXRpdmU7XHJcbiAgICBmcy5yZWFscGF0aFN5bmMgPSB3cmFwcGVkU3luYztcclxuICB9XHJcblxyXG4gIC8vIDUuIHN0YXRTeW5jICYgbHN0YXRTeW5jIHBhdGNoXHJcbiAgaWYgKGZzLnN0YXRTeW5jKSB7XHJcbiAgICBjb25zdCBvcmlnU3RhdFN5bmMgPSBmcy5zdGF0U3luYztcclxuICAgIGZzLnN0YXRTeW5jID0gZnVuY3Rpb24gKC4uLmFyZ3MpIHtcclxuICAgICAgcmV0dXJuIHJldHJ5U3luYygoKSA9PiBvcmlnU3RhdFN5bmMuYXBwbHkoZnMsIGFyZ3MpKTtcclxuICAgIH07XHJcbiAgfVxyXG5cclxuICBpZiAoZnMubHN0YXRTeW5jKSB7XHJcbiAgICBjb25zdCBvcmlnTHN0YXRTeW5jID0gZnMubHN0YXRTeW5jO1xyXG4gICAgZnMubHN0YXRTeW5jID0gZnVuY3Rpb24gKC4uLmFyZ3MpIHtcclxuICAgICAgcmV0dXJuIHJldHJ5U3luYygoKSA9PiBvcmlnTHN0YXRTeW5jLmFwcGx5KGZzLCBhcmdzKSk7XHJcbiAgICB9O1xyXG4gIH1cclxuXHJcbiAgLy8gNi4gcmVhZEZpbGVTeW5jICYgcmVhZGRpclN5bmMgcGF0Y2hcclxuICBpZiAoZnMucmVhZEZpbGVTeW5jKSB7XHJcbiAgICBjb25zdCBvcmlnUmVhZEZpbGVTeW5jID0gZnMucmVhZEZpbGVTeW5jO1xyXG4gICAgZnMucmVhZEZpbGVTeW5jID0gZnVuY3Rpb24gKC4uLmFyZ3MpIHtcclxuICAgICAgcmV0dXJuIHJldHJ5U3luYygoKSA9PiBvcmlnUmVhZEZpbGVTeW5jLmFwcGx5KGZzLCBhcmdzKSk7XHJcbiAgICB9O1xyXG4gIH1cclxuXHJcbiAgaWYgKGZzLnJlYWRkaXJTeW5jKSB7XHJcbiAgICBjb25zdCBvcmlnUmVhZGRpclN5bmMgPSBmcy5yZWFkZGlyU3luYztcclxuICAgIGZzLnJlYWRkaXJTeW5jID0gZnVuY3Rpb24gKC4uLmFyZ3MpIHtcclxuICAgICAgcmV0dXJuIHJldHJ5U3luYygoKSA9PiBvcmlnUmVhZGRpclN5bmMuYXBwbHkoZnMsIGFyZ3MpKTtcclxuICAgIH07XHJcbiAgfVxyXG5cclxuICAvLyA3LiBQcm9taXNlcyByZWFkRmlsZSBjb25jdXJyZW5jeSBsaW1pdGVyICYgcmV0cnlcclxuICBjb25zdCBvcmlnUmVhZEZpbGUgPSBmcy5wcm9taXNlcy5yZWFkRmlsZTtcclxuICBsZXQgYWN0aXZlID0gMDtcclxuICBjb25zdCBxdWV1ZSA9IFtdO1xyXG5cclxuICBmdW5jdGlvbiBydW5RdWV1ZSgpIHtcclxuICAgIHdoaWxlIChhY3RpdmUgPCA0ICYmIHF1ZXVlLmxlbmd0aCA+IDApIHtcclxuICAgICAgYWN0aXZlKys7XHJcbiAgICAgIGNvbnN0IHsgZm4sIHJlc29sdmUsIHJlamVjdCB9ID0gcXVldWUuc2hpZnQoKTtcclxuICAgICAgZm4oKS50aGVuKHJlc29sdmUsIHJlamVjdCkuZmluYWxseSgoKSA9PiB7XHJcbiAgICAgICAgYWN0aXZlLS07XHJcbiAgICAgICAgcnVuUXVldWUoKTtcclxuICAgICAgfSk7XHJcbiAgICB9XHJcbiAgfVxyXG5cclxuICBmcy5wcm9taXNlcy5yZWFkRmlsZSA9IGZ1bmN0aW9uIGxpbWl0ZWRSZWFkRmlsZSguLi5hcmdzKSB7XHJcbiAgICByZXR1cm4gbmV3IFByb21pc2UoKHJlc29sdmUsIHJlamVjdCkgPT4ge1xyXG4gICAgICBxdWV1ZS5wdXNoKHtcclxuICAgICAgICBmbjogKCkgPT4gcmV0cnlBc3luYygoKSA9PiBvcmlnUmVhZEZpbGUuYXBwbHkoZnMucHJvbWlzZXMsIGFyZ3MpKSxcclxuICAgICAgICByZXNvbHZlLFxyXG4gICAgICAgIHJlamVjdCxcclxuICAgICAgfSk7XHJcbiAgICAgIHJ1blF1ZXVlKCk7XHJcbiAgICB9KTtcclxuICB9O1xyXG5cclxuICAvLyA4LiBQcm9taXNlcyBzdGF0IC8gbHN0YXQgLyByZWFscGF0aCAvIG9wZW5cclxuICBpZiAoZnMucHJvbWlzZXMpIHtcclxuICAgIGlmIChmcy5wcm9taXNlcy5vcGVuKSB7XHJcbiAgICAgIGNvbnN0IG9yaWdPcGVuID0gZnMucHJvbWlzZXMub3BlbjtcclxuICAgICAgZnMucHJvbWlzZXMub3BlbiA9IGZ1bmN0aW9uICguLi5hcmdzKSB7XHJcbiAgICAgICAgcmV0dXJuIHJldHJ5QXN5bmMoKCkgPT4gb3JpZ09wZW4uYXBwbHkoZnMucHJvbWlzZXMsIGFyZ3MpKTtcclxuICAgICAgfTtcclxuICAgIH1cclxuXHJcbiAgICBpZiAoZnMucHJvbWlzZXMuc3RhdCkge1xyXG4gICAgICBjb25zdCBvcmlnU3RhdCA9IGZzLnByb21pc2VzLnN0YXQ7XHJcbiAgICAgIGZzLnByb21pc2VzLnN0YXQgPSBmdW5jdGlvbiAoLi4uYXJncykge1xyXG4gICAgICAgIHJldHVybiByZXRyeUFzeW5jKCgpID0+IG9yaWdTdGF0LmFwcGx5KGZzLnByb21pc2VzLCBhcmdzKSk7XHJcbiAgICAgIH07XHJcbiAgICB9XHJcblxyXG4gICAgaWYgKGZzLnByb21pc2VzLmxzdGF0KSB7XHJcbiAgICAgIGNvbnN0IG9yaWdMc3RhdCA9IGZzLnByb21pc2VzLmxzdGF0O1xyXG4gICAgICBmcy5wcm9taXNlcy5sc3RhdCA9IGZ1bmN0aW9uICguLi5hcmdzKSB7XHJcbiAgICAgICAgcmV0dXJuIHJldHJ5QXN5bmMoKCkgPT4gb3JpZ0xzdGF0LmFwcGx5KGZzLnByb21pc2VzLCBhcmdzKSk7XHJcbiAgICAgIH07XHJcbiAgICB9XHJcblxyXG4gICAgaWYgKGZzLnByb21pc2VzLnJlYWxwYXRoKSB7XHJcbiAgICAgIGNvbnN0IG9yaWdSZWFscGF0aCA9IGZzLnByb21pc2VzLnJlYWxwYXRoO1xyXG4gICAgICBmcy5wcm9taXNlcy5yZWFscGF0aCA9IGZ1bmN0aW9uIChwLCBvcHRpb25zKSB7XHJcbiAgICAgICAgcmV0dXJuIHJldHJ5QXN5bmMoXHJcbiAgICAgICAgICAoKSA9PiBvcmlnUmVhbHBhdGguY2FsbChmcy5wcm9taXNlcywgcCwgb3B0aW9ucyksXHJcbiAgICAgICAgICB0eXBlb2YgcCA9PT0gJ3N0cmluZycgPyBwIDogcD8udG9TdHJpbmcoKVxyXG4gICAgICAgICk7XHJcbiAgICAgIH07XHJcbiAgICB9XHJcbiAgfVxyXG59XHJcbiIsICJjb25zdCBfX3ZpdGVfaW5qZWN0ZWRfb3JpZ2luYWxfZGlybmFtZSA9IFwiL2FwcFwiO2NvbnN0IF9fdml0ZV9pbmplY3RlZF9vcmlnaW5hbF9maWxlbmFtZSA9IFwiL2FwcC92aXRlLmNvbmZpZy5qc1wiO2NvbnN0IF9fdml0ZV9pbmplY3RlZF9vcmlnaW5hbF9pbXBvcnRfbWV0YV91cmwgPSBcImZpbGU6Ly8vYXBwL3ZpdGUuY29uZmlnLmpzXCI7aW1wb3J0ICcuL2ZzLXNoaW0uanMnO1xyXG5pbXBvcnQgcGF0aCBmcm9tICdub2RlOnBhdGgnO1xyXG5pbXBvcnQgeyBmaWxlVVJMVG9QYXRoIH0gZnJvbSAnbm9kZTp1cmwnO1xyXG5pbXBvcnQgeyBkZWZpbmVDb25maWcgfSBmcm9tICd2aXRlJztcclxuaW1wb3J0IHJlYWN0IGZyb20gJ0B2aXRlanMvcGx1Z2luLXJlYWN0JztcclxuXHJcbmNvbnN0IF9fZGlybmFtZSA9IHBhdGguZGlybmFtZShmaWxlVVJMVG9QYXRoKGltcG9ydC5tZXRhLnVybCkpO1xyXG5cclxuZXhwb3J0IGRlZmF1bHQgZGVmaW5lQ29uZmlnKHtcclxuICByZXNvbHZlOiB7XHJcbiAgICBhbGlhczoge1xyXG4gICAgICAnQCc6IHBhdGgucmVzb2x2ZShfX2Rpcm5hbWUsICcuL3NyYycpLFxyXG4gICAgICAnQGFwaSc6IHBhdGgucmVzb2x2ZShfX2Rpcm5hbWUsICcuL3NyYy9hcGknKSxcclxuICAgICAgJ0BzZXJ2aWNlcyc6IHBhdGgucmVzb2x2ZShfX2Rpcm5hbWUsICcuL3NyYy9zZXJ2aWNlcycpLFxyXG4gICAgfSxcclxuICAgIGV4dGVuc2lvbnM6IFsnLm1qcycsICcuanMnLCAnLnRzJywgJy5qc3gnLCAnLnRzeCcsICcuanNvbiddLFxyXG4gIH0sXHJcbiAgcGx1Z2luczogW1xyXG4gICAgcmVhY3QoKSxcclxuICAgIHtcclxuICAgICAgbmFtZTogJ3N1cHByZXNzLXdhdGNoZXItZWlvJyxcclxuICAgICAgY29uZmlndXJlU2VydmVyKHNlcnZlcikge1xyXG4gICAgICAgIHNlcnZlci53YXRjaGVyLm9uKCdlcnJvcicsIChlcnIpID0+IHtcclxuICAgICAgICAgIGlmIChlcnI/LmNvZGUgPT09ICdFSU8nKSB7XHJcbiAgICAgICAgICAgIHJldHVybjsgLy8gTWFuZWpvIHNpbGVuY2lvc28gZGUgZXJyb3JlcyB0ZW1wb3JhbGVzIGRlIEkvTyBlbiBEb2NrZXIgcGFyYSBXaW5kb3dzXHJcbiAgICAgICAgICB9XHJcbiAgICAgICAgICBjb25zb2xlLmVycm9yKCdWaXRlIFdhdGNoZXIgRXJyb3I6JywgZXJyKTtcclxuICAgICAgICB9KTtcclxuICAgICAgfSxcclxuICAgIH0sXHJcbiAgXSxcclxuICBjYWNoZURpcjogJy90bXAvLnZpdGUnLFxyXG4gIG9wdGltaXplRGVwczoge1xyXG4gICAgbm9EaXNjb3Zlcnk6IHRydWUsXHJcbiAgICBpbmNsdWRlOiBbJ3JlYWN0JywgJ3JlYWN0LWRvbScsICdyZWFjdC1kb20vY2xpZW50JywgJ2F4aW9zJywgJ2hscy5qcycsICdsdWNpZGUtcmVhY3QnXSxcclxuICB9LFxyXG4gIHNlcnZlcjoge1xyXG4gICAgaG9zdDogJzAuMC4wLjAnLFxyXG4gICAgcG9ydDogNTE3MyxcclxuICAgIHN0cmljdFBvcnQ6IHRydWUsXHJcbiAgICBhbGxvd2VkSG9zdHM6IHRydWUsXHJcbiAgICB3YXRjaDoge1xyXG4gICAgICB1c2VQb2xsaW5nOiB0cnVlLFxyXG4gICAgICBpbnRlcnZhbDogMTAwMCxcclxuICAgICAgaWdub3JlZDogWycqKi9ub2RlX21vZHVsZXMvKionLCAnKiovZGlzdC8qKicsICcqKi8uZ2l0LyoqJywgJy90bXAvKionXSxcclxuICAgIH0sXHJcbiAgfSxcclxufSk7XHJcbiJdLAogICJtYXBwaW5ncyI6ICI7QUFDQSxPQUFPLFFBQVE7QUFFZixJQUFJLENBQUMsV0FBVyxtQkFBbUI7QUFJakMsTUFBUyxZQUFULFNBQW1CLElBQUksYUFBYSxhQUFhLElBQUk7QUFDbkQsUUFBSSxVQUFVO0FBQ2QsV0FBTyxNQUFNO0FBQ1gsVUFBSTtBQUNGLGVBQU8sR0FBRztBQUFBLE1BQ1osU0FBUyxLQUFLO0FBQ1osYUFBSyxLQUFLLFNBQVMsU0FB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QVUsR0FBRyxTQUFTO0FBQzNDLGVBQU87QUFBQSxVQUNMLE1BQU0sYUFBYSxLQUFLLEdBQUcsVUFBVSxHQUFHLE9BQU87QUFBQSxVQUMvQyxPQUFPLE1BQU0sV0FBVyxJQUFJLEdBQUcsU0FBUztBQUFBLFFBQzFDO0FBQUEsTUFDRjtBQUFBLElBQ0Y7QUFBQSxFQUNGO0FBQ0Y7OztBQy9KQSxPQUFPLFVBQVU7QUFDakIsU0FBUyxxQkFBcUI7QUFDOUIsU0FBUyxvQkFBb0I7QUFDN0IsT0FBTyxXQUFXO0FBSjhGLElBQU0sMkNBQTJDO0FBTWpLLElBQU0sWUFBWSxLQUFLLFFBQVEsY0FBYyx3Q0FBZSxDQUFDO0FBRTdELElBQU8sc0JBQVEsYUFBYTtBQUFBLEVBQzFCLFNBQVM7QUFBQSxJQUNQLE9BQU87QUFBQSxNQUNMLEtBQUssS0FBSyxRQUFRLFdBQVcsT0FBTztBQUFBLE1BQ3BDLFFBQVEsS0FBSyxRQUFRLFdBQVcsV0FBVztBQUFBLE1BQzNDLGFBQWEsS0FBSyxRQUFRLFdBQVcsZ0JBQWdCO0FBQUEsSUFDdkQ7QUFBQSxJQUNBLFlBQVksQ0FBQyxRQUFRLE9BQU8sT0FBTyxRQUFRLFFBQVEsT0FBTztBQUFBLEVBQzVEO0FBQUEsRUFDQSxTQUFTO0FBQUEsSUFDUCxNQUFNO0FBQUEsSUFDTjtBQUFBLE1BQ0UsTUFBTTtBQUFBLE1BQ04sZ0JBQWdCLFFBQVE7QUFDdEIsZUFBTyxRQUFRLEdBQUcsU0FBUyxDQUFDLFFBQVE7QUFDbEMsY0FBSSxLQUFLLFNBQVMsT0FBTztBQUN2QjtBQUFBLFVBQ0Y7QUFDQSxrQkFBUSxNQUFNLHVCQUF1QixHQUFHO0FBQUEsUUFDMUMsQ0FBQztBQUFBLE1BQ0g7QUFBQSxJQUNGO0FBQUEsRUFDRjtBQUFBLEVBQ0EsVUFBVTtBQUFBLEVBQ1YsY0FBYztBQUFBLElBQ1osYUFBYTtBQUFBLElBQ2IsU0FBUyxDQUFDLFNBQVMsYUFBYSxvQkFBb0IsU0FBUyxVQUFVLGNBQWM7QUFBQSxFQUN2RjtBQUFBLEVBQ0EsUUFBUTtBQUFBLElBQ04sTUFBTTtBQUFBLElBQ04sTUFBTTtBQUFBLElBQ04sWUFBWTtBQUFBLElBQ1osY0FBYztBQUFBLElBQ2QsT0FBTztBQUFBLE1BQ0wsWUFBWTtBQUFBLE1BQ1osVUFBVTtBQUFBLE1BQ1YsU0FBUyxDQUFDLHNCQUFzQixjQUFjLGNBQWMsU0FBUztBQUFBLElBQ3ZFO0FBQUEsRUFDRjtBQUNGLENBQUM7IiwKICAibmFtZXMiOiBbXQp9Cg==
