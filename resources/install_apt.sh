#!/bin/bash
PROGRESS_FILE=/tmp/jeedom/geelyconnect/dependancy
if [ ! -z $1 ]; then
    PROGRESS_FILE=$1
fi

touch ${PROGRESS_FILE}
echo 0 > ${PROGRESS_FILE}
echo "********************************************************"
echo "*       Installing Geely Connect dependencies          *"
echo "********************************************************"

sudo apt-get update
echo 25 > ${PROGRESS_FILE}

sudo apt-get install -y python3-venv python3-pip
echo 50 > ${PROGRESS_FILE}

BASEDIR=$(dirname "$0")
VENV_DIR="${BASEDIR}/venv"

rm -rf "${VENV_DIR}"
python3 -m venv "${VENV_DIR}"
echo 70 > ${PROGRESS_FILE}

"${VENV_DIR}/bin/pip" install --upgrade pip
echo 85 > ${PROGRESS_FILE}

"${VENV_DIR}/bin/pip" install "requests>=2.31.0" "pycryptodomex>=3.19.0"
echo 100 > ${PROGRESS_FILE}

echo "********************************************************"
echo "*       Dependencies installed successfully            *"
echo "********************************************************"
rm -f ${PROGRESS_FILE}
