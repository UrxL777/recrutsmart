# ia/analyser_cvs.py
# Script à lancer UNE FOIS pour analyser tous les CV existants
# et remplir la table cv_analyses
# Lancer avec : python analyser_cvs.py

import asyncio
import os
import json
from dotenv import load_dotenv

load_dotenv()

from database  import get_pool, close_pool, get_tous_candidats, sauvegarder_analyse
from extractor import extraire_texte, nettoyer_texte

UPLOADS_PATH = os.getenv('UPLOADS_PATH', 'C:/xampp/htdocs/recrutsmart/uploads')


async def analyser_un_cv(candidat: dict, llm) -> dict:
    """Analyse un CV et extrait compétences, formation, expérience."""
    from langchain_core.messages import HumanMessage, SystemMessage

    chemin = os.path.join(UPLOADS_PATH, os.path.basename(candidat['cv_fichier']))
    texte  = extraire_texte(chemin, candidat.get('cv_mime', ''))
    texte  = nettoyer_texte(texte)

    if not texte or len(texte) < 30:
        return {
            'competences': '',
            'experience':  '',
            'formation':   '',
            'resume':      'CV vide ou illisible'
        }

    prompt = f"""Analyse ce CV et extrais les informations suivantes.
Retourne UNIQUEMENT un JSON valide sans markdown ni backticks.

CV :
{texte[:4000]}

JSON attendu :
{{
    "competences": "liste des competences techniques separees par des virgules",
    "experience": "resume de l experience professionnelle en 1-2 phrases avec les annees",
    "formation": "diplomes et formations obtenus",
    "resume": "resume global du profil en 2-3 phrases"
}}"""

    try:
        response = await llm.ainvoke([
            SystemMessage(content="Extracteur de CV. Reponds uniquement en JSON valide."),
            HumanMessage(content=prompt)
        ])
        contenu = response.content.strip()
        contenu = contenu.replace('```json', '').replace('```', '').strip()
        return json.loads(contenu)
    except Exception as e:
        print(f"  Erreur analyse {candidat['prenom']} {candidat['nom']} : {e}")
        return {
            'competences': '',
            'experience':  '',
            'formation':   '',
            'resume':      ''
        }


async def main():
    from langchain_openai import ChatOpenAI

    api_key  = os.getenv('API_KEY', '')
    base_url = os.getenv('API_BASE_URL', 'https://openrouter.ai/api/v1')
    model    = os.getenv('API_MODEL', 'deepseek/deepseek-chat')

    if not api_key:
        print("ERREUR : API_KEY manquante dans .env")
        return

    llm = ChatOpenAI(
        api_key=api_key,
        base_url=base_url,
        model=model,
        temperature=0.1,
        max_tokens=600,
    )

    print("Connexion a la base de donnees...")
    await get_pool()

    print("Recuperation des candidats...")
    candidats = await get_tous_candidats()

    # Filtrer ceux qui n'ont pas encore d'analyse
    a_analyser = [c for c in candidats if not c.get('competences')]
    deja_faits = len(candidats) - len(a_analyser)

    print(f"Total : {len(candidats)} candidats")
    print(f"Deja analyses : {deja_faits}")
    print(f"A analyser : {len(a_analyser)}")
    print("-" * 40)

    succes = 0
    erreurs = 0

    for i, candidat in enumerate(a_analyser, 1):
        print(f"[{i}/{len(a_analyser)}] {candidat['prenom']} {candidat['nom']}...", end=' ')

        analyse = await analyser_un_cv(candidat, llm)
        await sauvegarder_analyse(candidat['id'], analyse)

        if analyse.get('competences') or analyse.get('formation'):
            print(f"OK - {analyse.get('competences', '')[:50]}...")
            succes += 1
        else:
            print("Vide")
            erreurs += 1

        # Pause pour ne pas surcharger l'API
        await asyncio.sleep(0.5)

    print("-" * 40)
    print(f"Analyses terminees : {succes} succes, {erreurs} echecs")
    await close_pool()


if __name__ == '__main__':
    asyncio.run(main())