// Tiny dependency-free round-robin reverse proxy for local development.
//
// Why this exists:
//   `php artisan serve` runs PHP's built-in web server, which is strictly
//   single-threaded. On Windows the usual multi-worker switch
//   (PHP_CLI_SERVER_WORKERS) does nothing because it relies on fork(), which
//   Windows lacks. So every request to the dashboard serializes behind the
//   previous one and the UI feels slow to load.
//
// This proxy listens on the public dev port (default 8000 - the port the SPA
// already calls) and spreads incoming requests across several independent
// `php artisan serve` worker processes running on the backend ports. Each
// worker is a normal, fresh-per-request PHP process - identical behavior to
// today - so nothing about the application runtime changes. The only thing
// that changes is that requests now run in parallel instead of one at a time.
//
// Usage:
//   node scripts/dev-proxy.mjs <listenPort> <workerPort> [workerPort ...]
//   node scripts/dev-proxy.mjs 8000 8001 8002 8003 8004
//
// The proxy itself is fully async/non-blocking (it only pipes bytes), so it is
// not a bottleneck - the heavy PHP work happens in parallel on the workers.

import http from 'node:http';

const args = process.argv.slice(2);
const listenPort = Number.parseInt(args[0], 10) || 8000;
const workerPorts = args.slice(1).map((p) => Number.parseInt(p, 10)).filter(Number.isInteger);

if (workerPorts.length === 0) {
  workerPorts.push(8001);
}

let cursor = 0;
function nextWorkerPort() {
  const port = workerPorts[cursor % workerPorts.length];
  cursor += 1;
  return port;
}

const server = http.createServer((clientReq, clientRes) => {
  const targetPort = nextWorkerPort();

  const proxyReq = http.request(
    {
      host: '127.0.0.1',
      port: targetPort,
      method: clientReq.method,
      path: clientReq.url,
      headers: clientReq.headers,
    },
    (proxyRes) => {
      clientRes.writeHead(proxyRes.statusCode ?? 502, proxyRes.headers);
      proxyRes.pipe(clientRes);
    },
  );

  proxyReq.on('error', (err) => {
    if (!clientRes.headersSent) {
      clientRes.writeHead(502, { 'Content-Type': 'text/plain' });
    }
    clientRes.end(`[dev-proxy] backend worker on port ${targetPort} is unavailable: ${err.message}\n`);
  });

  clientReq.pipe(proxyReq);
});

// Match Node's default keep-alive behavior to the PHP dev server's expectations.
server.keepAliveTimeout = 0;

server.listen(listenPort, '127.0.0.1', () => {
  console.log(
    `[dev-proxy] listening on http://127.0.0.1:${listenPort} -> round-robin across ports [${workerPorts.join(', ')}]`,
  );
});

const shutdown = () => {
  server.close(() => process.exit(0));
};
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
