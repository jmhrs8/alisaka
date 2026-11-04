#!/bin/bash
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export HOME=/home/sistemas

DIRECTORIO_PHP="/var/www/html/erp"
FECHA=$(date +"%Y-%m-%d %H:%M:%S")

DB_USER="root"
DB_PASS="jmhl2474"
DB_NAME="erp_inventory"

ARCHIVO_SQL="$DIRECTORIO_PHP/base_de_datos_ultima.sql"

echo "=========================================="
echo " Iniciando respaldo: $FECHA"
echo "=========================================="

# 1. Generar respaldo SQL directamente dentro del repositorio web
echo "[1/3] Generando respaldo SQL en la ruta del ERP..."
mysqldump -u "$DB_USER" -p"$DB_PASS" --routines --triggers --single-transaction "$DB_NAME" > "$ARCHIVO_SQL"

if [ ! -s "$ARCHIVO_SQL" ]; then
    echo "  -> [ERROR] Falló la extracción de la base de datos."
    exit 1
fi
echo "  -> Base de datos generada correctamente."

# 2. Sincronización con GitHub
echo "[2/3] Sincronizando con GitHub..."
cd "$DIRECTORIO_PHP" || exit

git config --global --add safe.directory /var/www/html
git config --global --add safe.directory "$DIRECTORIO_PHP"
git config --global http.postBuffer 524288000
rm -f "$DIRECTORIO_PHP/.git/index.lock"

git add base_de_datos_ultima.sql
git add .
git commit -m "Auto-backup sistema y BD: $FECHA" --no-edit

echo "[3/3] Enviando a GitHub..."
timeout 45s git push origin main

if [ $? -eq 0 ]; then
    echo "  -> [OK] Base de datos y código subidos a GitHub con éxito."
else
    echo "  -> [AVISO] El push tardó demasiado o no hubo cambios nuevos."
fi

echo "=========================================="
echo " RESPALDO COMPLETADO EXITOSAMENTE"
echo "=========================================="
