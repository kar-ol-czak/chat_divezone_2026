-- ============================================
-- DIVEZONE CHAT AI - Migracja 018
-- Tabela rol panelu admin (ADR-068 decyzja 176a)
-- Data: 2026-06-01
-- TASK: T-032
--
-- Mapowanie employee_id (PrestaShop pr_employee) -> rola czatu (operator|admin).
-- Backend jest wlascicielem autoryzacji (spojne z 157a). Rola czatu NIE
-- pochodzi z pr_profile — wprost rozdzielona, dlatego np. Sylwia (Super Admin
-- w PS) ma role operator w czacie, a Bartlomiej (Store Manager w PS) ma admin.
--
-- Lookup po podpisaniu kanalu serwerowego (ADR-068 decyzja 174a, ServerHmac-
-- Verifier): employee_id z podpisu -> SELECT role -> autoryzacja endpointu.
--
-- Idempotentna: CREATE TABLE IF NOT EXISTS + ON CONFLICT DO UPDATE w seedzie.
-- Seed: admin = 2,5,46; operator = 14,54,61 (wybor Karola po weryfikacji
-- pr_employee na prod MySQL — wszyscy active=1, T-032 KROK 0).
-- ============================================

CREATE TABLE IF NOT EXISTS divechat_admin_roles (
    employee_id INTEGER PRIMARY KEY,
    role        VARCHAR(16) NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT role_allowed CHECK (role IN ('admin', 'operator'))
);

COMMENT ON TABLE divechat_admin_roles IS
    'ADR-068 decyzja 176a: mapowanie employee_id (PrestaShop pr_employee) -> rola czatu. Backend wlasciel autoryzacji, niezalezne od pr_profile.';
COMMENT ON COLUMN divechat_admin_roles.employee_id IS
    'FK logiczna do pr_employee.id_employee (PrestaShop MySQL). Cross-db FK nieuzywane — walidacja istnienia po stronie aplikacji.';
COMMENT ON COLUMN divechat_admin_roles.role IS
    'Rola czatu (decyzja 164a): operator (mapuje rekomendacje + rationale) | admin (operator + koszty/modele/ustawienia).';

-- Seed startowy 6 rol (T-032 KROK 0, wybor Karola po weryfikacji pr_employee).
INSERT INTO divechat_admin_roles (employee_id, role) VALUES
    ( 2, 'admin'),
    ( 5, 'admin'),
    (46, 'admin'),
    (14, 'operator'),
    (54, 'operator'),
    (61, 'operator')
ON CONFLICT (employee_id) DO UPDATE
    SET role = EXCLUDED.role;
