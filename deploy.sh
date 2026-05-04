#!/bin/bash
cd /www/wwwroot/uriel.cvmatch.space

# Pull depuis GitHub
git fetch origin main
git reset --hard origin/main

# Créer le fichier de variables d'environnement PHP pour AAPanel
# Ce fichier n'est pas dans le repo — il reste sur le serveur
if [ ! -f config/.env.php ]; then
    cat > config/.env.php << 'ENVEOF'
<?php
putenv('DB_HOST=localhost');
putenv('DB_NAME=recrutsmart');
putenv('DB_USER=recrutsmart');
putenv('DB_PASS=MpeLYtxdXAZNwCLn');
ENVEOF
fi

# Redémarrer le microservice Python
pkill -f uvicorn
sleep 3
cd ia
nohup uvicorn app:app --host 127.0.0.1 --port 5000 > uvicorn.log 2>&1 &
sleep 2
curl -s http://127.0.0.1:5000/sante

echo "Deploiement termine."
