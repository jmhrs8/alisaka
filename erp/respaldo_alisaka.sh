#!/bin/bash

# ==============================================================================
# SCRIPT DE RESPALDO AUTOMATIZADO: BASE DE DATOS Y CÓDIGO PHP
# Sistema: PLÁSTICOS ALISAKA
# ==============================================================================

# 1. CONFIGURACIÓN DE RUTAS Y DATOS DE CONEXIÓN
DIRECTORIO_PHP="/var/www/html/erp"
DIRECTORIO_RESPALDOS="/home/sistemas/backups"
FECHA=$(date +"%Y%m%d_%H%M%S")

# Configuración de Base de Datos
DB_USER="root"
DB_PASS="jmhl2474"
DB_NAME="erp_inventory"

# 2. CREAR DIRECTORIO DE RESPALDOS SI NO EXISTE
mkdir -p "$DIRECTORIO_RESPALDOS"

# Directorio temporal de trabajo para estructurar el tar.gz
DIR_TEMP="/tmp/respaldo_$FECHA"
mkdir -p "$DIR_TEMP"

echo "=========================================="
echo " Iniciando respaldo: $FECHA"
echo "=========================================="

# 3. EXTRAER BASE DE DATOS (.sql) DIRECTAMENTE A LA RUTA DE BACKUPS
ARCHIVO_SQL="$DIRECTORIO_RESPALDOS/base_de_datos_$FECHA.sql"

echo "[1/4] Generando archivo SQL en $DIRECTORIO_RESPALDOS..."
mysqldump -u "$DB_USER" -p"$DB_PASS" --routines --triggers --single-transaction "$DB_NAME" > "$ARCHIVO_SQL"

# VERIFICACIÓN DE EXISTENCIA Y TAMAÑO DEL SQL
if [ -s "$ARCHIVO_SQL" ]; then
    echo "  -> Base de datos respaldada correctamente en: $ARCHIVO_SQL"
else
    echo "  -> [ERROR] Falló la extracción o el archivo SQL está vacío."
    rm -f "$ARCHIVO_SQL"
    rm -rf "$DIR_TEMP"
    exit 1
fi

# COPIAR EL SQL AL DIRECTORIO TEMPORAL PARA INCLUIRLO EN EL COMPRIMIDO
cp "$ARCHIVO_SQL" "$DIR_TEMP/"

# 4. COPIAR CÓDIGO PHP AL TEMPORAL
echo "[2/4] Copiando archivos de la aplicación PHP..."
cp -r "$DIRECTORIO_PHP" "$DIR_TEMP/codigo_php"

if [ $? -eq 0 ]; then
    echo "  -> Archivos PHP copiados correctamente."
else
    echo "  -> [ERROR] Falló la copia de los archivos PHP."
    rm -rf "$DIR_TEMP"
    exit 1
fi

# 5. COMPRIMIR TODO EN UN SOLO ARCHIVO TAR.GZ
ARCHIVO_FINAL="$DIRECTORIO_RESPALDOS/RESPALDO_ALISAKA_$FECHA.tar.gz"

echo "[3/4] Comprimiendo el respaldo global (PHP + SQL)..."
tar -czf "$ARCHIVO_FINAL" -C "$DIR_TEMP" .

# VERIFICACIÓN DEL COMPRIMIDO Y LIMPIEZA
if [ -s "$ARCHIVO_FINAL" ]; then
    echo "  -> Archivo comprimido generado exitosamente."
    # Elimina el directorio temporal
    rm -rf "$DIR_TEMP"
else
    echo "  -> [ERROR] Falló la creación del archivo comprimido .tar.gz"
    rm -rf "$DIR_TEMP"
    exit 1
fi

# 6. ROTACIÓN Y MANTENIMIENTO
# Elimina respaldos comprimidos y archivos SQL con más de 180 días
find "$DIRECTORIO_RESPALDOS" -type f \( -name "RESPALDO_ALISAKA_*.tar.gz" -o -name "base_de_datos_*.sql" \) -mtime +180 -exec rm {} \;

echo "[4/4] Limpieza de temporales completada."
echo "=========================================="
echo " RESPALDO EXITOSO "
echo " ARCHIVO SQL: $ARCHIVO_SQL"
echo " PAQUETE TAR.GZ: $ARCHIVO_FINAL"
echo "=========================================="
