#!/bin/bash
PROGRESS_FILE=/tmp/jeedom/geelyconnect/dependancy
if [ ! -z "$1" ]; then
    PROGRESS_FILE="$1"
fi

touch "${PROGRESS_FILE}"
echo 0 > "${PROGRESS_FILE}"
echo "********************************************************"
echo "*       Installing Geely Connect dependencies          *"
echo "********************************************************"

sudo apt-get update
echo 25 > "${PROGRESS_FILE}"

sudo apt-get install -y python3-venv python3-pip
echo 50 > "${PROGRESS_FILE}"

BASEDIR=$(cd "$(dirname "$0")" && pwd)
VENV_DIR="${BASEDIR}/venv"

rm -rf "${VENV_DIR}"
python3 -m venv "${VENV_DIR}"
echo 65 > "${PROGRESS_FILE}"

"${VENV_DIR}/bin/pip" install --no-cache-dir --upgrade pip
echo 80 > "${PROGRESS_FILE}"

"${VENV_DIR}/bin/pip" install --no-cache-dir "requests>=2.31.0"
echo 90 > "${PROGRESS_FILE}"

# Install prebuilt wheel if available; never stall compiling C extensions on ARM.
"${VENV_DIR}/bin/pip" install --no-cache-dir --only-binary=:all: "pycryptodomex>=3.19.0" || true
chown -R www-data:www-data "${VENV_DIR}"
echo 100 > "${PROGRESS_FILE}"

echo "********************************************************"
echo "*       Dependencies installed successfully            *"
echo "********************************************************"
rm -f "${PROGRESS_FILE}"
