#!/bin/bash

# ==============================================================================
# SCRIPT DE RESPALDO AUTOMATIZADO CON SOBREESCRITURA Y SUBIDA A GITHUB
# Sistema: PLÁSTICOS ALISAKA
# ==============================================================================

# 1. EXPORTAR RUTAS DE ENTORNO PARA CRON
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export HOME=/home/sistemas

# 2. CONFIGURACIÓN DE RUTAS Y DATOS DE CONEXIÓN
DIRECTORIO_PHP="/var/www/html/erp"
DIRECTORIO_RESPALDOS="/var/www/html/erp/respaldos"
FECHA=$(date +"%Y-%m-%d %H:%M:%S")

DB_USER="root"
DB_PASS="jmhl2474"
DB_NAME="erp_inventory"

# 3. CREAR DIRECTORIOS Y RUTA TEMPORAL
/bin/mkdir -p "$DIRECTORIO_RESPALDOS"
DIR_TEMP="/tmp/respaldo_temp"
/bin/rm -rf "$DIR_TEMP"
/bin/mkdir -p "$DIR_TEMP"

echo "=========================================="
echo " Iniciando respaldo: $FECHA"
echo "=========================================="

# 4. NOMBRES FIJOS PARA SOBREESCRIBIR Y EVITAR SATURAR EL DISCO
ARCHIVO_SQL_RECIENTE="$DIRECTORIO_RESPALDOS/base_de_datos_ultima.sql"
ARCHIVO_FINAL_RECIENTE="$DIRECTORIO_RESPALDOS/RESPALDO_ALISAKA_ULTIMO.tar.gz"

echo "[1/5] Generando respaldo SQL en $DIRECTORIO_RESPALDOS..."
mysqldump -u "$DB_USER" -p"$DB_PASS" --routines --triggers --single-transaction "$DB_NAME" > "$ARCHIVO_SQL_RECIENTE"

if [ -s "$ARCHIVO_SQL_RECIENTE" ]; then
    echo "  -> Base de datos respaldada correctamente en: $ARCHIVO_SQL_RECIENTE"
else
    echo "  -> [ERROR] Falló la extracción de la BD."
    /bin/rm -rf "$DIR_TEMP"
    exit 1
fi

/bin/cp "$ARCHIVO_SQL_RECIENTE" "$DIR_TEMP/"

# 5. COPIAR CÓDIGO PHP Y COMPRIMIR
echo "[2/5] Copiando archivos de la aplicación PHP..."
/bin/cp -r "$DIRECTORIO_PHP" "$DIR_TEMP/codigo_php"

echo "[3/5] Generando archivo comprimido único (sobreescribiendo anterior)..."
tar -czf "$ARCHIVO_FINAL_RECIENTE" -C "$DIR_TEMP" .
/bin/rm -rf "$DIR_TEMP"

# 6. INTEGRACIÓN CON GITHUB (SUBIR RESPALDO Y CÓDIGO)
echo "[4/5] Sincronizando respaldo con GitHub..."

cd "$DIRECTORIO_PHP" || exit

# Asegurar el directorio seguro en Git para ejecuciones bajo root/cron
git config --global --add safe.directory "$DIRECTORIO_PHP"

# Agregar archivos y hacer commit
git add .
git commit -m "Auto-backup sistema y BD: $FECHA" --no-edit

# Subir a GitHub
git push origin main

if [ $? -eq 0 ]; then
    echo "  -> [OK] Cambios y respaldos subidos exitosamente a GitHub."
else
    echo "  -> [AVISO] Falló el envío a GitHub o no hubo cambios detectados."
fi

echo "[5/5] Mantenimiento completado."
echo "=========================================="
echo " RESPALDO EXITOSO Y REEMPLAZADO "
echo " ARCHIVO SQL: $ARCHIVO_SQL_RECIENTE"
echo " PAQUETE TAR.GZ: $ARCHIVO_FINAL_RECIENTE"
echo "=========================================="
