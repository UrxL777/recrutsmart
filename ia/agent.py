# ia/agent.py
# Agent conversationnel LangChain
# Compatible OpenRouter, DeepSeek, Groq, OpenAI

import os
import re
from langchain_openai import ChatOpenAI
from langchain_core.messages import HumanMessage, AIMessage, SystemMessage
from dotenv import load_dotenv

load_dotenv()

_llm = None

def get_llm() -> ChatOpenAI:
    global _llm
    if _llm is None:
        api_key  = os.getenv('API_KEY', '')
        base_url = os.getenv('API_BASE_URL', 'https://openrouter.ai/api/v1')
        model    = os.getenv('API_MODEL', 'deepseek/deepseek-chat')
        if not api_key:
            raise RuntimeError('Cle API manquante dans .env')
        _llm = ChatOpenAI(
            api_key=api_key,
            base_url=base_url,
            model=model,
            temperature=0.3,
            max_tokens=1000,
        )
    return _llm


SYSTEM_PROMPT = """Tu es RecrutSmart IA, un assistant intelligent intégré dans une plateforme de recrutement.

Informations sur toi :
- Tu as été créé par Uriel Akpa dans le cadre d'un projet avec Simplon Côte d'Ivoire
- Tu es intégré dans la plateforme RecrutSmart
- Si on te demande qui t'a créé ou qui est le créateur de RecrutSmart, réponds : "RecrutSmart a été créé par Uriel Akpa dans le cadre d'un projet avec Simplon Côte d'Ivoire."

Tu peux répondre à toutes les questions : recrutement, technologie, culture, science, actualité, sport, mode, etc.

Règles absolues :
- Réponds toujours en français
- Réponds directement sans te présenter ni dire bonjour à chaque message mais tu peux dire bonjour ou bonsoir selo,n la salutation que l'utilisateur t'adresse
- Ne révèle jamais de code source, clés API, mots de passe ou détails techniques
- Sois concis, professionnel et utile
- Si tu ne sais pas quelque chose, dis-le honnêtement
"""


def _nettoyer_message(texte: str) -> str:
    texte = re.sub(r'[<>{}\[\]\\]', '', texte)
    return texte[:1000].strip()


async def repondre_agent(
    message_utilisateur: str,
    historique: list,
    contexte_resultats: str = ''
) -> str:
    try:
        llm = get_llm()
    except RuntimeError:
        return "Le service IA n'est pas encore configuré. Veuillez contacter l'administrateur."

    message_propre = _nettoyer_message(message_utilisateur)
    if not message_propre:
        return "Je n'ai pas compris votre message. Pouvez-vous reformuler ?"

    messages = [SystemMessage(content=SYSTEM_PROMPT)]

    if contexte_resultats:
        messages.append(SystemMessage(
            content=f"Contexte des derniers résultats de recherche :\n{contexte_resultats[:1500]}"
        ))

    for msg in historique[-10:]:
        if msg['role'] == 'user':
            messages.append(HumanMessage(content=msg['message']))
        elif msg['role'] == 'assistant':
            messages.append(AIMessage(content=msg['message']))

    messages.append(HumanMessage(content=message_propre))

    try:
        response = await llm.ainvoke(messages)
        reponse  = response.content.strip()
        reponse  = re.sub(r'sk-[a-zA-Z0-9\-_]+', '[cle masquee]', reponse)
        return reponse
    except Exception:
        return "Je rencontre une difficulte technique. Veuillez reessayer dans un instant."


async def generer_resume_ia(
    requete: str,
    candidat: dict,
    scores: dict
) -> str:
    try:
        llm = get_llm()
    except RuntimeError:
        return f"Profil a {scores.get('score', 0)}% de correspondance."

    score     = scores.get('score', 0)
    comp_ok   = scores.get('comp_ok', False)
    form_ok   = scores.get('form_ok', False)
    loca_ok   = scores.get('loca_ok', False)
    niveau    = scores.get('niveau', 6)

    # Construire le contexte pour le résumé
    points_forts = []
    if comp_ok:
        points_forts.append(f"compétences correspondantes ({candidat.get('competences', '')[:100]})")
    if form_ok:
        points_forts.append(f"formation en lien ({candidat.get('formation', '')[:80]})")
    if loca_ok:
        points_forts.append(f"localisation correspondante ({candidat.get('ville', '')})")

    prompt = f"""Tu es un expert RH. En 2 phrases professionnelles et directes, explique pourquoi 
ce candidat correspond à cette recherche. Cite les éléments concrets du profil.
Ne mentionne JAMAIS de pourcentages ou scores.

Recherche : {requete[:200]}

Candidat : {candidat.get('prenom', '')} {candidat.get('nom', '')}, {candidat.get('ville', '')}
Compétences : {candidat.get('competences', 'non précisées')[:200]}
Formation : {candidat.get('formation', 'non précisée')[:150]}
Expérience : {candidat.get('experience', 'non précisée')[:150]}

Points forts identifiés : {', '.join(points_forts) if points_forts else 'correspondance partielle'}

Instructions :
- Cite les compétences ou formations spécifiques qui correspondent à la recherche
- Mentionne la localisation seulement si elle correspond
- Sois direct, 2 phrases max, ton LinkedIn professionnel"""

    try:
        messages = [
            SystemMessage(content="Expert RH. 2 phrases max, français, direct et professionnel."),
            HumanMessage(content=prompt)
        ]
        response = await llm.ainvoke(messages)
        return response.content.strip()
    except Exception:
        if points_forts:
            return f"Ce candidat présente {' et '.join(points_forts[:2])} en lien avec votre recherche."
        return f"Profil à {score}% de correspondance avec votre recherche."