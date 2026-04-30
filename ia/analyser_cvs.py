# ia/analyser_cvs.py
# Script batch pour analyser tous les CV existants
# et remplir cv_analyses + cv_texte_brut
# Lancer avec : python analyser_cvs.py

import asyncio
import os
import json
from dotenv import load_dotenv

load_dotenv()

from database  import (
    get_pool, close_pool,
    get_tous_candidats,
    sauvegarder_analyse,
    sauvegarder_texte_brut
)
from extractor import extraire_texte, nettoyer_texte

UPLOADS_PATH = os.getenv('UPLOADS_PATH', 'C:/xampp/htdocs/recrutsmart/uploads')


async def analyser_un_cv(candidat: dict, llm) -> tuple[dict, str]:
    """
    Extrait le texte complet du CV et demande au LLM d'en extraire
    TOUTES les informations structurées sans limite.

    Retourne : (analyse_dict, texte_brut)
    """
    from langchain_core.messages import HumanMessage, SystemMessage

    chemin = os.path.join(UPLOADS_PATH, os.path.basename(candidat['cv_fichier']))
    texte  = extraire_texte(chemin, candidat.get('cv_mime', ''))
    texte  = nettoyer_texte(texte)

    if not texte or len(texte) < 30:
        return {
            'competences':         '',
            'experience':          '',
            'formation':           '',
            'resume':              'CV vide ou illisible',
            'langues':             '',
            'localisation':        '',
            'disponibilite':       '',
            'situation_familiale': '',
            'annees_experience':   0,
            'surplus_info':        '',
        }, ''

    prompt = f"""Tu es un expert en extraction d'informations depuis des CV.
Analyse ce CV en intégralité et extrais TOUTES les informations présentes.
Ne jamais inventer une information absente du CV.
Si une information n'est pas présente, laisse le champ vide ("") ou 0 pour les entiers.

CV COMPLET :
{texte}

Retourne UNIQUEMENT ce JSON valide, sans markdown ni backticks :
{{
    "competences": "toutes les compétences techniques et professionnelles séparées par des virgules",
    "experience": "résumé de l'expérience professionnelle avec postes, entreprises et durées",
    "formation": "tous les diplômes, formations et certifications obtenus",
    "resume": "résumé global du profil en 2-3 phrases",
    "langues": "toutes les langues parlées séparées par des virgules (ex: Français, Anglais, Baoulé, Dioula, Allemand)",
    "localisation": "ville, pays, région, mobilité géographique mentionnés dans le CV",
    "disponibilite": "disponibilité mentionnée (ex: immédiate, 1 mois de préavis, à partir de janvier 2025)",
    "situation_familiale": "situation familiale et personnelle complète (ex: marié, 2 enfants, célibataire, permis B, date de naissance si présente)",
    "annees_experience": <nombre entier d'années d'expérience totale, 0 si non précisé>,
    "surplus_info": "TOUTES les autres informations présentes dans le CV sans exception : loisirs, sports, centres d'intérêt, pays visités, qualités personnelles, références, associations, bénévolat, distinctions, publications, projets personnels, préférences alimentaires si mentionnées, croyances religieuses si mentionnées, et absolument toute autre information présente dans le CV"
}}"""

    try:
        response = await llm.ainvoke([
            SystemMessage(content="Tu es un extracteur de CV expert. Réponds uniquement en JSON valide. N'invente rien."),
            HumanMessage(content=prompt)
        ])
        contenu = response.content.strip()
        contenu = contenu.replace('```json', '').replace('```', '').strip()
        data = json.loads(contenu)
        return {
            'competences':         str(data.get('competences',         '')),
            'experience':          str(data.get('experience',          '')),
            'formation':           str(data.get('formation',           '')),
            'resume':              str(data.get('resume',              '')),
            'langues':             str(data.get('langues',             '')),
            'localisation':        str(data.get('localisation',        '')),
            'disponibilite':       str(data.get('disponibilite',       '')),
            'situation_familiale': str(data.get('situation_familiale', '')),
            'annees_experience':   int(data.get('annees_experience',    0)),
            'surplus_info':        str(data.get('surplus_info',        '')),
        }, texte
    except Exception as e:
        print(f"  Erreur LLM {candidat.get('prenom','')} {candidat.get('nom','')} : {e}")
        return {
            'competences': '', 'experience': '', 'formation': '', 'resume': '',
            'langues': '', 'localisation': '', 'disponibilite': '',
            'situation_familiale': '', 'annees_experience': 0, 'surplus_info': '',
        }, texte


async def analyser_cv_unique(candidat_id: int, cv_fichier: str, cv_mime: str) -> bool:
    """
    Analyse un seul CV immédiatement après son upload.
    Appelé depuis app.py via l'endpoint /analyser-cv.
    Retourne True si succès, False sinon.
    """
    from langchain_openai import ChatOpenAI

    api_key  = os.getenv('API_KEY', '')
    base_url = os.getenv('API_BASE_URL', 'https://openrouter.ai/api/v1')
    model    = os.getenv('API_MODEL', 'deepseek/deepseek-chat')

    if not api_key:
        print("ERREUR : API_KEY manquante dans .env")
        return False

    llm = ChatOpenAI(
        api_key=api_key,
        base_url=base_url,
        model=model,
        temperature=0.1,
        max_tokens=1500,
    )

    candidat = {
        'id':        candidat_id,
        'cv_fichier': cv_fichier,
        'cv_mime':    cv_mime,
        'prenom':    '',
        'nom':       '',
    }

    analyse, texte_brut = await analyser_un_cv(candidat, llm)
    await sauvegarder_analyse(candidat_id, analyse)
    if texte_brut:
        await sauvegarder_texte_brut(candidat_id, texte_brut)
    return True


async def main():
    """Lance l'analyse batch de tous les CV non encore analysés."""
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
        max_tokens=1500,
    )

    print("Connexion a la base de donnees...")
    await get_pool()

    print("Recuperation des candidats...")
    candidats = await get_tous_candidats()

    # Analyser ceux qui n'ont pas encore de texte brut extrait
    a_analyser = [c for c in candidats if not c.get('texte_complet')]
    deja_faits = len(candidats) - len(a_analyser)

    print(f"Total    : {len(candidats)} candidats")
    print(f"Deja OK  : {deja_faits}")
    print(f"A traiter: {len(a_analyser)}")
    print("-" * 40)

    succes = 0
    erreurs = 0

    for i, candidat in enumerate(a_analyser, 1):
        print(f"[{i}/{len(a_analyser)}] {candidat['prenom']} {candidat['nom']}...", end=' ', flush=True)

        analyse, texte_brut = await analyser_un_cv(candidat, llm)
        await sauvegarder_analyse(candidat['id'], analyse)
        if texte_brut:
            await sauvegarder_texte_brut(candidat['id'], texte_brut)

        if analyse.get('competences') or analyse.get('surplus_info') or texte_brut:
            print("OK")
            succes += 1
        else:
            print("Vide ou illisible")
            erreurs += 1

        await asyncio.sleep(0.5)

    print("-" * 40)
    print(f"Termine : {succes} succes, {erreurs} echecs")
    await close_pool()


if __name__ == '__main__':
    asyncio.run(main())
