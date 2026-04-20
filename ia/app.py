# ia/app.py
# Serveur FastAPI — Point d'entrée du microservice IA
# Lancer avec : uvicorn app:app --reload --port 5000

import os
import re
from contextlib import asynccontextmanager
from fastapi import FastAPI, HTTPException, Security
from fastapi.middleware.cors import CORSMiddleware
from fastapi.security import APIKeyHeader
from pydantic import BaseModel, field_validator
from dotenv import load_dotenv

from database  import (
    get_pool, close_pool,
    get_tous_candidats, sauvegarder_analyse,
    sauvegarder_recherche, sauvegarder_resultats,
    get_historique_agent, sauvegarder_message_agent
)
from extractor import extraire_texte, nettoyer_texte
from ranker    import classer_candidats
from agent     import repondre_agent, generer_resume_ia

load_dotenv()

# ── Clé secrète PHP → Python ──────────────────────────────────────
API_SECRET     = os.getenv('API_SECRET', '')
api_key_header = APIKeyHeader(name='X-API-Key', auto_error=False)

def verifier_cle(cle: str = Security(api_key_header)) -> str:
    if not API_SECRET or cle != API_SECRET:
        raise HTTPException(status_code=401, detail='Non autorisé')
    return cle

# ── Dossier uploads ───────────────────────────────────────────────
UPLOADS_PATH = os.getenv('UPLOADS_PATH', 'C:/xampp/htdocs/recrutsmart/uploads')


# ── Lifecycle ─────────────────────────────────────────────────────
@asynccontextmanager
async def lifespan(app: FastAPI):
    await get_pool()
    yield
    await close_pool()


# ── Application FastAPI ───────────────────────────────────────────
app = FastAPI(
    title='RecrutSmart IA',
    version='1.0.0',
    docs_url=None,
    redoc_url=None,
    openapi_url=None,   # Désactiver openapi.json complètement
    lifespan=lifespan
)

# ── CORS strict ───────────────────────────────────────────────────
app.add_middleware(
    CORSMiddleware,
    allow_origins=['http://localhost', 'http://127.0.0.1'],
    allow_methods=['POST', 'GET'],
    allow_headers=['X-API-Key', 'Content-Type'],
)


# ================================================================
#  MODÈLES PYDANTIC avec validation stricte
# ================================================================

class RequeteRecherche(BaseModel):
    recruteur_id: int
    requete:      str

    @field_validator('recruteur_id')
    @classmethod
    def valider_id(cls, v):
        if v <= 0:
            raise ValueError('ID invalide')
        return v

    @field_validator('requete')
    @classmethod
    def valider_requete(cls, v):
        v = v.strip()
        if len(v) < 3:
            raise ValueError('Requête trop courte')
        if len(v) > 500:
            raise ValueError('Requête trop longue')
        # Bloquer les tentatives d'injection
        if re.search(r'[<>{}\[\]\\]', v):
            raise ValueError('Caractères non autorisés')
        return v


class MessageAgent(BaseModel):
    user_id:   int
    user_role: str
    message:   str
    contexte:  str = ''

    @field_validator('user_id')
    @classmethod
    def valider_user_id(cls, v):
        if v <= 0:
            raise ValueError('ID invalide')
        return v

    @field_validator('user_role')
    @classmethod
    def valider_role(cls, v):
        if v not in ('candidat', 'recruteur'):
            raise ValueError('Rôle invalide')
        return v

    @field_validator('message')
    @classmethod
    def valider_message(cls, v):
        v = v.strip()
        if not v:
            raise ValueError('Message vide')
        if len(v) > 1000:
            raise ValueError('Message trop long')
        return v

    @field_validator('contexte')
    @classmethod
    def valider_contexte(cls, v):
        return v[:2000] if v else ''


# ================================================================
#  ENDPOINTS
# ================================================================

@app.post('/analyser')
async def analyser_candidats(
    body: RequeteRecherche,
    _: str = Security(verifier_cle)
):
    # Récupérer tous les candidats avec CV
    candidats = await get_tous_candidats()
    if not candidats:
        return {'resultats': [], 'total': 0}

    # Extraire le texte de chaque CV
    candidats_avec_texte = []
    for candidat in candidats:
        # Sécuriser le nom de fichier — empêcher path traversal
        nom_fichier = os.path.basename(candidat['cv_fichier'])
        chemin      = os.path.join(UPLOADS_PATH, nom_fichier)

        texte = extraire_texte(chemin, candidat.get('cv_mime', ''))
        texte = nettoyer_texte(texte)
        candidat['texte_cv'] = texte

        if texte and not candidat.get('competences'):
            await sauvegarder_analyse(candidat['id'], {
                'competences': '', 'experience': '',
                'formation': '',  'resume': ''
            })

        candidats_avec_texte.append(candidat)

    # Classer les candidats
    resultats = classer_candidats(body.requete, candidats_avec_texte)

    # Aucune correspondance trouvée
    if not resultats:
        return {
            'resultats': [],
            'total':     0,
            'message':   'Aucune correspondance trouvée pour le profil recherché.'
        }

    # Générer les résumés IA pour les 10 premiers
    for r in resultats[:10]:
        candidat_info = next(
            (c for c in candidats if c['id'] == r['candidat_id']), {}
        )
        r['resume_ia'] = await generer_resume_ia(body.requete, candidat_info, r)

    # Sauvegarder
    recherche_id = await sauvegarder_recherche(body.recruteur_id, body.requete)
    await sauvegarder_resultats(recherche_id, resultats)

    resultats_propres = [
        {
            'candidat_id': r['candidat_id'],
            'nom':         r['nom'],
            'prenom':      r['prenom'],
            'ville':       r['ville'],
            'competences': r['competences'],
            'experience':  r['experience'],
            'formation':   r.get('formation', ''),
            'cv_fichier':  r.get('cv_fichier', ''),
            'score':       r['score'],
            'resume_ia':   r.get('resume_ia', ''),
            'comp_ok':     r.get('comp_ok', False),
            'form_ok':     r.get('form_ok', False),
            'loca_ok':     r.get('loca_ok', False),
        }
        for r in resultats
    ]

    return {
        'resultats': resultats_propres,
        'total':     len(resultats_propres),
        'message':   ''
    }


@app.post('/agent')
async def chat_agent(
    body: MessageAgent,
    _: str = Security(verifier_cle)
):
    historique = await get_historique_agent(body.user_id, body.user_role)

    reponse = await repondre_agent(
        message_utilisateur = body.message,
        historique          = historique,
        contexte_resultats  = body.contexte
    )

    await sauvegarder_message_agent(body.user_id, body.user_role, 'user',      body.message)
    await sauvegarder_message_agent(body.user_id, body.user_role, 'assistant', reponse)

    return {'reponse': reponse}


@app.get('/sante')
async def sante():
    return {'statut': 'ok'}