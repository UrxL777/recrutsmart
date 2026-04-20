# ia/database.py
# Connexion MySQL asynchrone avec aiomysql

import aiomysql #bibliothèque asynchrone pour parler à MySQL
import os # pour lire les variables d'environnement
from dotenv import load_dotenv #pour charger les variables d'environnement depuis un fichier .env

load_dotenv() # Charger les variables d'environnement depuis .env

# ── Pool de connexions global ─────────────────────────────────────
_pool = None

async def get_pool(): 
    """Retourne le pool de connexions, le crée si nécessaire."""
    global _pool
    if _pool is None:
        _pool = await aiomysql.create_pool(
            host=os.getenv('DB_HOST', 'localhost'),
            port=3306,
            user=os.getenv('DB_USER', 'root'),
            password=os.getenv('DB_PASS', ''),
            db=os.getenv('DB_NAME', 'recrutsmart'),
            charset='utf8mb4',
            autocommit=True,
            minsize=1,
            maxsize=10
        )
    return _pool


async def close_pool():
    """Ferme le pool proprement à l'arrêt du serveur."""
    global _pool
    if _pool:
        _pool.close()
        await _pool.wait_closed()
        _pool = None


async def get_tous_candidats() -> list:
    """
    Récupère tous les candidats ayant un CV uploadé.
    Retourne une liste de dicts.
    """
    pool = await get_pool()
    async with pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute("""
                SELECT
                    c.id,
                    c.nom,
                    c.prenom,
                    c.ville,
                    c.email,
                    c.telephone,
                    c.cv_fichier,
                    c.cv_mime,
                    c.cv_date,
                    COALESCE(a.competences, '') AS competences,
                    COALESCE(a.experience,  '') AS experience,
                    COALESCE(a.formation,   '') AS formation,
                    COALESCE(a.resume,      '') AS resume_cv
                FROM candidats c
                LEFT JOIN cv_analyses a ON a.candidat_id = c.id
                WHERE c.actif = 1
                AND   c.cv_fichier IS NOT NULL
                ORDER BY c.cree_le DESC
            """)
            return await cur.fetchall()


async def sauvegarder_analyse(candidat_id: int, analyse: dict) -> None:
    """
    Sauvegarde ou met à jour l'analyse IA d'un CV en base.
    """
    pool = await get_pool()
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute("""
                INSERT INTO cv_analyses
                    (candidat_id, competences, experience, formation, resume)
                VALUES (%s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    competences = VALUES(competences),
                    experience  = VALUES(experience),
                    formation   = VALUES(formation),
                    resume      = VALUES(resume),
                    analyse_le  = NOW()
            """, (
                candidat_id,
                analyse.get('competences', ''),
                analyse.get('experience',  ''),
                analyse.get('formation',   ''),
                analyse.get('resume',      '')
            ))


async def sauvegarder_recherche(recruteur_id: int, requete: str) -> int:
    """
    Sauvegarde la requête du recruteur et retourne son ID.
    """
    pool = await get_pool()
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute("""
                INSERT INTO recherches (recruteur_id, requete)
                VALUES (%s, %s)
            """, (recruteur_id, requete))
            return cur.lastrowid


async def sauvegarder_resultats(recherche_id: int, resultats: list) -> None:
    """
    Sauvegarde les résultats de matching en base.
    resultats : liste de dicts avec candidat_id, score, resume_ia
    """
    if not resultats:
        return
    pool = await get_pool()
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.executemany("""
                INSERT INTO resultats_matching
                    (recherche_id, candidat_id, score, resume_ia)
                VALUES (%s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    score     = VALUES(score),
                    resume_ia = VALUES(resume_ia)
            """, [
                (recherche_id, r['candidat_id'], r['score'], r.get('resume_ia', ''))
                for r in resultats
            ])


async def get_historique_agent(user_id: int, user_role: str, limite: int = 20) -> list:
    """
    Récupère l'historique de conversation de l'agent IA.
    """
    pool = await get_pool()
    async with pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute("""
                SELECT role, message
                FROM conversations_agent
                WHERE user_id   = %s
                AND   user_role = %s
                ORDER BY cree_le DESC
                LIMIT %s
            """, (user_id, user_role, limite))
            rows = await cur.fetchall()
            # Inverser pour avoir l'ordre chronologique
            return list(reversed(rows))


async def sauvegarder_message_agent(
    user_id: int, user_role: str, role: str, message: str
) -> None:
    """
    Sauvegarde un message dans l'historique de l'agent.
    role : 'user' ou 'assistant'
    """
    pool = await get_pool()
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute("""
                INSERT INTO conversations_agent
                    (user_id, user_role, role, message)
                VALUES (%s, %s, %s, %s)
            """, (user_id, user_role, role, message))