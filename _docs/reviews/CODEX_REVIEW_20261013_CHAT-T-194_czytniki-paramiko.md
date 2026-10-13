═══ CHAT-T-194 · INTEGRATION · KROK 4 ═══

## Findings

1. **MEDIUM — Missing-key behavior is not directly verified in the changed files.**  
   The new check is independently duplicated in [mysql_dev.py](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/mysql_dev.py:93), [mysql_pmk.py](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/mysql_pmk.py:58), and [ssh_pmk.py](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/ssh_pmk.py:27). A3 exercises the **pre-change** copy, while A6/A7 exercise only `mysql_dev.py`; the new missing-key path is therefore not tested directly, nor are the negative paths of the other two copies ([task:83](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_instances/integration/tasks/CHAT-T-194_INTEGRATION_czytniki-paramiko-macos.md:83), [task:89](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_instances/integration/tasks/CHAT-T-194_INTEGRATION_czytniki-paramiko-macos.md:89)).

2. **MEDIUM — The `mysql_dev.py` cleanup description remains stronger than the implementation.**  
   The docstring says the temporary file remains only when SSH breaks between creation and removal ([mysql_dev.py:19](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/mysql_dev.py:19)). Cleanup is actually a best-effort trailing `rm -f` ([mysql_dev.py:116](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/mysql_dev.py:116)); the client neither checks the remote exit status nor treats nonempty stderr as failure when stdout is valid JSON ([mysql_dev.py:132](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/mysql_dev.py:132)). Consequently, an `rm` failure can be silently reported as a successful query without an SSH break.

3. **LOW — The fixed temporary filename has an unstated single-invocation assumption.**  
   Every execution writes, executes, and removes the same `/tmp/_hades_ro.php` path ([mysql_dev.py:117](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/mysql_dev.py:117)). Concurrent invocations can interleave so that one removes the file before another opens it. This is pre-existing and outside the requested implementation scope, but it is a real failure mode exposed by the expanded documentation.

4. **LOW — Connection failures bypass explicit cleanup.**  
   In all three scripts, `connect()` occurs before the `try/finally`, so rejection of an unknown/mismatched host or authentication failure skips `cli.close()`: [mysql_dev.py:129](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/mysql_dev.py:129), [mysql_pmk.py:91](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/mysql_pmk.py:91), [ssh_pmk.py:41](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/ssh_pmk.py:41). One-shot CLI processes will normally be reclaimed by the OS, but reusable/imported callers do not receive deterministic cleanup.

5. **MEDIUM — Windows compatibility remains an unverified environmental assumption.**  
   Portable path construction itself is present in all three files, for example [mysql_dev.py:46](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/mysql_dev.py:46), but the task explicitly leaves Windows untested ([task:93](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_instances/integration/tasks/CHAT-T-194_INTEGRATION_czytniki-paramiko-macos.md:93)). Successful operation additionally assumes Desktop Commander resolves `~` to the profile containing `.ssh`, that `id_ed25519` is a readable and unencrypted Ed25519 key—the loaders provide no passphrase ([mysql_dev.py:128](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/mysql_dev.py:128), [mysql_pmk.py:90](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/mysql_pmk.py:90), [ssh_pmk.py:40](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_diag_local/chat_verification/ssh_pmk.py:40)—and that Windows `known_hosts` contains the port-qualified host entry currently confirmed only on macOS ([task:30](/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_instances/integration/tasks/CHAT-T-194_INTEGRATION_czytniki-paramiko-macos.md:30)).

## Proposed next steps

- Add post-change missing-key and missing/empty `known_hosts` mutants for each script; assert the named path and verify that `connect()` was not called.
- Add a wrong-key entry test, not only an empty `known_hosts` test.
- Soften the `mysql_dev.py` cleanup wording to “best effort” and mention cleanup failure/interruption; track the fixed-filename race separately.
- Move each `connect()` inside the existing `try/finally`.
- Run one real Windows smoke before claiming Windows support; otherwise state “Windows-compatible path construction, not tested on Windows.”
- Document the required key type/passphrase expectation and the `[divezonededyk.smarthost.pl]:5739` trust-store entry.

No high-severity defect was found.

═══ CHAT-T-194 · INTEGRATION · KROK 4 ═══