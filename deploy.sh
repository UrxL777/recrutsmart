#!/bin/bash
cd /www/wwwroot/uriel.cvmatch.space

# Pull depuis GitHub
git fetch origin main
git reset --hard origin/main

# Corriger les identifiants DB (XAMPP → AAPanel)
sed -i "s/'root', '',/'recrutsmart', 'MpeLYtxdXAZNwCLn',/" config/db.php

# Redémarrer le microservice Python
pkill -f uvicorn
sleep 3
cd ia
nohup uvicorn app:app --host 127.0.0.1 --port 5000 > uvicorn.log 2>&1 &
sleep 2
curl -s http://127.0.0.1:5000/sante

echo "Deploiement termine."
