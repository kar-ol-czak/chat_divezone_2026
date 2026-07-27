#!/bin/bash
# CHAT-T-150 (ADR-128 nota 2, decyzja 148b) — dead-man watchdog embeddingów produktów.
#
# Niezależny od runnera: obserwuje wiek pliku last_success, NIE runnera samego siebie.
# Dlatego łapie scenariusz, którego heartbeat w runnerze nie złapie: główny wpis crona
# zniknął / runner nie wystartował → last_success przestaje się odświeżać → alert.
# Granica (świadoma): nie łapie śmierci całego crond — to wymaga monitoringu spoza serwera.
#
# Osobna linia crontaba (KROK 7), 08:30. Alert gdy last_success > 26 h (doba + margines)
# lub gdy pliku brak. Adres alertu czytany z .env ścieżką bezwzględną — bez hardkodu maila.
# Exit zawsze 0 (nie zaśmieca logu crona błędem; stan komunikuje mailem + linią w logu).

set -u

ENV_FILE="/home/divezone/public_html/chat.divezone.pl/.env"
LAST_SUCCESS="/home/divezone/logs/divechat_embeddings.last_success"
MAX_AGE_H=26
SENDMAIL="/usr/sbin/sendmail"
FROM_ADDR="noreply@chat.divezone.pl"
HOST_NAME="$(hostname 2>/dev/null || echo unknown)"
TS() { date '+%F %T'; }

# Adres z .env (bez hardkodu). Zdejmij cudzysłowy i CR; email nie ma spacji wewnątrz.
TO_ADDR=""
if [ -r "$ENV_FILE" ]; then
    TO_ADDR="$(grep -E '^DIVECHAT_COST_ALERT_EMAIL=' "$ENV_FILE" 2>/dev/null | head -n1 \
        | cut -d= -f2- | tr -d '"'"'"'\r' | awk '{$1=$1; print}')"
fi

send_alert() {
    subject="$1"; body="$2"
    if [ -z "$TO_ADDR" ]; then
        echo "$(TS) watchdog: BRAK DIVECHAT_COST_ALERT_EMAIL w .env — alert pominięty: $subject" >&2
        return
    fi
    if [ ! -x "$SENDMAIL" ]; then
        echo "$(TS) watchdog: brak $SENDMAIL — alert pominięty: $subject" >&2
        return
    fi
    {
        echo "To: $TO_ADDR"
        echo "From: DiveChat embeddings watchdog <$FROM_ADDR>"
        echo "Subject: [DiveChat embeddings] $subject"
        echo "Content-Type: text/plain; charset=utf-8"
        echo ""
        echo "Host: $HOST_NAME"
        echo "$body"
    } | "$SENDMAIL" -t -oi
    echo "$(TS) watchdog: alert wysłany do adresu z .env ($subject)"
}

now="$(date +%s)"

if [ ! -f "$LAST_SUCCESS" ]; then
    send_alert "dead-man: brak last_success" \
        "Plik $LAST_SUCCESS nie istnieje. Nocny przebieg embeddingów (run_nightly.py, cron 02:15) nigdy nie zakończył się sukcesem albo znacznik skasowano. Sprawdź wpis crona i log /home/divezone/logs/divechat_embeddings.log."
    exit 0
fi

mtime="$(stat -c %Y "$LAST_SUCCESS" 2>/dev/null || echo 0)"
age_h=$(( (now - mtime) / 3600 ))

if [ "$age_h" -gt "$MAX_AGE_H" ]; then
    send_alert "dead-man: brak udanego przebiegu > ${MAX_AGE_H} h" \
        "Ostatni udany przebieg embeddingów: ${age_h} h temu (próg ${MAX_AGE_H} h). Prawdopodobnie zniknął wpis crona 02:15 albo runner pada przed zapisem heartbeatu. Log: /home/divezone/logs/divechat_embeddings.log"
    exit 0
fi

echo "$(TS) watchdog OK: last_success ${age_h} h temu (< ${MAX_AGE_H} h)"
exit 0
