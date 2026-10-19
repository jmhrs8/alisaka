#!/bin/bash

# ==============================================================================
# SCRIPT DE RESPALDO AUTOMATIZADO CON SOBREESCRITURA Y SUBIDA A GITHUB
# Sistema: PLÁSTICOS ALISAKA
# ==============================================================================

# 1. CONFIGURACIÓN DE RUTAS Y DATOS DE CONEXIÓN
DIRECTORIO_PHP="/var/www/html/erp"
DIRECTORIO_RESPALDOS="/home/sistemas/backups"
FECHA=$(date +"%Y-%m-%d %H:%M:%S")

DB_USER="root"
DB_PASS="jmhl2474"
DB_NAME="erp_inventory"

# 2. CREAR DIRECTORIOS Y RUTA TEMPORAL
mkdir -p "$DIRECTORIO_RESPALDOS"
DIR_TEMP="/tmp/respaldo_temp"
rm -rf "$DIR_TEMP"
mkdir -p "$DIR_TEMP"

echo "=========================================="
echo " Iniciando respaldo: $FECHA"
echo "=========================================="

# 3. NOMBRES FIJOS PARA SOBREESCRIBIR Y EVITAR SATURAR EL DISCO
ARCHIVO_SQL_RECIENTE="$DIRECTORIO_RESPALDOS/base_de_datos_ultima.sql"
ARCHIVO_FINAL_RECIENTE="$DIRECTORIO_RESPALDOS/RESPALDO_ALISAKA_ULTIMO.tar.gz"

echo "[1/5] Generando respaldo SQL en $DIRECTORIO_RESPALDOS..."
mysqldump -u "$DB_USER" -p"$DB_PASS" --routines --triggers --single-transaction "$DB_NAME" > "$ARCHIVO_SQL_RECIENTE"

if [ -s "$ARCHIVO_SQL_RECIENTE" ]; then
    echo "  -> Base de datos respaldada correctamente en: $ARCHIVO_SQL_RECIENTE"
else
    echo "  -> [ERROR] Falló la extracción de la BD."
    rm -rf "$DIR_TEMP"
    exit 1
fi

cp "$ARCHIVO_SQL_RECIENTE" "$DIR_TEMP/"

# 4. COPIAR CÓDIGO PHP Y COMPRIMIR
echo "[2/5] Copiando archivos de la aplicación PHP..."
cp -r "$DIRECTORIO_PHP" "$DIR_TEMP/codigo_php"

echo "[3/5] Generando archivo comprimido único (sobreescribiendo anterior)..."
tar -czf "$ARCHIVO_FINAL_RECIENTE" -C "$DIR_TEMP" .
rm -rf "$DIR_TEMP"

# 5. INTEGRACIÓN CON GITHUB (SUBIR ÚLTIMO RESPALDO Y CÓDIGO)
echo "[4/5] Sincronizando respaldo con GitHub..."

cd "$DIRECTORIO_PHP" || exit

# Asegurar que Git agregue los cambios de código y el dump si está rastreado
git add .

# Hacer commit registrando la fecha del respaldo
git commit -m "Auto-backup sistema y BD: $FECHA"

# Subir a GitHub
git push origin main

if [ $? -eq 0 ]; then
    echo "  -> [OK] Cambios y respaldos subidos exitosamente a GitHub."
else
    echo "  -> [AVISO] No hubo cambios nuevos o falló el envio a GitHub."
fi

echo "[5/5] Mantenimiento completado."
echo "=========================================="
echo " RESPALDO EXITOSO Y REEMPLAZADO "
echo " ARCHIVO SQL: $ARCHIVO_SQL_RECIENTE"
echo " PAQUETE TAR.GZ: $ARCHIVO_FINAL_RECIENTE"
echo "=========================================="
