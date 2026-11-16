# Tour analysis (Deploy 1) runbook

Deploy 1 adds a tour analysis layer to the private lab. Each tour photo gets one paid survey (`gpt-6-astra`). The survey becomes editable defaults and selections, and golden hour and twilight runs are built from the existing lab workflows. D1 ships switched off.

Every step on the server needs the owner's approval first: flag flips, the installer, the paid test and the rollback. Run all commands as `maverick` in `/var/www/backend`. `~/.local/share/repro-studio` is the user-owned Supervisor runtime.

## 1. Flags

| `.env` key | Default | Effect |
|---|---|---|
| `IGUIDE_LAB_TOUR_ANALYSIS_ENABLED` | `false` | Master switch. When off, `GET /api/iguide-lab/tour-analyses` returns `capabilities.enabled:false`, every other tour route returns 404, and tour jobs do nothing. |
| `IGUIDE_LAB_TOUR_RUNS_PAUSED` | `false` | When on, `POST .../runs` is refused with 409. Selections still save, and running runs start no new chunk. |
| `IGUIDE_LAB_TOUR_GRASS_RUNS_ENABLED` | `false` | No effect in D1: the code keeps it off. **Never enable grass runs in D1.** |
| `IGUIDE_LAB_TOUR_DECLUTTER_RUNS_ENABLED` | `false` | No effect in D1: the code keeps it off. **Never enable declutter runs in D1.** |

Queue keys: `IGUIDE_LAB_TOUR_QUEUE_CONNECTION` and `IGUIDE_LAB_TOUR_QUEUE` default to `studio`/`studio`, and `IGUIDE_LAB_TOUR_WORKERS` (1-8) defaults to 1. The connection must exist in `config/queue.php` and use the `database` driver. Anything else, for example a typo or `sync`, falls back to `studio`/`studio` and logs `Tour queue settings are invalid` once per process. Two existing flags must stay `false`: `IGUIDE_LAB_TOUR_SCHEDULER_ENABLED` and `IGUIDE_LAB_TOUR_PROCESSING_BUDGET_ENABLED`. If either is on, the estimate reports `scheduler_mode_unsupported` or `tour_budget_required` and no run starts.

## 2. Flip a flag

```bash
cd /var/www/backend
# edit .env
sg www-data -c 'php artisan config:cache'
php artisan queue:restart
```

Production config is cached. An `.env` edit does nothing until `config:cache` runs, and workers keep the old config until `queue:restart`. Afterwards, check `capabilities.enabled` in `GET /api/iguide-lab/tour-analyses`. If PHP-FPM still shows the old value, it needs the opcache reset that the deploy script does.

For the first enable, use `IGUIDE_LAB_TOUR_ANALYSIS_ENABLED=true` with `IGUIDE_LAB_TOUR_RUNS_PAUSED=true`.

**Kill-switch latency.** A flag change applies only after `config:cache` and `queue:restart`. A worker finishes its current job with the config it loaded at boot, and queued jobs check the flags when they start. Turning analysis off stops new work, but not a provider call already in flight.

## 3. Dedicated worker (optional)

By default, tour jobs run on the single shared `studio` worker. They wait behind Listing Studio jobs that can take up to 2 h. A crashed survey job on `studio` stays reserved for about 2 h (`retry_after` 7260). While its job is pending, a survey is never sent again and never marked lost.

A monitor job that runs past its 300 s timeout on `studio` is killed, and its `jobs` row also stays reserved for about 2 h. The sweeper and the record GET restart a monitor chain only when no job of that monitor is waiting or reserved (`LabTourQueue::pendingJobExists`, whatever the row's age). So that analysis or run makes no progress for about 2 h. Nothing extra is spent or queued meanwhile. On the dedicated connection the same wait is about 15 min (`retry_after` 900).

The installer adds the tour program only when the tour queue target is the dedicated one. A Studio reinstall after a Studio release (`docs/studio-workspaces-v4.md`) therefore never adds or starts a tour worker while the target is `studio`/`studio`.

To use the dedicated worker:

1. In `.env`, set `IGUIDE_LAB_TOUR_QUEUE_CONNECTION=iguide-lab-tour` and `IGUIDE_LAB_TOUR_QUEUE=iguide-lab-tour`, and optionally `IGUIDE_LAB_TOUR_WORKERS=1`. Then run the commands of section 2.
2. Check the target: `php artisan iguide-lab:tour-workers`. It prints `{"connection","queue","workers","dedicated"}`, and `dedicated` must be `true`. `php artisan iguide-lab:tour-workers --count` must then print the worker count (1-8). It prints `0` while the target is not dedicated.
3. Run the installer: `bash scripts/install-studio-runtime.sh`. It also rewrites the crontab entry, Studio storage permissions and `supervisord.conf`.
   - It adds `[program:repro-lab-tour]` with `numprocs` = `iguide-lab:tour-workers --count`, read through `sg www-data`.
   - It skips the program without a message when the release has no tour job.
   - It skips the program with the line `Info: the tour queue is not the dedicated iguide-lab-tour target; the tour worker program is not installed` when the count is `0`.
   - It skips the program with a `Warning: unexpected tour worker count` line for any other value outside 1-8, including a failed artisan call.
   - It never fails the Studio install because of the tour program, and the `[program:repro-studio]` block stays unchanged.
4. Check the processes: `supervisorctl -c ~/.local/share/repro-studio/supervisord.conf status 'repro-lab-tour:*'`.

The program runs `queue:work iguide-lab-tour --queue=iguide-lab-tour --timeout=660` (the jobs time out at 600 s, and `retry_after` is 900). It uses `stopwaitsecs=720` and logs to `~/.local/share/repro-studio/logs/lab-tour-worker-NN.log`. Without the `.env` switch, it waits on an empty queue.

**Pause:**

```bash
touch ~/.local/share/repro-studio/paused-lab-tour
supervisorctl -c ~/.local/share/repro-studio/supervisord.conf stop 'repro-lab-tour:*'
```

A worker reads the pause file only when it starts, so the `stop` is required. The shared `paused` file also stops this program. To resume, remove the pause file and run `start 'repro-lab-tour:*'`. A stopped tour program does not fail the installer.

## 4. Operator commands

| Command | Paid | What it does |
|---|---|---|
| `sg www-data -c 'php artisan iguide-lab:runtime-manifests --check'` | no | Read-only report. It prints one JSON line per runtime manifest (path, size, sha256, owner/group/mode, `check` = `pass`/`fail`/`not_applicable`; on failure only the error class and HTTP status), then one summary line `{"version":"runtime-manifest-check/1","presetsReady",...,"ok"}`. It exits 0 only when no manifest fails and every enabled preset is ready (golden hour and twilight always count). Run it as the worker user through `sg www-data`, as shown. Do not run it during a deploy window: an owner's readiness probe can write Python bytecode, which changes the deploy fingerprint. |
| `php artisan iguide-lab:tour-workers [--count]` | no | Shows the tour queue target. With `--count` it prints only the worker count when the target is dedicated, otherwise `0` (the installer reads it). |
| `php artisan iguide-lab:tour-survey PROJECT_UUID IMPORT_UUID --limit=N [--preset=golden-hour]` | yes | Creates or resumes the shared analysis of an import and allows the next N (1-80) pending photos to be surveyed. It prints a JSON receipt. It refuses when analysis is disabled. |
| `php artisan iguide-lab:tour-survey-reparse SURVEY_UUID [--force]` | no | Re-reads a stored answer with the current parser. It makes no provider call. `--force` re-parses a completed survey only when no run uses it. |
| `php artisan iguide-lab:tour-monitors` | no | The scheduler runs it every minute (see section 5). It restarts dead monitor chains and sweeps stale surveys. |
| `php artisan iguide-lab:set-run-limit PROJECT_UUID LIMIT --expected-current=N` | no | Raises one project's image limit (see `docs/iguide-ai-lab.md`). |

## 5. Scheduler dependency

Background recovery uses the Laravel scheduler (`iguide-lab:tour-monitors`, every minute). The server must run `php artisan schedule:run` every minute from cron. The lab already relies on it for `iguide-lab:prune-import-uploads`.

Without it, a dead monitor chain restarts only when someone opens the record, and a leaked survey slot is freed only by an analysis tick.

The entry runs the sweep in the background with a 5-minute overlap lock (`withoutOverlapping(5)->runInBackground()`). A sweep that is killed mid-run (OOM, reboot) blocks later sweeps for at most 5 minutes, not the default 24 h. The sweep also no longer delays the other every-minute tasks.

Confirm the scheduler in the server batch with these read-only checks:

```bash
crontab -l 2>/dev/null | grep -n -E 'artisan +schedule:(run|work)' || echo none-maverick
grep -rn -E 'schedule:(run|work)' /etc/crontab /etc/cron.d 2>/dev/null || echo none-system
grep -rln -E 'schedule:(run|work)' /etc/supervisor/conf.d 2>/dev/null || echo none-supervisor
systemctl list-timers --all --no-pager | grep -i -E 'laravel|schedule|artisan' || echo none-timer
# catch one live run (up to 75 s); prints the php line with the user that really runs it
for i in $(seq 75); do p=$(ps -eo user=,args= | grep -E '[a]rtisan schedule:(run|work)' | grep -v '/sg '); [ -n "$p" ] && { echo "$p"; break; }; sleep 1; done
sg www-data -c 'php artisan schedule:list' | grep -E 'iguide-lab:(tour-monitors|prune-import-uploads)'
sg www-data -c 'php artisan config:show cache.default'
```

Good results:
- Exactly one every-minute `schedule:run`, and its php line runs as a user in the `www-data` group. Root's crontab is not readable without sudo, so the owner checks that one.
- `schedule:list` shows `iguide-lab:tour-monitors` every minute.
- `cache.default` is `file`, `database` or `redis`. With `array`, the overlap lock and the record GET's kick gate do not work across processes.

If the php line runs as root, stop and decide; a tour-only line does not help, because the root scheduler would still run the sweep. If no scheduler runs at all, do not install `schedule:run`: it would start every scheduled task, including customer SMS, invoice and email reminders and `provider:auto-deliver`. Instead, with owner approval, save `crontab -l` to a backup file and add one tagged line for the sweep only (the directory `~/.local/share/repro-iguide-lab-tour` is created first with `install -d -m 700`):

```
* * * * * /usr/bin/flock -n $HOME/.local/share/repro-iguide-lab-tour/monitors.lock /usr/bin/timeout 290 /usr/bin/sg www-data -c 'umask 0002; cd /var/www/backend && /usr/bin/php artisan iguide-lab:tour-monitors' >> $HOME/.local/share/repro-iguide-lab-tour/monitors.log 2>&1 # repro-iguide-lab-tour-monitors
```

`timeout 290` keeps a hung sweep from holding the `flock` for good. To undo it, restore the saved crontab. Upload pruning still does not run without the scheduler.

## 6. Paid 3-photo test (approval first)

**What bounds spend before the editor's tour UI ships (Lovable message L1).** Until then, only two things limit paid surveys: `--limit` on the operator command, and the caps (`IGUIDE_LAB_TOUR_SURVEY_CALLS_PER_DAY`, `IGUIDE_LAB_TOUR_SURVEY_CALLS_PER_PROJECT`, at most 2 paid surveys per photo). Turning analysis on also opens `POST /api/iguide-lab/tour-analyses` to anyone who holds a lab link. That create has no limit: it allows every pending photo of the import (up to 80), and on a shared record it also lifts an operator `--limit`. So:
- during a paid step, set the daily cap to that step's worst case: 2 attempts per allowed photo, plus the attempts of the last 24 h;
- between paid steps, set the daily cap to `0`. A create that needs a paid call then gets a 429. Set it to `0` only when no survey is `queued`, `submitting` or `received`; a queued survey would be failed and lose one of its 2 paid surveys.

1. Run `php artisan iguide-lab:tour-survey PROJECT_UUID IMPORT_UUID --limit=3`. Analysis must be enabled, and the runs can stay paused.
2. Read the usage rows. This is read-only:

   ```bash
   php artisan tinker --execute="dump(DB::table('ai_provider_usage')->where('feature', 'tour_survey')->latest('occurred_at')->limit(10)->get(['occurred_at', 'model', 'status', 'http_status', 'input_tokens', 'output_tokens'])->all());"
   ```

3. Read the stored answers in `storage/app/private/listing-studio-trials/iguide-lab/PROJECT_UUID/tour-surveys/SURVEY_UUID/response-0.json`.
4. If the parser needs a fix, the re-parse is free (section 4).

## 7. What each paid step costs

| Step | Paid calls | Limits |
|---|---|---|
| Survey (one per photo) | One OpenAI Responses call (`gpt-6-astra`, medium effort, up to 16,000 output tokens). A quota fallback to the Dashboard key is a second paid attempt. | 240 attempts per project lifetime and 160 lab-wide per rolling 24 h; quota fallbacks count. At most 2 paid surveys per photo, and none is retried automatically. A cached survey (same project, photo, preview and survey version) is free. |
| Re-parse, manifest check, worker count | none | |
| Lighting run, per room (golden hour / twilight) | Worst case: 3 image edits, 2 analyses and 5 quality evaluations. Composites are local. | Images count against the project's `max_runs` and the lab-wide 120 per day. Evaluations allow about 24 rooms per project lifetime and per lab-wide day (120 / 5). The estimate shows `roomsThatFit` and `worstCaseImages` before a run starts. |

The usage rows show tokens. `estimated_cost_usd` stays empty because `config/ai_usage.php` has no `gpt-6-astra` price.

## 8. Post-deploy checks (flags still off)

- `sg www-data -c 'php artisan iguide-lab:runtime-manifests --check'` exits 0 with `presetsReady` 7.
- The editor contract check `check.ts live` passes.
- An explicit `GET /api/iguide-lab/imports` and `GET /api/iguide-lab/imports/{id}` read works. `check.ts live` does not read imports, and link imports now report `sourceKind:"link"`.
- The editor loads.
- `GET /api/iguide-lab/tour-analyses` shows `capabilities.enabled:false` before the flip.

## 9. Rollback (each step with approval)

1. Set `IGUIDE_LAB_TOUR_ANALYSIS_ENABLED=false`, then run `config:cache` and `queue:restart` (section 2).
2. Wait until no paid outcome is in flight, so that none is lost. Both counts must be 0. These queries are read-only:

   ```bash
   php artisan tinker --execute="echo DB::table('iguide_lab_tour_surveys')->whereIn('status', ['submitting', 'received'])->count(), PHP_EOL;"
   php artisan tinker --execute="echo DB::table('iguide_lab_tour_run_chunks')->where('status', 'reserved')->count(), PHP_EOL;"
   ```

3. Delete the pending tour jobs. After the revert their classes no longer exist, and the jobs would fail. List them first (read-only):

   ```bash
   php artisan tinker --execute="\$names = ['App\\Jobs\\ProcessIguideLabTourSurvey', 'App\\Jobs\\MonitorIguideLabTourAnalysis', 'App\\Jobs\\MonitorIguideLabTourRun']; dump(DB::table('jobs')->get(['id', 'queue', 'payload'])->filter(fn (\$job) => in_array(json_decode(\$job->payload)->displayName ?? null, \$names, true))->pluck('id')->all());"
   ```

   Then delete exactly those ids with `DB::table('jobs')->whereIn('id', [...])->delete()`. This is a write.
4. Revert the deploy.
5. Remove the tour program. Either re-run the installer from the reverted tree (it no longer writes `[program:repro-lab-tour]`), or stop the program, delete its block from `~/.local/share/repro-studio/supervisord.conf` and run `supervisorctl -c ~/.local/share/repro-studio/supervisord.conf update`:

   ```bash
   supervisorctl -c ~/.local/share/repro-studio/supervisord.conf stop 'repro-lab-tour:*'
   ```

   Otherwise the installed program stays in `supervisord.conf`. Its guard on `app/Jobs/ProcessIguideLabTourSurvey.php` then fails, and the program loops into FATAL.

The migrations only add tables. Never roll them back with `down()`. The `iguide-lab:tour-monitors` schedule entry goes away with the revert.
