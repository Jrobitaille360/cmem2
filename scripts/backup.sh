#!/usr/bin/env bash
set -euo pipefail

# Parse command-line arguments in the format -KEY:VALUE
for arg in "$@"; do
    if [[ $arg == -* ]]; then
        key="${arg%%:*}"
        value="${arg#*:}"
        key="${key#-}"  # Remove leading -
        export "$key"="$value"
    fi
done


# Dossier source à sauvegarder
SOURCE_DIR="/home/lmdkhdg5/cmem2.journauxdebord.com"
# Dossier cible pour les archives chiffrées
BACKUP_DIR="${BACKUP_DIR:-/home/lmdkhdg5/backups}"
# Nommage des fichiers de backup
DATE="$(date +"%Y-%m-%d_%H-%M-%S")"
ZIP_NAME="cmem2_api_${DATE}.zip"
ENC_NAME="${ZIP_NAME}.enc"

# Exclusions (à ne pas inclure dans le ZIP)
EXCLUDES=(
	"${BACKUP_DIR}"
	"${SOURCE_DIR}/vendor"
	"${SOURCE_DIR}/.git"
	"${SOURCE_DIR}/.venv"
	"${SOURCE_DIR}/node_modules"
	"${SOURCE_DIR}/uploads/cache"
	"${SOURCE_DIR}/logs"
)

# Load environment variables from .env if present
ENV_FILE="${SOURCE_DIR}/.env"
if [ -f "${ENV_FILE}" ]; then
	set -a
	# shellcheck disable=SC1090
	. "${ENV_FILE}"
	set +a
else
	echo "Fichier .env introuvable: ${ENV_FILE}" >&2
fi

# Encryption passphrase must be provided via environment variable
: "${BACKUP_PASSPHRASE:?BACKUP_PASSPHRASE is required}"

# Mode déchiffrement (utilisation: ./backup.sh decrypt fichier.enc fichier.zip)
if [ "${1:-}" = "decrypt" ]; then
	ENC_FILE="${2:-${BACKUP_ENC_FILE:-}}"
	OUT_FILE="${3:-${BACKUP_OUT_FILE:-}}"
	: "${ENC_FILE:?Encrypted file path is required}"
	: "${OUT_FILE:?Output zip path is required}"
	openssl enc -d -aes-256-cbc -pbkdf2 \
		-in "${ENC_FILE}" \
		-out "${OUT_FILE}" \
		-pass env:BACKUP_PASSPHRASE
	exit 0
fi

# Prépare le dossier de backup
mkdir -p "${BACKUP_DIR}"

# Création du zip temporaire
TMP_ZIP="${BACKUP_DIR}/${ZIP_NAME}"
rm -f "${TMP_ZIP}"

# Dump MySQL optionnel (activé si MYSQL_DATABASE/DB_NAME est défini)
TMP_DIR="${SOURCE_DIR}/.backup_tmp"
MYSQL_DUMP_FILE="${TMP_DIR}/mysql_${DATE}.sql"
MYSQL_DATABASE="${MYSQL_DATABASE:-${DB_NAME:-}}"
MYSQL_USER="${MYSQL_USER:-${DB_USER:-${DB_USERNAME:-}}}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:-${DB_PASS:-${DB_PASSWORD:-}}}"
MYSQL_HOST="${MYSQL_HOST:-${DB_HOST:-localhost}}"
MYSQL_PORT="${MYSQL_PORT:-${DB_PORT:-3306}}"
if [ -n "${MYSQL_DATABASE:-}" ]; then
	: "${MYSQL_USER:?MYSQL_USER is required}"
	: "${MYSQL_PASSWORD:?MYSQL_PASSWORD is required}"
	DUMP_OPTIONS=("--single-transaction" "--routines" "--events" "--triggers")
	if [ "${MYSQL_DUMP_SKIP_ROUTINES:-}" = "1" ]; then
		DUMP_OPTIONS=("--single-transaction" "--skip-routines" "--skip-events" "--skip-triggers")
	fi
	mkdir -p "${TMP_DIR}"
	MYSQL_PWD="${MYSQL_PASSWORD}" mysqldump \
		--host="${MYSQL_HOST}" \
		--port="${MYSQL_PORT}" \
		--user="${MYSQL_USER}" \
		"${DUMP_OPTIONS[@]}" \
		"${MYSQL_DATABASE}" > "${MYSQL_DUMP_FILE}"
	if [ ! -s "${MYSQL_DUMP_FILE}" ]; then
		echo "MySQL dump failed or produced an empty file: ${MYSQL_DUMP_FILE}" >&2
		exit 1
	fi
fi

# Mode dump seul (utilisation: ./backup.sh dump)
if [ "${1:-}" = "dump" ]; then
	exit 0
fi

# Prépare les arguments d'exclusion pour zip
ZIP_EXCLUDE_ARGS=()
for ex in "${EXCLUDES[@]}"; do
	ZIP_EXCLUDE_ARGS+=("-x" "${ex}/*")
done


(
	# Zip du contenu source
	cd "${SOURCE_DIR}"
	zip -r "${TMP_ZIP}" . "${ZIP_EXCLUDE_ARGS[@]}"
)

# Nettoyage des fichiers temporaires
rm -rf "${TMP_DIR}"

# Chiffrement du zip avec OpenSSL (AES-256-CBC)
openssl enc -aes-256-cbc -pbkdf2 -salt \
	-in "${TMP_ZIP}" \
	-out "${BACKUP_DIR}/${ENC_NAME}" \
	-pass env:BACKUP_PASSPHRASE

# Supprime le zip en clair
rm -f "${TMP_ZIP}"

# Conserve uniquement les 7 derniers jours de backups chiffrés
find "${BACKUP_DIR}" -type f -name "*.enc" -mtime +7 -delete

echo "Backup terminé: ${BACKUP_DIR}/${ENC_NAME}"
