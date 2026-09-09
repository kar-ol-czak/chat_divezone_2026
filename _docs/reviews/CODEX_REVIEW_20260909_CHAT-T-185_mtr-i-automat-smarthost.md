═══ CHAT-T-185 · REVIEW · [STOP] ═══

Verdict: **do not approve unchanged.** The pasted `29ee267` snapshot does not guarantee once-per-episode delivery or the three-per-day cap, can lose pending tickets on restart, and can send incomplete or misleading evidence.

Line references below refer to the pasted snapshot, not subsequent worktree edits.

## Findings

1. **CRITICAL — the daily cap and deduplication are not concurrency-safe.**

   The count is read under one lock, the lock is released, `mail()` runs, and only afterward is the counter incremented under a new lock (`railway_monitor.php:L349`, `L388-L389`). Two monitors can both read `2`, both send, then increment to `4`. They can also send the same episode twice because there is no durable episode identifier.

   Failure handling is actually fail-open: inability to open or lock the counter returns `0` during the pre-send read, despite the comment saying mail should be suppressed (`L289-L292`). Empty, non-numeric, or negative content also casts to a send-permitting value at `L292`; an erroneously large value suppresses all remaining mail. Write/truncate failures are ignored at `L295`.

2. **CRITICAL — pending tickets can be lost or overwritten.**

   There is only one in-memory slot (`L420`), overwritten unconditionally by each recovery (`L546-L548`). A second qualifying recovery before the first ticket is sent loses the first ticket.

   Every restart initializes the slot and all episode state from scratch (`L413-L420`). The guard can kill and restart the process (`railway_monitor_guard.sh:L45-L58`), so a restart during the 180-second delay loses the mail permanently. A restart during an active failure also loses the active episode and cooldown state, allowing the same external outage to become a new alert after three failures.

   A failed `mail()` attempt is discarded because the slot is cleared regardless of success (`railway_monitor.php:L452-L455`). Conversely, concurrent monitor instances can each hold and send their own copy. Failures that recover entirely during the existing 15-minute cooldown never become `alertActive`, so they produce no provider ticket at all (`L489-L511`); “episode” therefore means “episode admitted by the existing alert cooldown,” not every detected failure episode.

3. **HIGH — the “dump stopped growing” test cannot establish completeness.**

   A missing or unstatable file is explicitly treated as stable (`L449-L451`). No completion marker or flock state is checked.

   A 20-second mtime gap is insufficient: sequential `ping`, `traceroute`, and report-mode MTR commands can remain silent longer than 20 seconds while still running (`L185-L191`). PHP stat results can also remain cached in a long-running process because no `clearstatcache()` is performed around `filemtime()` (`L449`).

   Worse, the parser considers an MTR section present as soon as it encounters its heading (`L317-L322`), and the email treats any non-empty extracted text as completed MTR evidence (`L385-L387`). It does not require both ICMP and TCP output, either diagnostic end marker, or successful command exit. The 600-second deadline sends regardless of completeness (`L451-L453`).

4. **HIGH — dumps can overlap across episodes; the flock only protects one incident file.**

   Each diagnostic has sequential hard ceilings of approximately 390 seconds: four 40-second pings, one 50-second traceroute, and two 90-second MTR runs (`L185-L191`). Both episode-start and episode-end diagnostics are launched (`L507`, `L534`). Because they share one incident file, its flock serializes them (`L196-L199`), yielding a theoretical approximately 780-second chain; with the supplied production measurement, a short episode commonly leaves the file growing for about 200 seconds.

   A later episode gets a different timestamped incident file (`L503`), hence a different lock. If the first episode lasts beyond the 15-minute cooldown, the next episode may start shortly after recovery while the prior recovery dump is still running (`L489-L499`, `L555-L556`). Those dumps run concurrently. The daily baseline uses another separate lock and can overlap both (`L162-L164`, `L438-L442`).

   The lock wait itself has no deadline at `L196`; a wedged holder leaves the recovery dump waiting indefinitely in the background.

5. **HIGH — the delayed send can block the sampling loop and trigger the guard.**

   Ticket processing occurs synchronously before TCP and PostgreSQL sampling (`L447-L461`). It performs a blocking flock, reads the entire incident file, and calls synchronous PHP `mail()` (`L349`, `L354`, `L388`). There is no timeout or exception boundary around this path.

   If it blocks for 60 seconds, the guard sees a stale log and kills the monitor (`railway_monitor_guard.sh:L16`, `L40-L58`), thereby also losing the in-memory pending ticket.

   It also changes timestamp semantics: `$nowW` is captured before sending (`railway_monitor.php:L425`), while the UTC sample timestamp is taken after sending and probing (`L466-L470`). The daily parser currently documents that this difference represents probe duration (`railway_summary_mail.php:L90-L93`); after this change it can include provider-mail latency. Failure start and recovery windows also use that pre-mail `$nowW` (`railway_monitor.php:L483-L485`, `L517-L519`).

6. **HIGH — the provider email can make claims unsupported by the evidence.**

   Ping loss is reduced to an independent maximum per target across the entire incident file (`L324-L328`). That combines episode-start and recovery measurements from different times. The email can therefore infer a “wider connection problem” using Railway loss from one block and control loss from another.

   MTR extraction discards the surrounding `### DIAG`, timestamp, and start/recovery labels (`L315-L322`). If the file is complete, the recipient receives four similarly named sections without knowing which pair was measured during failure and which during recovery.

   The “controls were clean” conclusion does not require all controls to be present or actually zero (`L368-L373`); one or all may be missing, or may show non-zero loss below 20%. The alternative conclusion says the outage concerned the database TCP connection (`L373`), although an alert can be triggered by any PG query/write metric rather than a failed TCP connection (`L475-L490`).

   The subject always asserts packet loss (`L391`) even when Railway ping is missing or 0%. The body says sampling occurs every five seconds (`L376-L378`), although five seconds is only the post-cycle sleep and the supplied observed cadence is approximately six seconds (`L565`). These are externally visible factual overstatements.

7. **HIGH — the 18:30 scheduler is neither at-least-once nor at-most-once across restarts.**

   Starting after 18:30 schedules only the following day (`L398-L401`), so a restart that straddles the scheduled time can make that day’s baseline never run.

   Scheduling state exists only in memory (`L400-L401`, `L438-L442`). Concurrent monitor instances each fire independently. The per-file flock serializes their writes but does not deduplicate them (`L162-L164`), so the same daily file can receive multiple complete baseline runs.

   The baseline is unconditionally labelled “healthy reference state” (`L148-L156`) even if an incident is active. The email repeats that claim (`L389`), although the scheduler never checks current probe or alert state (`L438-L442`).

8. **MEDIUM — diagnostic routing can silently diverge from the route actually monitored.**

   The production host and port come from `DATABASE_URL` (`L54-L62`), but incident ping/MTR uses fixed IP `66.33.22.230` and hard-coded port `14368` (`L81`, `L185-L191`). Baseline MTR receives the runtime port, while incident MTR does not (`L440`, `L191`). A Railway DNS or proxy-port change would make the ticket describe stale infrastructure while the main monitor probes the new endpoint.

9. **MEDIUM — background launch and MTR success are not verified.**

   Results from both background `shell_exec()` launches are ignored (`L164`, `L199`). Nevertheless, callers log that the baseline was scheduled or the diagnostic was saved (`L440-L441`, `L507-L508`, `L534-L535`).

   MTR exit status is neither recorded nor validated (`L141-L144`). A heading plus an error message is enough for the ticket parser to treat MTR as present (`L317`, `L385-L387`). There is no distinction between complete output, timeout, unavailable capability, failed process creation, or a queued process still waiting for flock.

10. **MEDIUM — the sample line remains parser-compatible, but measurement semantics may change.**

    The actual measurement-line format is unchanged (`L466-L470`). The daily parser still matches only numbered measurement lines and treats only unmatched `#<digits>` lines as format regressions (`railway_summary_mail.php:L108-L146`), so the new `# MTR-BASELINE` and `### MAIL-SMARTHOST` lines should be ignored safely.

    However, background MTR jobs continue while the same host performs TCP/PG/GitHub probes (`railway_monitor.php:L139-L144`, `L459-L472`). Episode-start, recovery, and baseline MTR can overlap because their locks are file-specific. The change therefore introduces additional TCP/ICMP traffic and process load into the system being measured; no A/B verification demonstrates that latency, GitHub-control failures, alert counts, or daily-report window classification remain unchanged.

## Proposed next steps

1. Block deployment until the cap uses one atomic reservation transaction and a durable per-episode idempotency key. Test two synchronized monitor processes at counts 0 and 2, plus missing, unwritable, empty, negative, non-numeric, and oversized counter files.

2. Persist pending-ticket state and support a real queue. Add crash-injection tests at recovery, during dump generation, before/after mail submission, and before/after recording success. Explicitly define whether cooldown-suppressed failures count as episodes.

3. Replace mtime stability with verifiable completion: successful end markers/exit statuses and ownership of the incident lock. Require both MTR modes for both start and recovery, or explicitly state which measurements are absent.

4. Add a monitor singleton lock and a global diagnostic concurrency policy covering incident dumps and baseline MTR. Test a short episode, an episode longer than 15 minutes, a new episode during recovery diagnostics, and duplicate monitor processes.

5. Move provider delivery off the sampling path or give every blocking operation a bound shorter than the guard threshold. Test a deliberately hung MTA and locked counter file while asserting uninterrupted sample cadence.

6. Make ticket evidence block-aware. Keep failure-start and recovery measurements separate with timestamps; avoid causal claims unless contemporaneous controls support them. Use a neutral subject for PG failures without proven packet loss.

7. Make baseline scheduling durable and idempotent per UTC date, with an explicit catch-up policy. Test restarts immediately before, during, and after 18:30, plus two concurrent monitors. Do not call it “healthy” unless health was actually established.

8. Resolve the target from the monitored endpoint or verify the fixed IP/port against `DATABASE_URL` before capture and ticket creation.

9. Add production-like acceptance tests for background-spawn failure, MTR timeout/error, silent blackhole intervals, incomplete incident files, midnight-spanning episodes, malformed env addresses, and actual helpdesk ticket threading.

10. Replay known production logs through the daily parser and compare sample counts, failure counts, windows, coverage, and rejected-line counts before/after. Separately measure probe latency and control failures with MTR disabled and enabled.

═══ CHAT-T-185 · REVIEW · [STOP] ═══