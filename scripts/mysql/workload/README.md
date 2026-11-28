# Isolated synthetic application smoke

This is a bounded CLI check of the actual Laravel bootstrap, authentication,
notification read-state controller/service, and native MySQL queue handler.
It is **not** a copied-customer-data run, an HTTP/FPM load benchmark, a full
migration test, worker crash recovery, or production readiness approval.
It neither starts a service nor installs software or changes live configuration.

## Before running

- Use Linux with Python 3.10+, PHP 8.3 with the application's required extensions
  and Composer dependencies, plus root-owned `/usr/bin/unshare`. Unprivileged
  user and network namespaces must be enabled for the default launcher. The
  runner refuses to proceed if it cannot create and verify them. A separately
  reviewed supervisor can provide isolation as described below; there is no
  network-enabled fallback.
- Use an isolated copy of this candidate, including its own `vendor/`. The
  source cannot be `/var/www/...` and must not contain `.env`, `.env.production`
  or a cached Laravel configuration. Source is not copied or changed by the
  runner. All generated Laravel caches, logs and storage use a fresh private
  runtime directory outside the checkout. Keep its parent owned by the caller
  with mode 0700. Source and runtime must not resolve through symlinks.
- The independently accepted socket-only MySQL **8.4.13** service must already
  be running and reachable by the unprivileged caller. The runner cannot start
  it or grant socket access. A user namespace retains host access restrictions;
  whether the actual host permits the socket connection must be tested.
- Reserve the **empty** schema `repro_mysql84_acceptance` exclusively for this
  smoke. Never point it at the copied database or PHPUnit's truncating test DB.
  These existing `localhost` accounts must have exactly these schema grants:

| Principal | Exact privileges on the acceptance schema |
| --- | --- |
| `repro_accept_admin` | ALTER, CREATE, DELETE, DROP, INDEX, INSERT, REFERENCES, SELECT, TRIGGER, UPDATE |
| `repro_accept_worker` | DELETE, INSERT, SELECT, UPDATE |
| `repro_accept_reader` | SELECT |

Global `USAGE` is allowed; roles, grant option and other privileges are refused.
With MySQL `partial_revokes=OFF`, underscores in the grant target must be escaped
so the grant is literal, not a schema wildcard. The smoke creates no triggers
and requires no global privileges or `log_bin_trust_function_creators` change.

The administrator provisioning helper may retain protected client files. It
must pass their contents **in memory**, through stdin, to this runner after
dropping to the reviewed unprivileged host user. Do not put passwords in shell
arguments, shell history, environment variables, source `.env`, or shared files.
The runner never asks for sudo or another authentication session.

## Input and invocation

Stdin contains one JSON object, at most 16 KiB, with this exact shape (values
below are explanatory placeholders, not usable credentials):

```json
{
  "version": 1,
  "server_uuid": "11111111-2222-3333-4444-555555555555",
  "socket": "/run/repro-mysql84-native/mysql.sock",
  "database": "repro_mysql84_acceptance",
  "accounts": {
    "admin": {"username": "repro_accept_admin", "password": "PRIVATE_VALUE_PASSED_IN_MEMORY"},
    "worker": {"username": "repro_accept_worker", "password": "PRIVATE_VALUE_PASSED_IN_MEMORY"},
    "reader": {"username": "repro_accept_reader", "password": "PRIVATE_VALUE_PASSED_IN_MEMORY"}
  }
}
```

The private orchestrator supplies stdin. Its argument list is:

```text
python3 -I /isolated/backend/scripts/mysql/workload/run.py
  --app /isolated/backend
  --runtime /private/acceptance-runs/unique-run-name
  --php /usr/bin/php8.3
  --mode check
```

`check` validates namespaces, all three authenticated identities, exact grants,
durability, socket/version/UUID and an empty schema. It does not boot Laravel
or create database tables. Use a **different fresh runtime directory** with
`--mode smoke` for the application exercise.

Before requesting credentials, use `--mode isolation` with the same arguments.
It reads no stdin, opens no database and does not bootstrap Laravel. It proves
the namespace and PHP prerequisites and records their result. A fresh runtime
directory is still required. This is also how to discover host namespace policy
restrictions without requesting an administrator session.

## Explicit supervisor-provided isolation

Some Ubuntu hosts prohibit unprivileged UID mappings. Do not loosen a global
AppArmor policy or turn off the namespace check to run this harness. A reviewed
administrator-owned coordinator can instead create a dedicated transient unit
with `PrivateNetwork=yes`, `User=maverick` (the verified account),
`NoNewPrivileges=yes`, empty `CapabilityBoundingSet` and ambient capabilities,
and a private writable runtime. It should additionally make the live application,
production configuration and unrelated service sockets inaccessible. This
package does not install or start such a unit.

That coordinator records its own network namespace **before** isolation and
passes it as `--supervised-parent-net 'net:[NUMBER]'` to the unprivileged runner.
This mode is explicit; the default launcher never selects it after a failure.
It verifies the current namespace differs, only loopback exists, no IPv4 route
exists and an outbound UDP route lookup fails. It additionally verifies all real,
effective, saved and filesystem UIDs equal the nonroot effective UID;
`NoNewPrivs=1`; and empty permitted, effective, inheritable, ambient and bounding
capability sets. Missing or contradictory evidence refuses the run. The PHP
smoke repeats the network namespace/interface/route checks independently.

An isolated Docker fixture may exercise this same mode only with a supervisor
that independently verifies `--network none`, nonroot UID, dropped capabilities,
`no-new-privileges` and the distinct parent namespace. A caller-invented namespace
number is not accepted operational evidence. The coordinator's reviewed report
must bind the baseline namespace, unit/container identity and harness digest.
No credential or database access is needed for its first `isolation` probe.

`smoke` checks the same preconditions, then creates four tiny tables (`users`,
`notification_read_states`, `workload_jobs`, `workload_effects`) containing only
fixture data. The users schema is deliberately a minimal fixture, not the full
application schema. It bootstraps Laravel with only the restricted worker
connection, a new ephemeral application key, local storage and in-memory mail,
session and cache. Provider service configuration is cleared before providers
boot; server-monitor Unix-socket output is disabled. No scheduler is run.

It verifies uncommitted writes stay invisible to the independent reader and
rollback removes them; valid and invalid synthetic passwords; persisted
notification-ID merging, a monotonic watermark and separation between users;
then native queue reservation, handler execution, acknowledgement and one
committed synthetic effect. There is no network listener or real customer job.
The native queue handler runs in this process; separate worker-process recovery
and retry tests remain distinct acceptance work.

Only tables created by this invocation are dropped in the final cleanup. The
runner never runs `migrate:fresh`, truncates an existing table or resets a schema.
A killed process or failed cleanup can leave synthetic tables; the next run
refuses that nonempty schema. Inspect and remove only the recorded fixture
tables through the dedicated schema administrator before another run.

Success writes a private `result.json` containing booleans, counts, identity,
elapsed time and source/harness hashes. Credentials and exception text are not
logged. A failure prints a redacted refusal; it is not acceptance evidence.
The source hash covers application/configuration/routes/bootstrap files,
Composer manifests and dependency inventory; it is not a complete vendor-binary
attestation. Bind the result to the reviewed full release archive separately.

## What can be verified without the host

```text
python -B -m unittest discover -s scripts/mysql/workload -p test_run.py -v
```

These tests check credential targets, duplicate input, environment isolation,
namespace/route refusal, subprocess timeout and redaction, source configuration
refusal and POSIX runtime ownership. They do not establish real Linux namespace,
socket, PHP, schema-grant or Laravel behavior. Run `check` and then `smoke` on a
disposable accepted Linux instance before describing the harness as runtime
verified. Existing customer snapshots and production credentials are not inputs.
