#!/usr/bin/env python3
"""
list_open_problems.py — wyciaga z Railway PG rozmowy oflagowane jako
verdict='problem_do_rozwiazania' o statusie NIEzamknietym (nowy/do_weryfikacji).

To JEST punkt startu przy zadaniu "zweryfikuj czaty do naprawy".
Zrodlo przebiegu: divechat_conversations.messages (jsonb) — to samo, co pokazuje
panel recenzji w module PS (/api/conversations/{sid} -> ConversationStore).

Uzycie:
    python3 list_open_problems.py            # podsumowanie (notatki + metadane)
    python3 list_open_problems.py --full     # + pelny przebieg kazdej rozmowy
    python3 list_open_problems.py --dump PLIK # przebiegi zapisane do pliku
"""
import sys
from _conn import connect

FULL = "--full" in sys.argv
DUMP = None
if "--dump" in sys.argv:
    i = sys.argv.index("--dump")
    if i + 1 < len(sys.argv):
        DUMP = sys.argv[i + 1]


def fmt_msgs(msgs):
    out = []
    if not isinstance(msgs, list):
        return "(brak/nieprawidlowy format)"
    for m in msgs:
        if not isinstance(m, dict):
            continue
        role = m.get("role", "?")
        content = m.get("content", "")
        if isinstance(content, list):
            parts = []
            for b in content:
                if isinstance(b, dict):
                    parts.append(b.get("text") or b.get("type", ""))
                else:
                    parts.append(str(b))
            content = " ".join(parts)
        out.append(f"[{role}] {content}")
    return "\n".join(out)


def main():
    conn = connect()
    cur = conn.cursor()
    cur.execute("""
        select r.id, r.conversation_id, r.status, r.note, r.updated_at,
               c.session_id, c.model_used, c.knowledge_gap, c.tools_used,
               c.chip_path, c.messages
        from divechat_conversation_review r
        join divechat_conversations c on c.id = r.conversation_id
        where r.verdict='problem_do_rozwiazania'
          and r.status in ('nowy','do_weryfikacji')
        order by r.updated_at desc
    """)
    rows = cur.fetchall()
    print(f"OTWARTE PROBLEMY: {len(rows)}\n")

    dump_fh = open(DUMP, "w") if DUMP else None
    for r in rows:
        (rid, cid, status, note, upd, sid, model, kgap, tools,
         chip, msgs) = r
        head = (f"--- review_id={rid} conv_id={cid} [{status}] "
                f"{upd:%Y-%m-%d %H:%M}")
        meta = (f"    model={model} knowledge_gap={kgap} "
                f"tools={tools} chip={chip}")
        print(head)
        print(meta)
        print(f"    NOTE: {note}\n")
        if dump_fh:
            dump_fh.write(f"\n{'='*90}\n{head}\n{meta}\nNOTE: {note}\n"
                          f"{'-'*90}\n{fmt_msgs(msgs)}\n")
        elif FULL:
            print(fmt_msgs(msgs))
            print()
    if dump_fh:
        dump_fh.close()
        print(f"\nPrzebiegi zapisane do: {DUMP}")


if __name__ == "__main__":
    main()
