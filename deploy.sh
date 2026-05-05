#!/bin/bash
cd /www/wwwroot/uriel.cvmatch.space

# Pull depuis GitHub
git fetch origin main
git reset --hard origin/main

# Corriger les identifiants DB (XAMPP → AAPanel)
sed -i "s/'root', '',/'recrutsmart', 'MpeLYtxdXAZNwCLn',/" config/db.php

# Créer le fichier .env.php si absent (identifiants DB pour PHP)
if [ ! -f config/.env.php ]; then
    python3 -c "
with open('config/.env.php', 'w') as f:
    f.write('<?php\n')
    f.write(\"define('DB_HOST', 'localhost');\n\")
    f.write(\"define('DB_NAME', 'recrutsmart');\n\")
    f.write(\"define('DB_USER', 'recrutsmart');\n\")
    f.write(\"define('DB_PASS', 'MpeLYtxdXAZNwCLn');\n\")
"
fi

# Forcer le bon modèle IA sur AAPanel (remplace n'importe quel modèle)
sed -i 's|API_MODEL=.*|API_MODEL=openai/gpt-4o-mini|' ia/.env

# Redémarrer le microservice Python
pkill -f uvicorn
sleep 3
cd ia
nohup uvicorn app:app --host 127.0.0.1 --port 5000 > uvicorn.log 2>&1 &
sleep 2
curl -s http://127.0.0.1:5000/sante

echo "Deploiement termine."
