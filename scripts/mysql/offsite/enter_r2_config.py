#!/usr/bin/env python3
"""Enter one R2 credential and both crypt passphrases without echo, and write private rclone configs.

Roles keep the two keys apart. `upload` is for the server: the bucket-scoped
upload key that must not be able to delete. `restore` is for the separate
recovery machine: the read-only key, never stored on the server. Each --prefix
gets its own config (remotes `repro_encrypted` over `storage:BUCKET/PREFIX`),
because the publisher accepts exactly one crypt/S3 pair per file. The helper
contacts no provider. It prints only file paths and the SHA-256 fingerprint of
the access key ID, so the owner can match it to the key they created.
Moved from the 24 Nov evidence folder (r2-private-config-entry/enter-r2-config.py).
"""

import argparse
from contextlib import contextmanager
import getpass
import hashlib
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
import warnings

ENDPOINT = re.compile(r"https://[0-9a-f]{32}\.r2\.cloudflarestorage\.com")
BUCKET = re.compile(r"[a-z0-9][a-z0-9-]{1,61}[a-z0-9]")
PREFIX = re.compile(r"[a-z0-9][a-z0-9_-]{0,62}")
S3_TOKEN = re.compile(r"[A-Za-z0-9_+./=-]{1,4096}")
OBSCURED = re.compile(rb"[A-Za-z0-9_-]{22,2048}")
SAFE_ENV = {"PATH": "/usr/bin:/bin", "LANG": "C", "LC_ALL": "C", "TZ": "UTC"}
SERVER_CREDENTIALS = Path("/var/lib/repro-backup")
PROMPTS = {
    "upload": {"access": "UPLOAD key ID (server key, no delete): ", "secret": "UPLOAD secret access key: ",
               "token": "UPLOAD session token: "},
    "restore": {"access": "READ-ONLY restore key ID: ", "secret": "READ-ONLY restore secret access key: ",
                "token": "READ-ONLY session token: "},
}


class Refusal(RuntimeError):
    pass


class NonsecretArguments(argparse.ArgumentParser):
    def error(self, _message):
        raise Refusal("Invalid arguments. Use --help for the nonsecret options.")


def validate_destination(endpoint: str, bucket: str, prefix: str) -> str:
    if ENDPOINT.fullmatch(endpoint) is None:
        raise Refusal("Use the reviewed standard HTTPS Cloudflare R2 account endpoint.")
    if BUCKET.fullmatch(bucket) is None:
        raise Refusal("Supply the reviewed private bucket name.")
    if PREFIX.fullmatch(prefix) is None:
        raise Refusal("Supply a reviewed outer prefix such as daily or binlog.")
    return f"storage:{bucket}/{prefix}"


def check_role(role: str, config_dir: Path) -> None:
    """The read-only restore key must never be written into the server's backup state."""
    if role == "restore" and (config_dir == SERVER_CREDENTIALS or SERVER_CREDENTIALS in config_dir.parents):
        raise Refusal("The restore key belongs on the recovery machine, not in the server's backup state.")


def render_configs(endpoint: str, bucket: str, prefixes: list, access: str, secret: str, token,
                   password: str, salt: str) -> dict:
    configs = {}
    for prefix in prefixes:
        storage = ["[storage]", "type=s3", "provider=Cloudflare", "env_auth=false", "region=auto",
                   "no_check_bucket=true", f"endpoint={endpoint}", f"access_key_id={access}",
                   f"secret_access_key={secret}"]
        if token is not None:
            storage.append(f"session_token={token}")
        crypt = ["[repro_encrypted]", "type=crypt", f"remote={validate_destination(endpoint, bucket, prefix)}",
                 "filename_encryption=standard", "directory_name_encryption=true",
                 f"password={password}", f"password2={salt}"]
        configs[f"rclone-{prefix}.conf"] = "\n".join(crypt + [""] + storage + [""]).encode("utf-8")
    return configs


def protected_rclone(executable: Path) -> None:
    if not executable.is_absolute() or ".." in executable.parts:
        raise Refusal("Supply the installed rclone executable's absolute path.")
    for part in reversed((executable, *executable.parents)):
        info = part.lstat()
        expected = stat.S_ISREG if part == executable else stat.S_ISDIR
        if not expected(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
            raise Refusal("The rclone executable and its parents must be root-owned protected paths.")


@contextmanager
def protected_parent(path: Path):
    if not path.is_absolute() or ".." in path.parts:
        raise Refusal("Use an absolute directory on the private Linux filesystem.")
    options = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    owner = os.geteuid()
    directory = os.open("/", options)
    try:
        for name in ("", *path.parts[1:]):
            if name:
                child = os.open(name, options, dir_fd=directory)
                os.close(directory)
                directory = child
            info = os.fstat(directory)
            if not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, owner) or info.st_mode & 0o022:
                raise Refusal("The config directory's ancestors must be protected root or user directories.")
        if os.fstat(directory).st_uid != owner:
            raise Refusal("Use an existing protected parent owned by the account running the helper.")
        yield directory
    finally:
        os.close(directory)


@contextmanager
def terminal():
    with open("/dev/tty", "w", encoding="utf-8", buffering=1) as tty:
        if not tty.isatty():
            raise Refusal("An interactive Linux terminal is required for private input.")
        yield tty


def read_secret(prompt: str, tty, *, crypt: bool = False) -> str:
    with warnings.catch_warnings():
        warnings.simplefilter("error", getpass.GetPassWarning)
        value = getpass.getpass(prompt, stream=tty)
    if crypt:
        if not value or not value.isprintable() or value != value.strip() or len(value.encode("utf-8")) > 1024:
            raise Refusal("Each crypt passphrase must be a nonempty printable line without edge whitespace.")
    elif S3_TOKEN.fullmatch(value) is None:
        raise Refusal("The R2 credential's format is invalid.")
    return value


def obscure(executable: Path, value: str) -> str:
    process = subprocess.run(
        [os.fspath(executable), "obscure", "-", "--config", "/dev/null"],
        input=value.encode("utf-8") + b"\n", stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        env=SAFE_ENV, timeout=15, check=False,
    )
    output = process.stdout.rstrip(b"\r\n")
    if process.returncode != 0 or OBSCURED.fullmatch(output) is None:
        raise Refusal("Local rclone obscuring failed.")
    return output.decode("ascii")


def create(options) -> tuple[list, str]:
    path = options.config_dir
    prompts = PROMPTS[options.role]
    with protected_parent(path.parent) as parent:
        previous_umask = os.umask(0o077)
        try:
            os.mkdir(path.name, 0o700, dir_fd=parent)
        finally:
            os.umask(previous_umask)
        folder, written = None, []
        try:
            folder = os.open(path.name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
            info = os.fstat(folder)
            if info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
                raise Refusal("The new config directory could not be verified.")
            with terminal() as tty:
                access = read_secret(prompts["access"], tty)
                secret = read_secret(prompts["secret"], tty)
                token = read_secret(prompts["token"], tty) if options.with_session_token else None
                password = obscure(options.rclone, read_secret("Backup passphrase 1: ", tty, crypt=True))
                salt = obscure(options.rclone, read_secret("Backup passphrase 2: ", tty, crypt=True))
            configs = render_configs(options.endpoint, options.bucket, options.prefix, access, secret, token,
                                     password, salt)
            fingerprint = hashlib.sha256(access.encode("ascii")).hexdigest()
            del access, secret, token, password, salt
            for name, raw in configs.items():
                fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600,
                             dir_fd=folder)
                written.append(name)
                with os.fdopen(fd, "wb") as stream:
                    os.fchmod(stream.fileno(), 0o600)
                    stream.write(raw)
                    stream.flush()
                    os.fsync(stream.fileno())
            os.fsync(folder)
            os.fsync(parent)
        except BaseException:
            for name in written:
                os.unlink(name, dir_fd=folder)
            os.rmdir(path.name, dir_fd=parent)
            raise
        finally:
            if folder is not None:
                os.close(folder)
    return [path / name for name in sorted(configs)], fingerprint


def stop(_number, _frame):
    raise KeyboardInterrupt


def main(argv=None) -> int:
    parser = NonsecretArguments(description=__doc__)
    parser.add_argument("--role", required=True, choices=("upload", "restore"))
    parser.add_argument("--endpoint", required=True, help="reviewed standard HTTPS R2 account endpoint")
    parser.add_argument("--bucket", required=True, help="reviewed private bucket name")
    parser.add_argument("--prefix", required=True, action="append", help="outer prefix; repeat for each")
    parser.add_argument("--config-dir", required=True, type=Path, help="new directory under a protected path")
    parser.add_argument("--rclone", required=True, type=Path, help="absolute path of the installed rclone")
    parser.add_argument("--with-session-token", action="store_true", help="also prompt for a session token")
    parser.add_argument("--skip-rclone-check", action="store_true", help=argparse.SUPPRESS)
    try:
        options = parser.parse_args(argv)
        if sys.platform != "linux":
            raise Refusal("Run this helper in an interactive Linux terminal.")
        if len(set(options.prefix)) != len(options.prefix):
            raise Refusal("Name each prefix once.")
        for prefix in options.prefix:
            validate_destination(options.endpoint, options.bucket, prefix)
        check_role(options.role, options.config_dir)
        if not options.skip_rclone_check:
            protected_rclone(options.rclone)
        files, fingerprint = create(options)
        for item in files:
            print(f"Created protected {options.role} configuration at {item}")
        print(f"Access key ID fingerprint (SHA-256): {fingerprint}")
        return 0
    except Refusal as error:
        print(str(error), file=sys.stderr)
    except (KeyboardInterrupt, EOFError, getpass.GetPassWarning):
        print("Private credential entry was interrupted or lacked a secure interactive terminal.", file=sys.stderr)
    except (OSError, UnicodeError, subprocess.SubprocessError):
        print("Local credential setup failed. No credential details were printed.", file=sys.stderr)
    return 1


if __name__ == "__main__":
    for number in (signal.SIGHUP, signal.SIGTERM) if sys.platform == "linux" else (signal.SIGTERM,):
        signal.signal(number, stop)
    raise SystemExit(main())
