#!/usr/bin/env python3
"""
show_conversation.py — pelny przebieg JEDNEJ rozmowy po conversation_id.
Zrodlo: divechat_conversations.messages (jsonb) — to samo, co panel recenzji PS.

Uzycie:
    python3 show_conversation.py 634
    python3 show_conversation.py 634 --tools   # pokaz tez tool_result (dlugie)
"""
import sys
import json
from _conn import connect


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    if not args:
        print("Uzycie: show_conversation.py <conversation_id> [--tools]")
        sys.exit(1)
    conv_id = int(args[0])
    show_tools = "--tools" in sys.argv

    conn = connect()
    cur = conn.cursor()
    cur.execute("""
        select c.session_id, c.model_used, c.started_at, c.chip_path,
               c.messages, r.status, r.verdict, r.note
        from divechat_conversations c
        left join divechat_conversation_review r on r.conversation_id = c.id
        where c.id = %s
    """, (conv_id,))
    row = cur.fetchone()
    if not row:
        print(f"Brak rozmowy o conversation_id={conv_id}")
        sys.exit(1)

    sid, model, started, chip, msgs, status, verdict, note = row
    print(f"conv_id={conv_id} session={sid}")
    print(f"model={model} started={started:%Y-%m-%d %H:%M} chip={chip}")
    print(f"recenzja: status={status} verdict={verdict}")
    if note:
        print(f"NOTE: {note}")
    print("=" * 90)

    if not isinstance(msgs, list):
        print("(brak przebiegu)")
        return
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
        if role == "tool" and not show_tools:
            content = "(tool_result — uzyj --tools by pokazac)"
        print(f"[{role}] {content}\n")


if __name__ == "__main__":
    main()
