# Namespace sandbox for on-host rehearsals (design 7.3)

Built and tested locally only, in a privileged container. Running it on the
production host (W2a on Wed 2 Dec, W2c about Wed 9 Dec) is a production
action that needs the owner's approval each time.

The controller's fixed paths (`cli.py`, the guard's
`/opt/repro-cutover-fence/guard.py` and `/var/lib/repro-cutover-fence`,
`admit.py`'s `wrappers.json`, the nginx open marker, the Deploy-App marker)
and its `/proc` scans mean a "sandbox with private paths" would act on
production (critic HO-H2). The sandbox is therefore a set of Linux namespaces
in which every one of those paths is a directory under the sandbox root.

| File | Purpose |
| --- | --- |
| `run-sandbox.sh` | Launcher (root): `unshare --mount --pid --net --fork --kill-child --mount-proc --propagation private`, the mounts below, then the checks |
| `sandbox_check.py` | Canary and isolation checks from inside and from the host; PID 1 inside |
| `shims/refuse` | Bound read-only over `systemctl`, `systemd-run`, `supervisorctl`, `service`; exits 97 |
| `make_config.py` | Writes the sandbox `host.json`, `identity.json`, `policy.json` and wrapper files |
| `test_sandbox.py` | Tests (launcher tests need a privileged container) |

## Usage

```sh
install -d -o root -g root -m 0755 /opt/repro-cutover-sandbox /opt/repro-cutover-sandbox/shims
install -o root -g root -m 0644 sandbox_check.py make_config.py run-sandbox.sh /opt/repro-cutover-sandbox/
install -o root -g root -m 0755 shims/refuse /opt/repro-cutover-sandbox/shims/
install -d -o root -g root -m 0711 /srv/repro-cutover-sandbox
sh /opt/repro-cutover-sandbox/run-sandbox.sh /srv/repro-cutover-sandbox -- COMMAND [ARGUMENT...]
sh /opt/repro-cutover-sandbox/run-sandbox.sh --reboot /srv/repro-cutover-sandbox -- COMMAND ...
```

`COMMAND` runs as root inside, with `PATH=/usr/sbin:/usr/bin:/sbin:/bin`,
`HOME=/root`, `REPRO_SANDBOX_ROOT` and `REPRO_SANDBOX_BOOT_ID`, working
directory `/`, and only stdin, stdout and stderr open. When it exits, every
process left in the sandbox gets SIGTERM, then SIGKILL after 10 s (the kernel
kills the PID namespace when its init exits in any case).

`--reboot` simulates a reboot: a new sandbox boot ID and empty sandbox `/run`
directories. Everything else under the root persists between launches (same
boot or not), like `/etc` and `/var/lib` on a real host.

Exit status: the command's status (128 + signal if it was killed); 96 when a
check failed and nothing ran; 2 for a bad root, a bad command line or a
launch already using the root.

The root must exist, be root-owned and not writable by group or others (0711
when sandbox services running as `www-data` need the app copy under it), have
root-owned parents that are not group or other writable, and lie outside
`/etc`, `/var`, `/opt`, `/home`, `/run`, `/tmp` and the system trees. One
launch per root at a time (`flock` on `ROOT/launch.lock`, held until the last
sandbox process is gone). Remove the root with `rm -rf` after the run; no
mount outlives the sandbox's PID 1.

## What the sandbox replaces

| Inside path | What it is |
| --- | --- |
| `/etc/repro-cutover-fence`, `/var/lib/repro-cutover-fence`, `/var/lib/repro-cutover-archive`, `/var/lib/repro-cutover-keep`, `/run/repro-cutover-fence`, `/run/repro-cutover-ingress`, `/etc/repro-database-cutover`, `/opt/repro-cutover-fence`, `/run/repro-telnyx-capture`, `/etc/repro-telnyx-capture` | Bind of `ROOT/binds/<path>` |
| `/proc/sys/kernel/random/boot_id` | Read-only bind of `ROOT/boot/boot_id` |
| `/usr/bin/systemctl`, `/usr/bin/systemd-run`, `/usr/bin/supervisorctl`, `/usr/sbin/service` | Read-only bind of the shim when the host has the tool; an absent tool stays absent |
| `/run`, `/tmp` | Fresh tmpfs per launch |
| `/etc`, `/var`, `/opt`, `/home` | Copy-on-write overlay: host files read through, every write goes to `ROOT/cow/upper/<tree>` |
| `/proc` | The sandbox's own (`--mount-proc`): only sandbox processes |
| Network | Own namespace with only `lo` (brought up) |

So inside the sandbox the controller can keep production's fixed paths and
values: `/run/php/php8.3-fpm.sock` and `/run/repro-mysql84-native/mysql.sock`
are the sandbox FPM's and MySQL's, never the host's, because `/run` is
private. A path that a sandbox config gets wrong (the production app tree,
the deploy locks in `/home/maverick`, Studio pause files) is copied on write
and never reaches the host.

Not isolated: `/usr` (except the shims), `/srv`, `/root`, `/mnt`, `/boot`,
`/dev` and `/dev/shm`, SysV IPC, the kernel, CPU and memory, and the host's
machine ID (read only). The sandbox protects the host from the controller's
fixed paths and from configuration mistakes, not from hostile code running as
root inside.

## Checks before anything runs

1. `prepare` validates the root and lays it out (never touches a host path).
2. `arm` (under the launch lock) sets the boot ID, writes a canary holding a
   fresh token into every `ROOT/binds/...` directory and records host facts
   (boot ID, namespaces, devices of `/run` and `/tmp`, hashes of the four
   service-manager binaries) in `ROOT/sync/launch.json`.
3. Inside, `run-sandbox.sh` mounts in this order: private `/run` and `/tmp`,
   the four overlays, the ten binds, the boot ID, the shims. A missing mount
   point is created only inside a mount it just made (checked by mount point
   and device); otherwise it refuses, so it never creates a directory on a
   host file system.
4. PID 1 (`sandbox_check.py init`) checks: it is PID 1 with its own `/proc`;
   the PID, network and mount namespaces differ from the host's; `/etc`,
   `/var`, `/opt` and `/home` are overlays with their upper layer in the root;
   `/run` and `/tmp` are tmpfs and not the host's; **each fixed path shows the
   canary written outside** (a missing bind stops the run here, before
   anything inside writes); the boot ID is the sandbox's; each shim is the
   shim and exits 97; the only interface is `lo`. Then it writes a second
   canary at every fixed path and reports ready.
5. The launcher's checker, waiting in the host namespace, requires every
   second canary in its `ROOT/binds/...` directory and none on any host path
   (a leaked canary is removed and the run stops), the host boot ID and
   service-manager binaries unchanged. It removes the canaries and lets the
   sandbox start.

Any failure prints `run-sandbox: refused: ...; nothing ran` and exits 96.

## Root layout

| Path | Content |
| --- | --- |
| `binds/<fixed path>/` | The sandbox directories (persist; `binds/run/...` emptied by `--reboot`) |
| `cow/upper/{etc,var,opt,home}`, `cow/work/...` | Overlay layers |
| `boot/boot_id` | The sandbox boot ID |
| `shims/refuse` | Copy of the shim, refreshed every launch |
| `sync/` | `launch.json`, `ready`, `go`, `stop` |
| `launch.lock` | The launch lock |
| `bin/`, `app/`, `mysql/`, `locks/`, `studio/` | Written by the drill script and `make_config.py` (D18) |

## Sandbox config (`make_config.py`)

```sh
/usr/bin/python3 -I -S make_config.py --root ROOT --controller ROOT/binds/opt/repro-cutover-fence \
    --app-root ROOT/app/backend --run-id RUN --mysql-datadir ROOT/mysql/data \
    --smoke-client-id N --smoke-shoot-id N [--deploy-owner maverick] [--mode drill|cutover] \
    [--mysql-client PATH] [--measured measured.json] [--wrappers wrappers.template.json]
```

It writes into `ROOT/binds/etc/repro-cutover-fence/` only, which inside is
`/etc/repro-cutover-fence`. Fixed paths keep their production values; the app
copy, its SQLite file, the MySQL datadir, deploy locks and Studio pause files
must lie inside the root; host commands call the sandbox probes under
`/opt/repro-cutover-fence/production/`, the sandbox app copy (queue commands
as `www-data` with `umask 0022`, design 5.1) or sandbox service scripts in
`ROOT/bin/` (`stop-workers`, `start-workers`, `fpm-start`, `fpm-reload`,
`fpm-stop`, `timer`), never a service manager. The contract names (`PATHS`,
`COMMANDS`, `OPTIONS`) are read from the controller copy; a name this file
has no value for is refused, and the result must pass that controller's
`load_config` and `cli.py` fixed-path checks. `identity.json`'s
`env_denylist` is the night flags plus the switch keys. `--measured` (the
sandbox identity probe's six fields plus both live-config hashes) gives
`policy.json`; its `target_uuid` must be the sandbox MySQL's `server-uuid`.
`--wrappers` gives `wrappers.sqlite.json`, `wrappers.mysql.json` and
`wrappers.json` (the SQLite copy); a profile that names `/var/www/` or
`/home/` is refused.

Alignment with the contract slices:

- D01 (`claude/adp-d01-20261126` at `47affbcd`, not yet merged): its
  `load_config(raw, read=...)` and the `cli.py` fixed-path checks accept the
  generated `host.json` and `identity.json` (checked in a container against
  that tree). D01 checks the new paths only for being absolute; the values
  here (`switch_record` ... `keep` under the bound directories) are this
  file's choice and must match whatever the code that writes them expects.
  After D01 merges, drop the `load_config` call without `read=` and add
  `DB_EXPECTED_ENGINE` and `SCHEMA_CACHE_RELEASE` to the switch once those
  branches merge.
- D02: the identity probe command line (`identity_probe.py --config
  /etc/repro-cutover-fence/identity.json`, the shape D01 requires). D04: the
  MySQL probe command line. D08: `fpm_drain.py`'s command line.
- D14: transient timers must go through the `timer` host command (or another
  configurable path); a hard-coded `systemd-run` is refused inside the
  sandbox. `repro-cutover-run` is not usable inside; the drill script calls
  `cli.py` directly.
- C11 (W2c): the cutover mode refuses `offsite_waiver`, and the sandbox has
  no network for real uploads; W2c needs an off-site stand-in.

## Tests

```sh
docker run --rm --pull never --privileged --network none --cpus 2 --memory 2g \
  --tmpfs /tmp:rw,exec,size=512m,mode=1777 \
  --mount type=bind,source=<checkout>,target=/src,readonly \
  --entrypoint /bin/sh repro-mysql-rehearsal-qa:20261122 \
  /src/scripts/cutover/run-linux-tests.sh production/sandbox/test_sandbox.py
```

The launcher tests install fake production paths, fake service-manager
binaries that log every call, a fake production worker, a dummy network
interface and a host listener at the real paths of that disposable container,
and compare a snapshot of the host paths before and after every test. Never
run them on a real host: they skip outside a container (no `/.dockerenv`)
and fail without `--privileged`. The `make_config.py` tests need Linux only and also run as an
unprivileged user.
