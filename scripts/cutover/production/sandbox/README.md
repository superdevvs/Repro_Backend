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
limits='-p MemoryMax=2G -p MemorySwapMax=0 -p CPUQuota=100% -p TasksMax=512'
systemd-run --scope --unit=repro-cutover-sandbox $limits -- \
    /bin/sh /opt/repro-cutover-sandbox/run-sandbox.sh /srv/repro-cutover-sandbox -- COMMAND [ARGUMENT...]
systemd-run --scope --unit=repro-cutover-sandbox $limits -- \
    /bin/sh /opt/repro-cutover-sandbox/run-sandbox.sh --reboot /srv/repro-cutover-sandbox -- COMMAND ...
```

On the host, always start the launcher inside that `systemd-run --scope`.
The scope is created by the host's systemd, outside the namespaces (inside,
`systemd-run` is the refusing shim), and every sandbox process inherits it.
See "Resource limits" below for the numbers.

`COMMAND` runs as root inside, with `PATH=/usr/sbin:/usr/bin:/sbin:/bin`,
`HOME=/root`, `REPRO_SANDBOX_ROOT` and `REPRO_SANDBOX_BOOT_ID`, working
directory `/`, and only stdin, stdout and stderr open. When it exits, every
process left in the sandbox gets SIGTERM, then SIGKILL after 10 s (the kernel
kills the PID namespace when its init exits in any case).

`--reboot` simulates a reboot: a new sandbox boot ID and empty sandbox `/run`
directories. Everything else under the root persists between launches (same
boot or not), like `/etc` and `/var/lib` on a real host.

Exit status: the command's status (128 + signal if it was killed); 96 when a
check failed and nothing ran, and also whenever the checker waiting in the
host namespace did not give its go (it writes `ROOT/sync/go` and exits 0), so
a run never counts as successful without the checks; 2 for a bad root, a bad
command line or a launch already using the root. `ROOT/sync` belongs to the
launcher: a command that removes `ROOT/sync/go` makes its run count as
refused.

The root must exist, be root-owned and not writable by group or others (0711
when sandbox services running as `www-data` need the app copy under it), have
root-owned parents that are not group or other writable, lie below a
top-level directory (`/srv/repro-cutover-sandbox`, not `/sandbox`), and lie
outside `/etc`, `/var`, `/opt`, `/home`, `/root`, `/run`, `/tmp` and the
system trees. One launch per root at a time: `flock` on the ROOT directory
itself, opened read-only and held until the last sandbox process is gone.
ROOT is writable inside, so the launcher keeps no lock file there (a
command could replace one with a link that the next launch, as root, would
truncate or create); a `launch.lock` left by an older launcher is unused.
Remove the root with `rm -rf` after the run; no mount outlives the
sandbox's PID 1.

If a launch refuses because the copy-on-write layer "shadows the launcher's
own files", an earlier command wrote into the launcher's directory as seen
inside (for example `/opt/repro-cutover-sandbox`). A likely cause is
`__pycache__`: Python run inside that imports from that directory (as
`make_config.py` imports `sandbox_check`) writes its bytecode cache there.
Run `make_config.py` in the host namespace and any Python inside with `-B`.
Look at `ROOT/cow/upper/opt/repro-cutover-sandbox`, find out what wrote it,
and remove it before the next launch (`rm -r --` that directory; the
refusal names it). The refusal stays: the launcher never runs with its own
files shadowed.

A launch also refuses while `ROOT/cow/upper/etc` holds `ld.so.preload` or
`ld.so.cache`: written inside (a preload, or `ldconfig` run inside), the
dynamic loader would read them into the inside half's own programs
(`mount`, `sha256sum`, PID 1's `python3`) at every later launch. Do not run
`ldconfig` inside; inspect the file and remove it (`rm --`, the refusal
names it).

## What the sandbox replaces

| Inside path | What it is |
| --- | --- |
| `/etc/repro-cutover-fence`, `/var/lib/repro-cutover-fence`, `/var/lib/repro-cutover-archive`, `/var/lib/repro-cutover-keep`, `/run/repro-cutover-fence`, `/run/repro-cutover-ingress`, `/etc/repro-database-cutover`, `/opt/repro-cutover-fence`, `/run/repro-telnyx-capture`, `/etc/repro-telnyx-capture` | Bind of `ROOT/binds/<path>` |
| `/proc/sys/kernel/random/boot_id` | Read-only bind of `ROOT/boot/boot_id` |
| `/usr/bin/systemctl`, `/usr/bin/systemd-run`, `/usr/bin/supervisorctl`, `/usr/sbin/service` | Read-only bind of the shim when the host has the tool; an absent tool stays absent |
| `/run`, `/tmp`, `/dev/shm` | Fresh tmpfs per launch, capped at 64 MiB, 1 GiB and 64 MiB |
| `/etc`, `/var`, `/opt`, `/home`, `/root` | Copy-on-write overlay: host files read through, every write goes to `ROOT/cow/upper/<tree>` |
| `/usr`, `/boot` | The host's trees, bound read-only (they may be file systems of their own) |
| `/` | The host's root file system, read-only: top-level directories, `/mnt`, `/media`, the merged `/bin`, `/sbin` and `/lib` |
| ROOT's top-level directory (`/srv` for `/srv/repro-cutover-sandbox`) | A read-only tmpfs (1 MiB) holding only the path to ROOT, with ROOT itself bound back read-write. Nothing else of the host's `/srv` is visible: not its files, not its unix sockets |
| `/proc` | The sandbox's own (`--mount-proc`): only sandbox processes |
| Network | Own namespace with only `lo` (brought up) |

So inside the sandbox the controller can keep production's fixed paths and
values: `/run/php/php8.3-fpm.sock` and `/run/repro-mysql84-native/mysql.sock`
are the sandbox FPM's and MySQL's, never the host's, because `/run` is
private. A path that a sandbox config gets wrong (the production app tree,
the deploy locks in `/home/maverick`, Studio pause files) is copied on write
and never reaches the host. A drill script that repeats a W1 install step
(`/usr/local/sbin/repro-queue-flag`, `repro-cutover-run`, the cron guard
`repro-storage-guard`) fails with "Read-only file system" instead of changing
the host's files. Sandbox services write only under ROOT, the overlays and
the private tmpfs mounts.

Not isolated: `/dev` other than `/dev/shm`, `/sys` (including cgroups), any
other file system the host mounts below `/` (the read-only remount covers the
root file system, `/usr` and `/boot` only; list them with `findmnt -R /`
before W2a and stop if one is writable and outside the trees above), SysV
IPC, the kernel, CPU and memory (see "Resource limits"), and the host's
machine ID (read only). The sandbox protects the host from the controller's
fixed paths and from configuration mistakes, not from hostile code running as
root inside. `/etc/ld.so.preload` and `/etc/ld.so.cache` written inside
stop the next launch (above), but within one launch a preload written
inside reaches every program started after it.

## Resource limits

The tmpfs caps bound the sandbox's private memory file systems: `/run` 64 MiB
(sockets and pid files), `/tmp` 1 GiB (MySQL's tmpdir and sort files for the
synthetic data and W2c's small export), `/dev/shm` 64 MiB, and ROOT's
top-level tmpfs 1 MiB (only directories). Without `size=` each would default
to half of the host's memory.

On the host, start the launcher inside the transient scope shown under Usage:
`MemoryMax=2G` (tmpfs pages are charged to the scope, so `/tmp` fits),
`MemorySwapMax=0`, `CPUQuota=100%` (one CPU's worth), `TasksMax=512`. These
are starting values; W2a confirms them against P5's free-memory reading and
lowers them if production needs more headroom. If the sandbox exceeds them,
the kernel kills sandbox processes, never production's.

Disk: everything the sandbox writes lands under ROOT on the host's disk (the
app copy, the synthetic SQLite file, the sandbox MySQL datadir and binary
logs, copy-ups into `cow/upper`). Budget 5 GiB for ROOT. Before each launch,
`df -B1 --output=avail` on ROOT's file system must exceed P5's figure for the
night plus the budget; check `du -sx ROOT` after each launch and remove the
root when it nears the budget. A copy-up of a large host file (anything
under `/var` the sandbox opens for writing) counts against this budget.

## Checks before anything runs

1. `prepare` validates the root and lays it out (never touches a host path).
   The root is writable from inside, so every directory of the layout is
   opened component by component without following links; a symbolic link or
   a file where a directory belongs is refused, and owners and modes are set
   through the opened directory, never through a path.
2. `arm` (under the launch lock) checks the layout again, refuses when the
   copy-on-write layer holds anything at the launcher's own directory or a
   loader file in `cow/upper/etc` (see above), sets the boot ID, copies
   `sandbox_check.py` from the launcher's directory (read in the host
   namespace) to `ROOT/sync/sandbox_check.py`,
   writes a canary holding a fresh token into every `ROOT/binds/...`
   directory and records host facts (boot ID, namespaces, devices of the
   private and copy-on-write trees and of ROOT, hashes of the four
   service-manager binaries) and the checker copy's sha256 in
   `ROOT/sync/launch.json`.
3. Inside, `run-sandbox.sh` mounts in this order: private `/run`, `/tmp` and
   `/dev/shm`; ROOT's top-level directory with only ROOT; the five overlays;
   the ten binds; the boot ID; read-only `/usr` and `/boot`; the shims;
   finally `/` read-only. A missing mount point is created only inside a
   mount it just made (checked by mount point and device); otherwise it
   refuses, so it never creates a directory on a host file system. It then
   checks the per-launch checker copy against the sha256 that `arm` printed
   and runs PID 1 from that copy, never from the launcher's directory (which
   the sandbox may see through its `/opt` overlay).
4. PID 1 (`sandbox_check.py init`) checks: it is PID 1 with its own `/proc`;
   the PID, network and mount namespaces differ from the host's; `/etc`,
   `/var`, `/opt`, `/home` and `/root` are overlays with their upper layer in
   the root; `/run`, `/tmp` and `/dev/shm` are tmpfs and not the host's; `/`,
   `/usr` and `/boot` are read-only; ROOT's top-level directory is a read-only
   tmpfs showing only the path to ROOT, and ROOT is the host's ROOT; **each
   fixed path shows the canary written outside** (a missing bind stops the
   run here, before anything inside writes); the boot ID is the sandbox's;
   each shim is the shim and exits 97; the only interface is `lo`. Then it
   writes a second canary at every fixed path and reports ready.
5. The launcher's checker, waiting in the host namespace, requires every
   second canary in its `ROOT/binds/...` directory and none on any host path
   (a leaked canary is removed and the run stops), the host boot ID and
   service-manager binaries unchanged. It removes the canaries, writes
   `ROOT/sync/go` and exits 0, and the sandbox starts.
6. When the sandbox ends, the launcher returns the command's status only if
   `ROOT/sync/go` exists and the checker exited 0; otherwise 96.

Any failure prints `run-sandbox: refused: ...` and exits 96.

## Root layout

| Path | Content |
| --- | --- |
| `binds/<fixed path>/` | The sandbox directories (persist; `binds/run/...` emptied by `--reboot`) |
| `cow/upper/{etc,var,opt,home,root}`, `cow/work/...` | Overlay layers |
| `boot/boot_id` | The sandbox boot ID |
| `shims/refuse` | Copy of the shim, refreshed every launch |
| `sync/` | `launch.json`, `sandbox_check.py` (PID 1's copy, rewritten every launch), `ready`, `go`, `stop` |
| ROOT itself | The launch lock (`flock` on the directory; no lock file) |
| `bin/`, `app/`, `mysql/`, `locks/`, `studio/` | Written by the drill script and `make_config.py` (D18) |

## Sandbox config (`make_config.py`)

```sh
/usr/bin/python3 -I -S -B /opt/repro-cutover-sandbox/make_config.py --root ROOT \
    --controller CONTROLLER \
    --app-root ROOT/app/backend --run-id RUN --mysql-datadir ROOT/mysql/data \
    --smoke-client-id N --smoke-shoot-id N [--deploy-owner maverick] [--mode drill|cutover] \
    [--mysql-client PATH] [--measured measured.json] [--wrappers wrappers.template.json]
```

Run it as root in the host namespace, not through `run-sandbox.sh`, and
keep `-B`: it imports `sandbox_check` from its own directory, and run
inside without `-B` it would leave `__pycache__` in the `/opt` layer, which
stops every later launch. In the host namespace it runs the `adapter.py`,
`cli.py` and `admit.py` of `CONTROLLER` as root and writes through paths
under ROOT, which commands inside can change. So `CONTROLLER` is the
reviewed controller tree read from outside ROOT (the one copied into
`ROOT/binds/opt/repro-cutover-fence`), never the copy under ROOT, and it
runs before the first launch on the root.

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

- D01 (merged into the lane): `make_config.py` calls only
  `load_config(raw, read=...)`, handing it the `identity.json` it writes; a
  controller whose `load_config` takes no `read` is refused (exit 2). Its
  `cli.py` fixed-path checks accept the generated files. `smoke_tables`
  (`users`, `shoots`, `aryeo_changes`, `jobs`) and the switch keys equal
  D01's example host config (`MakeConfigTests` checks both). D01 checks the
  new paths only for being absolute; the values here (`switch_record` ...
  `keep` under the bound directories) are this file's choice and must match
  whatever the code that writes them expects. Add `DB_EXPECTED_ENGINE` and
  `SCHEMA_CACHE_RELEASE` to the switch only once those branches merge (the
  lane's app config reads neither key yet).
- D02: the identity probe command line (`identity_probe.py --config
  /etc/repro-cutover-fence/identity.json`, the shape D01 requires). D04: the
  MySQL probe command line. D08: `fpm_drain.py`'s command line.
- D14: transient timers must go through the `timer` host command (or another
  configurable path); a hard-coded `systemd-run` is refused inside the
  sandbox. `repro-cutover-run` is not usable inside; the drill script calls
  `cli.py` directly.
- D14 and D18, a plan item (review C5, 26 Nov): inside, `systemd-run` exits
  97, so the approval-expiry timer, the import-limit timer and the watchdog
  that W2a must exercise (design 7.4: a watchdog rollback after `prepare` and
  the expiry timer) run only if D14 takes the timer's argument list from
  `host.json` (`commands.timer`, which `make_config.py` already writes as
  `ROOT/bin/timer`, but which `adapter.COMMANDS` does not define yet, so it is
  not emitted) instead of calling `systemd-run` directly, and D18 supplies
  `ROOT/bin/timer` and a watchdog loop inside the sandbox. The build plan's
  D14 tests (`test_arm_deadline_builds_transient_timer`,
  `test_run_wrapper_argv`) assume a direct `systemd-run`; the lead decides.
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
interface, a host listener, the launcher under `/opt/repro-cutover-sandbox`
for one test, and `/usr` and `/boot` as mounts of their own at the real paths
of that disposable container, and compare a snapshot of the host paths
(including `/`, `/usr/local/sbin`, `/usr/lib`, `/root`, `/mnt`, `/boot`,
`/dev/shm` and `/srv`) before and after every test. Never run them on a real
host: they skip outside a container (no `/.dockerenv`) and fail without
`--privileged`. The `make_config.py` tests (`MakeConfigTests`) need Linux only
and also run as an unprivileged user; `run-linux-tests.sh` runs them by
default (filtered with `-k MakeConfigTests`, so the default list needs no
`--privileged`), and the whole file when it is passed as above.
