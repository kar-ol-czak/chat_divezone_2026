#!/bin/bash
# Setup srodowiska dla pipeline generacji encyklopedii
# Uzycie: cd generate_encyclopedia && chmod +x setup.sh && ./setup.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

echo "=== Setup: generate_encyclopedia ==="

# 1. Sprawdz Python
if ! command -v python3 &> /dev/null; then
    echo "BLAD: python3 nie znaleziony"
    exit 1
fi
echo "Python: $(python3 --version)"

# 2. Sprawdz .env
ENV_FILE="$(dirname "$SCRIPT_DIR")/.env"
if [ ! -f "$ENV_FILE" ]; then
    echo "BLAD: brak pliku .env w katalogu projektu"
    exit 1
fi

# Sprawdz klucze API
if ! grep -q "OPENAI_API_KEY=." "$ENV_FILE"; then
    echo "BLAD: OPENAI_API_KEY pusty w .env"
    exit 1
fi
if ! grep -q "ANTHROPIC_API_KEY=." "$ENV_FILE"; then
    echo "BLAD: ANTHROPIC_API_KEY pusty w .env"
    exit 1
fi
echo "API keys: OK"

# 3. Utworz venv
if [ ! -d ".venv" ]; then
    echo "Tworzenie .venv..."
    python3 -m venv .venv
else
    echo ".venv juz istnieje"
fi

# 4. Instaluj zaleznosci
source .venv/bin/activate
echo "Instalowanie zaleznosci..."
pip install -q -r requirements.txt

# 5. Utworz katalogi output
mkdir -p output/logs

# 6. Test importow
python3 -c "import openai, anthropic, jinja2, dotenv; print('Importy: OK')"

echo ""
echo "=== Setup zakonczony ==="
echo ""
echo "Uzycie:"
echo "  source .venv/bin/activate"
echo "  python run.py --group C --dry-run"
echo "  python run.py --group C"
