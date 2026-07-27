-- Rollback migracji 027 (CHAT-T-086): CHECK z 3 wartosci na 2.
--
-- UWAGA: jesli sa juz wiersze nudge_dismiss w tabeli, rollback CHECK
-- sie wywali (PG sprawdza istniejace wiersze przy ADD CHECK). Najpierw
-- DELETE WHERE event_type='nudge_dismiss' — zachowuje migracja jako
-- ostrzezenie: dane sa tracone razem z rollbackiem (nie da sie wrocic
-- do 2 wartosci CHECK bez ich usuniecia). W produkcji rozwazyc czy to
-- akceptowalne; w razie watpliwosci zostawic migracje 027 i tylko cofac
-- zmiany frontu/backendu.
DELETE FROM divechat_nudge_events WHERE event_type = 'nudge_dismiss';

ALTER TABLE divechat_nudge_events
    DROP CONSTRAINT IF EXISTS divechat_nudge_events_event_type_chk;

ALTER TABLE divechat_nudge_events
    ADD CONSTRAINT divechat_nudge_events_event_type_chk
    CHECK (event_type IN ('nudge_shown', 'nudge_cta_click'));
