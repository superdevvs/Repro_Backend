#!/bin/sh
# Namespace sandbox for on-host rehearsals of the cutover controller (design
# 7.3; W2a and W2c). Run as root on the host:
#
#   run-sandbox.sh [--reboot] ROOT -- COMMAND [ARGUMENT...]
#
# COMMAND runs as root in new mount, PID and network namespaces where every
# fixed path of the controller, the guard, admit.py, nginx and Deploy-App is a
# directory under ROOT, /run, /tmp and /dev/shm are private (size-capped),
# /etc, /var, /opt, /home and /root are copy-on-write views, /usr, /boot and
# the rest of / are read-only, ROOT's top-level directory shows only the path
# to ROOT, the boot ID is the sandbox's, systemctl, systemd-run, supervisorctl
# and service refuse (exit 97), and the only network interface is loopback.
# Nothing runs until sandbox_check.py has checked all of that from inside and
# from the host (canaries); any failure stops the run with exit 96 and nothing
# ran. PID 1 inside runs the copy of sandbox_check.py written into ROOT/sync
# for this launch, checked against its sha256. A run counts only when the
# checker waiting in the host namespace gave its go; otherwise the exit status
# is 96 whatever happened inside. When COMMAND exits, every process left in
# the sandbox is stopped. --reboot simulates a reboot: a new boot ID and empty
# sandbox /run directories.
#
# ROOT must exist, be root-owned and not writable by group or others (0711
# when sandbox services running as www-data need the app copy under it), lie
# below a top-level directory and outside /etc, /var, /opt, /home, /root,
# /run, /tmp and the system trees, for example /srv/repro-cutover-sandbox. On
# the host, start the launcher in a resource-limited scope (README.md, which
# also describes the root's layout).
set -eu
umask 022
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
here=$(cd "$(dirname "$0")" && pwd -P)
python=/usr/bin/python3
check=$here/sandbox_check.py
REFUSED=96

refuse() {
    echo "run-sandbox: refused: $*; nothing ran" >&2
    exit "$REFUSED"
}

# Inside: PID 1 of the new namespaces. Each helper refuses rather than fall
# back to a host path.
sandbox_mounts=

remember() {  # MOUNT_POINT DEVICE_BEFORE: record a mount this launcher made
    after=$(stat -c %d "$1")
    [ "$after" != "$2" ] || refuse "the mount over $1 did not take effect"
    sandbox_mounts="$sandbox_mounts $1:$after"
}

private_tmpfs() {  # DIRECTORY MODE SIZE: a size cap, so the sandbox cannot fill the host's memory
    before=$(stat -c %d "$1")
    mount -n -t tmpfs -o "mode=$2,size=$3,nosuid,nodev" tmpfs "$1" || refuse "cannot mount a private $1"
    remember "$1" "$before"
}

only_root_beside() {  # ROOT's top-level directory shows only the path to ROOT, read-only
    rest=${root#/}
    top=/${rest%%/*}
    identity=$(stat -c %d:%i "$root")
    stash=/run/.repro-sandbox-root
    { mkdir -m 0700 "$stash" && mount -n --bind "$root" "$stash"; } || refuse "cannot hold $root aside"
    private_tmpfs "$top" "$(stat -c %a "$top")" 1m
    { mkdir -p "$root" && mount -n --move "$stash" "$root" && rmdir "$stash"; } || refuse "cannot place $root"
    [ "$(stat -c %d:%i "$root")" = "$identity" ] || refuse "$root inside is not the sandbox root"
    mount -n -o remount,bind,ro "$top" || refuse "cannot make $top read-only"
}

read_only() {  # DIRECTORY: the host's own tree, read-only inside; an absent tree stays absent
    if [ -d "$1" ] && [ ! -L "$1" ]; then
        bind_read_only "$1" "$1"
    fi
}

copy_on_write() {  # DIRECTORY
    before=$(stat -c %d "$1")
    mount -n -t overlay overlay -o "lowerdir=$1,upperdir=$root/cow/upper$1,workdir=$root/cow/work$1" "$1" \
        || refuse "cannot mount a copy-on-write view of $1"
    remember "$1" "$before"
}

make_mount_point() {  # TARGET: create it only inside a mount made above, never on a host file system
    if [ -d "$1" ] && [ ! -L "$1" ]; then
        return 0
    fi
    if [ -e "$1" ] || [ -L "$1" ]; then
        refuse "$1 exists but is not a directory"
    fi
    parent=$(dirname "$1")
    [ -d "$parent" ] || refuse "the parent of $1 is missing"
    case " $sandbox_mounts " in
        *" $(stat -c %m "$parent"):$(stat -c %d "$parent") "*) mkdir -m 0755 "$1" || refuse "cannot create $1" ;;
        *) refuse "refusing to create $1 on a host file system" ;;
    esac
}

bind_directory() {  # TARGET: the sandbox directory ROOT/binds/TARGET over TARGET
    make_mount_point "$1"
    mount -n --bind "$root/binds$1" "$1" || refuse "cannot bind the sandbox directory over $1"
}

bind_read_only() {  # SOURCE TARGET
    { mount -n --bind "$1" "$2" && mount -n -o remount,bind,ro "$2"; } || refuse "cannot bind $1 over $2"
}

shim() {  # PATH: the refusing shim over a host service manager; an absent tool stays absent
    if [ -e "$1" ]; then
        bind_read_only "$root/shims/refuse" "$1"
    fi
}

inside() {  # ROOT TOKEN CHECKER_SHA256 -- COMMAND...
    root=$1
    token=$2
    checker_sha=$3
    shift 3
    [ "${1-}" = "--" ] || refuse "internal: missing command separator"
    shift
    [ "$$" -eq 1 ] || refuse "the sandbox shell is not PID 1 of a new PID namespace"
    # Sizes (README): sockets and pid files; MySQL's tmpdir and sort files; POSIX shared memory.
    private_tmpfs /run 0755 64m
    private_tmpfs /tmp 1777 1g
    private_tmpfs /dev/shm 1777 64m
    only_root_beside
    copy_on_write /etc
    copy_on_write /var
    copy_on_write /opt
    copy_on_write /home
    copy_on_write /root
    bind_directory /etc/repro-cutover-fence
    bind_directory /var/lib/repro-cutover-fence
    bind_directory /var/lib/repro-cutover-archive
    bind_directory /var/lib/repro-cutover-keep
    bind_directory /run/repro-cutover-fence
    bind_directory /run/repro-cutover-ingress
    bind_directory /etc/repro-database-cutover
    bind_directory /opt/repro-cutover-fence
    bind_directory /run/repro-telnyx-capture
    bind_directory /etc/repro-telnyx-capture
    bind_read_only "$root/boot/boot_id" /proc/sys/kernel/random/boot_id
    # Before the shims: a later bind over /usr would hide them.
    read_only /usr
    read_only /boot
    shim /usr/bin/systemctl
    shim /usr/bin/systemd-run
    shim /usr/bin/supervisorctl
    shim /usr/sbin/service
    # Last: the rest of the root file system (/mnt, /srv, /media, top-level directories). Every
    # mount above is its own and keeps its own flags.
    mount -n -o remount,bind,ro / || refuse "cannot make / read-only"
    # PID 1 runs the copy arm wrote into ROOT/sync for this launch, never a file seen through an
    # overlay (the launcher's own directory may lie under /opt), and only with arm's sha256.
    case $checker_sha in
        *[!0-9a-f]*) refuse "internal: bad checker digest" ;;
    esac
    [ "${#checker_sha}" -eq 64 ] || refuse "internal: bad checker digest"
    [ -f "$root/sync/sandbox_check.py" ] && [ ! -L "$root/sync/sandbox_check.py" ] \
        || refuse "the per-launch checker copy is missing"
    digest=$(sha256sum < "$root/sync/sandbox_check.py") || refuse "cannot hash the per-launch checker copy"
    [ "${digest%% *}" = "$checker_sha" ] || refuse "the per-launch checker copy differs from the launch record"
    exec "$python" -I -S "$root/sync/sandbox_check.py" init --root "$root" --token "$token" -- "$@"
}

if [ "${1-}" = "--inside" ]; then
    shift
    inside "$@"
fi

# Outside: the host namespace.
usage() {
    echo "usage: run-sandbox.sh [--reboot] ROOT -- COMMAND [ARGUMENT...]" >&2
    exit 2
}
[ "$(id -u)" -eq 0 ] && [ "$(uname -s)" = Linux ] || { echo "run-sandbox: run as root on Linux" >&2; exit 2; }
reboot=
if [ "${1-}" = "--reboot" ]; then
    reboot=--reboot
    shift
fi
[ $# -ge 3 ] && [ "$2" = "--" ] || usage
root=$1
shift 2

"$python" -I -S "$check" prepare --root "$root" || exit $?
# One launch per sandbox root at a time: the lock is on the ROOT directory itself, opened read-only.
# ROOT is writable inside, so a lock file in it could be swapped for a link that this shell, as root
# in the host namespace, would then create or truncate. prepare checked ROOT and its root-owned
# parents, which nothing inside can reach. unshare and the sandbox init inherit the lock and keep it
# until the last sandbox process is gone, even if this shell dies first; the init closes it for the
# command.
exec 9<"$root"
flock -n 9 || { echo "run-sandbox: another launch is using $root" >&2; exit 2; }
armed=$("$python" -I -S "$check" arm --root "$root" $reboot 9>&-) || exit $?
token=${armed%% *}
checker_sha=${armed#* }

# The outside half of the canary check waits here, in the host namespace.
"$python" -I -S "$check" outside --root "$root" --token "$token" 9>&- &
checker=$!
status=0
unshare --mount --pid --net --fork --kill-child --mount-proc --propagation private \
    -- /bin/sh "$here/run-sandbox.sh" --inside "$root" "$token" "$checker_sha" -- "$@" || status=$?
# A run counts only with the outside checker's verdict: it wrote ROOT/sync/go and exited 0. Its
# exit status comes from the host namespace, where nothing inside can forge it.
if [ -f "$root/sync/go" ]; then
    verdict=0
    wait "$checker" || verdict=$?
else
    # The sandbox stopped before the checker decided: the checker may still be waiting.
    kill "$checker" 2>/dev/null || true
    wait "$checker" 2>/dev/null || true
    verdict=$REFUSED
fi
if [ "$verdict" -ne 0 ]; then
    [ "$status" -eq "$REFUSED" ] \
        || echo "run-sandbox: refused: no go from the outside check; this run does not count (command status $status)" >&2
    exit "$REFUSED"
fi
exit "$status"
