# ia/app.py
# Serveur FastAPI — Point d'entrée du microservice IA
# Lancer avec : uvicorn app:app --reload --port 5000

import os
import re
import asyncio
from contextlib import asynccontextmanager
from fastapi import FastAPI, HTTPException, Security, Request
from fastapi.responses import JSONResponse
from fastapi.middleware.cors import CORSMiddleware
from fastapi.security import APIKeyHeader
from pydantic import BaseModel, field_validator
from dotenv import load_dotenv

from database import (
    get_pool, close_pool,
    get_tous_candidats, get_candidat_par_id,
    sauvegarder_analyse, sauvegarder_texte_brut,
    sauvegarder_recherche, sauvegarder_resultats,
    get_historique_agent, sauvegarder_message_agent
)
from extractor     import extraire_texte, nettoyer_texte
from ranker        import classer_candidats
from agent         import repondre_agent
from analyser_cvs  import analyser_cv_unique

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
    version='2.0.0',
    docs_url=None,
    redoc_url=None,
    openapi_url=None,
    lifespan=lifespan
)

# ── CORS strict ───────────────────────────────────────────────────
app.add_middleware(
    CORSMiddleware,
    allow_origins=['http://localhost', 'http://127.0.0.1'],
    allow_methods=['POST', 'GET'],
    allow_headers=['X-API-Key', 'Content-Type'],
)

# ── Gestionnaire d'exceptions global ─────────────────────────────
# Aucune stacktrace ni détail technique n'est retourné au frontend
@app.exception_handler(Exception)
async def gestionnaire_erreur_global(request: Request, exc: Exception):
    import logging
    logging.error(f"[RecrutSmart] Erreur non gérée : {exc}", exc_info=True)
    return JSONResponse(
        status_code=500,
        content={'erreur': 'Une erreur interne est survenue. Veuillez réessayer.'}
    )

@app.exception_handler(HTTPException)
async def gestionnaire_http(request: Request, exc: HTTPException):
    return JSONResponse(
        status_code=exc.status_code,
        content={'erreur': exc.detail}
    )


# ================================================================
#  MODÈLES PYDANTIC
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
    def valider_requete_champ(cls, v):
        v = v.strip()
        if len(v) < 3:
            raise ValueError('Requête trop courte')
        if len(v) > 1000:
            raise ValueError('Requête trop longue')
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


class DemandeAnalyseCV(BaseModel):
    candidat_id: int
    cv_fichier:  str
    cv_mime:     str

    @field_validator('candidat_id')
    @classmethod
    def valider_candidat_id(cls, v):
        if v <= 0:
            raise ValueError('ID invalide')
        return v

    @field_validator('cv_fichier')
    @classmethod
    def valider_fichier(cls, v):
        v = v.strip()
        if not v:
            raise ValueError('Nom de fichier vide')
        # Sécurité : pas de traversée de répertoire
        if '..' in v or '/' in v or '\\' in v:
            raise ValueError('Nom de fichier invalide')
        return v


# ================================================================
#  ENDPOINTS
# ================================================================

@app.post('/analyser')
async def analyser_candidats(
    body: RequeteRecherche,
    _: str = Security(verifier_cle)
):
    """
    Recherche de candidats par requête en langage naturel.
    Python pré-filtre → LLM évalue le top → résultats triés par score.
    Timeout effectif : géré côté PHP (300s dans ia-proxy.php).
    """
    # Récupérer tous les candidats avec leurs données complètes
    candidats = await get_tous_candidats()
    if not candidats:
        return {
            'exacts': [], 'partiels': [], 'similaires': [],
            'total': 0, 'message': 'Aucun candidat disponible.',
            'composantes': {}
        }

    # Pour les candidats sans texte brut stocké, l'extraire à la volée
    for candidat in candidats:
        if not candidat.get('texte_complet') and candidat.get('cv_fichier'):
            nom_fichier = os.path.basename(candidat['cv_fichier'])
            chemin      = os.path.join(UPLOADS_PATH, nom_fichier)
            texte       = extraire_texte(chemin, candidat.get('cv_mime', ''))
            texte       = nettoyer_texte(texte)
            candidat['texte_complet'] = texte
            # Stocker pour les prochaines fois
            if texte:
                await sauvegarder_texte_brut(candidat['id'], texte)

    # Classer les candidats
    niveaux = await classer_candidats(body.requete, candidats)

    if not niveaux.get('valide', True):
        return {
            'exacts': [], 'partiels': [], 'similaires': [],
            'total': 0,
            'message': f'Aucune correspondance trouvée pour : {body.requete}.',
            'composantes': {}
        }

    exacts     = niveaux['exacts']
    partiels   = niveaux['partiels']
    similaires = niveaux['similaires']
    total      = len(exacts) + len(partiels) + len(similaires)

    if total == 0:
        return {
            'exacts': [], 'partiels': [], 'similaires': [],
            'total': 0,
            'message': f'Aucune correspondance trouvée pour : {body.requete}.',
            'composantes': {}
        }

    # Sauvegarder les résultats
    tous = exacts + partiels + similaires
    recherche_id = await sauvegarder_recherche(body.recruteur_id, body.requete)
    await sauvegarder_resultats(recherche_id, tous)

    def formater(liste):
        return [
            {
                'candidat_id':       r['candidat_id'],
                'nom':               r['nom'],
                'prenom':            r['prenom'],
                'ville':             r['ville'],
                'competences':       r['competences'],
                'formation':         r.get('formation',          ''),
                'experience':        r.get('experience',         ''),
                'langues':           r.get('langues',            ''),
                'localisation':      r.get('localisation',       ''),
                'annees_experience': r.get('annees_experience',   0),
                'cv_fichier':        r.get('cv_fichier',         ''),
                'score':             r['score'],
                'resume_ia':         r.get('resume_ia',          ''),
                'points_forts':      r.get('points_forts',       ''),
                'points_faibles':    r.get('points_faibles',     ''),
                'niveau_experience': r.get('niveau_experience',  ''),
                'recommandation':    r.get('recommandation',     ''),
            }
            for r in liste
        ]

    return {
        'exacts':      formater(exacts),
        'partiels':    formater(partiels),
        'similaires':  formater(similaires),
        'total':       total,
        'message':     '',
        'composantes': {}
    }


@app.post('/analyser-cv')
async def analyser_cv_apres_upload(
    body: DemandeAnalyseCV,
    _: str = Security(verifier_cle)
):
    """
    Analyse automatique d'un CV juste après son upload.
    Appelé par upload-cv.php en arrière-plan.
    Extrait tout le texte + remplit toutes les colonnes.
    """
    try:
        succes = await analyser_cv_unique(
            candidat_id=body.candidat_id,
            cv_fichier=body.cv_fichier,
            cv_mime=body.cv_mime
        )
        return {'statut': 'ok' if succes else 'erreur'}
    except Exception as e:
        return {'statut': 'erreur', 'detail': str(e)}


@app.post('/agent')
async def chat_agent(
    body: MessageAgent,
    _: str = Security(verifier_cle)
):
    """Agent conversationnel pour recruteurs et candidats."""
    historique = await get_historique_agent(body.user_id, body.user_role)

    reponse = await repondre_agent(
        message_utilisateur=body.message,
        historique=historique,
        contexte_resultats=body.contexte
    )

    await sauvegarder_message_agent(body.user_id, body.user_role, 'user',      body.message)
    await sauvegarder_message_agent(body.user_id, body.user_role, 'assistant', reponse)

    return {'reponse': reponse}


@app.get('/sante')
async def sante():
    """Health check — utilisé par l'indicateur vert/rouge du dashboard."""
    return {'statut': 'ok'}
