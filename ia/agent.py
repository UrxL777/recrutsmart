# ia/agent.py
# Agent conversationnel + évaluation complète des candidats
# Le LLM reçoit TOUTES les informations du candidat et décide seul

import os
import re
import json
from langchain_openai import ChatOpenAI
from langchain_core.messages import HumanMessage, AIMessage, SystemMessage
from dotenv import load_dotenv

load_dotenv()

_llm = None

def get_llm(temperature: float = 0.3, max_tokens: int = 1000) -> ChatOpenAI:
    global _llm
    if _llm is None:
        api_key  = os.getenv('API_KEY', '')
        base_url = os.getenv('API_BASE_URL', 'https://openrouter.ai/api/v1')
        model    = os.getenv('API_MODEL', 'deepseek/deepseek-chat')
        if not api_key:
            raise RuntimeError('Clé API manquante dans .env')
        _llm = ChatOpenAI(
            api_key=api_key,
            base_url=base_url,
            model=model,
            temperature=temperature,
            max_tokens=max_tokens,
        )
    return _llm


# ─────────────────────────────────────────────────────────────────
# Prompt système d'évaluation — adapté aux nouvelles colonnes
# ─────────────────────────────────────────────────────────────────
GROQ_SYSTEM_PROMPT = """Tu es un expert senior en recrutement avec 15 ans d'expérience. Tu évalues si un candidat correspond à une recherche.

Tu reçois :
- La REQUÊTE du recruteur en langage naturel
- Le PROFIL complet du candidat :
    * competences, formation, experience, annees_experience
    * langues, localisation, disponibilite, situation_familiale
    * surplus_info — loisirs, religion, préférences, valeurs, tottems, pays visités, qualités, etc.
    * texte_complet — texte brut intégral du CV (source de vérité absolue)

========================================
ÉTAPE 1 — IDENTIFIER TOUS LES CRITÈRES
========================================
Lis la requête et identifie TOUS les critères demandés :
- Critères professionnels : métier, compétences, formation, expérience, localisation, langues
- Critères personnels : situation familiale, religion, loisirs, valeurs, tottems, préférences alimentaires, traits de caractère, etc.
Traite TOUS les critères avec le même sérieux, qu'ils soient professionnels ou personnels.

========================================
ÉTAPE 2 — CHERCHER DANS LE PROFIL
========================================
Pour chaque critère identifié, cherche dans TOUT le profil :
- D'abord dans les colonnes structurées (competences, langues, localisation, etc.)
- Ensuite dans surplus_info
- Enfin dans texte_complet

INFÉRENCE SÉMANTIQUE OBLIGATOIRE :
Raisonne comme un humain intelligent. Fais des inférences logiques :
- "n'aime pas l'indiscipline" → cherche "aime la discipline" → CORRESPONDANCE
- "ne mange pas de porc" → cherche religion juive ou musulmane → CORRESPONDANCE
- "travailleur" → cherche "rigoureux", "sérieux", "consciencieux" → CORRESPONDANCE
- "dynamique" → cherche "proactif", "énergique", "motivé" → CORRESPONDANCE
Toujours chercher l'équivalent sémantique, le synonyme ou l'implication logique.

========================================
ÉTAPE 3 — SCORING
========================================
- Tous les critères demandés présents → score 80-100
- La plupart des critères présents → score 60-79
- Certains critères présents → score 35-59
- Peu de critères présents → score 15-34
- Aucun critère présent → score 0-14

Règles importantes :
- Si un critère est satisfait par inférence logique → score positif, jamais 0
- Localisation différente → -20 points
- Langue demandée absente → -15 points par langue
- Expérience insuffisante (écart > 3 ans) → -20 points
- Genre non correspondant (si demandé) → score plafonné à 20

========================================
ÉTAPE 4 — RECOMMANDATION
========================================
- Score ≥ 75 → "fort recommandé"
- Score 50-74 → "recommandé"
- Score 25-49 → "à considérer"
- Score < 25 → "non recommandé"

Retourne UNIQUEMENT ce JSON valide :
{
    "score": <entier 0-100>,
    "justification": "Ce candidat correspond à votre requête car <explication précise des critères trouvés>",
    "points_forts": "<critères satisfaits>",
    "points_faibles": "<critères manquants>",
    "competences_detectees": "<compétences identifiées>",
    "niveau_experience": "<junior|confirmé|senior|expert>",
    "recommandation": "<fort recommandé|recommandé|à considérer|non recommandé>"
}

RÈGLES ABSOLUES :
- La justification DOIT commencer par "Ce candidat correspond à votre requête car"
- Ne JAMAIS inventer des informations absentes du profil
- Chercher dans texte_complet avant de conclure qu'une info est absente
- Aucun commentaire avant ou après le JSON
""".strip()


# ─────────────────────────────────────────────────────────────────
# 1. Validation de la requête
# ─────────────────────────────────────────────────────────────────
async def reformuler_requete(requete: str) -> str:
    """
    Reformule la requête pour clarifier les critères implicites ou négatifs.
    Ex: "quelqu'un qui n'aime pas l'indiscipline" → "quelqu'un qui aime la discipline"
    Ex: "ne mange pas de porc" → "pratique une religion qui interdit le porc ou mentionne cette préférence"
    Retourne la requête reformulée ou la requête originale si pas de reformulation nécessaire.
    """
    try:
        llm = get_llm(temperature=0.0, max_tokens=300)
    except RuntimeError:
        return requete

    prompt = f"""Tu es un expert en analyse de requêtes de recrutement.
Reformule cette requête pour la rendre plus explicite et positive, en conservant TOUS les critères.
Transforme les formulations négatives en formulations positives équivalentes.
Développe les implications logiques des critères.

Exemples :
- "n'aime pas l'indiscipline" → "aime la discipline et la rigueur"
- "ne mange pas de porc" → "pratique une religion ou a des préférences alimentaires excluant le porc (Islam, Judaïsme, ou végétarien)"
- "quelqu'un de sérieux" → "quelqu'un de rigoureux, consciencieux et fiable"

Requête originale : "{requete}"

Retourne UNIQUEMENT la requête reformulée, sans explication ni commentaire."""

    try:
        response = await llm.ainvoke([
            SystemMessage(content="Tu reformules des requêtes. Réponds uniquement avec la requête reformulée."),
            HumanMessage(content=prompt)
        ])
        reformulee = response.content.strip()
        # Si la reformulation est trop longue ou vide, garder l'originale
        if not reformulee or len(reformulee) > 500:
            return requete
        return reformulee
    except Exception:
        return requete


async def valider_requete(requete: str) -> tuple[bool, str]:
    """
    Vérifie que la requête contient au moins un critère de recherche.
    Retourne (valide, raison).
    """
    try:
        llm = get_llm(temperature=0.0, max_tokens=200)
    except RuntimeError:
        return False, 'LLM non configuré'

    prompt = f"""Une requête de recrutement est VALIDE si elle contient au moins un critère
permettant de chercher un profil parmi des candidats.
Elle peut contenir n'importe quel type de critère : métier, compétence, formation,
langue, localisation, situation familiale, traits de personnalité, loisirs,
valeurs, préférences, religion, nationalité, ou toute autre caractéristique.
Elle est INVALIDE UNIQUEMENT si elle est totalement vide de sens ou incompréhensible.

Requête : "{requete}"

Retourne UNIQUEMENT ce JSON :
{{"valide": true ou false, "raison": "explication courte"}}"""

    try:
        response = await llm.ainvoke([
            SystemMessage(content="Réponds uniquement en JSON valide."),
            HumanMessage(content=prompt)
        ])
        contenu = response.content.strip().replace('```json','').replace('```','').strip()
        data = json.loads(contenu)
        return bool(data.get('valide', False)), str(data.get('raison', ''))
    except Exception as e:
        return False, f"Erreur : {e}"


# ─────────────────────────────────────────────────────────────────
# 2. Évaluation complète d'un candidat
# ─────────────────────────────────────────────────────────────────
async def evaluer_candidat_par_llm(requete: str, candidat: dict) -> dict:
    """
    Le LLM reçoit la requête complète + TOUTES les informations du candidat
    organisées par catégories. Il évalue et retourne score + justification.
    """
    try:
        llm = get_llm(temperature=0.0, max_tokens=800)
    except RuntimeError:
        return {"score": 0, "justification": "LLM non configuré", "recommandation": "non recommandé"}

    # Construire le profil complet structuré
    profil = f"""- Compétences          : {candidat.get('competences',         'non renseigné')}
- Formation            : {candidat.get('formation',           'non renseigné')}
- Expérience           : {candidat.get('experience',          'non renseigné')}
- Années d'expérience  : {candidat.get('annees_experience',    0)}
- Langues              : {candidat.get('langues',             'non renseigné')}
- Localisation         : {candidat.get('localisation',        '') or candidat.get('ville', 'non renseigné')}
- Disponibilité        : {candidat.get('disponibilite',       'non renseigné')}
- Situation personnelle: {candidat.get('situation_familiale', 'non renseigné')}
- Autres informations  : {candidat.get('surplus_info',        'non renseigné')}

Texte intégral du CV :
{candidat.get('texte_complet', 'non disponible')}"""

    try:
        response = await llm.ainvoke([
            SystemMessage(content=GROQ_SYSTEM_PROMPT),
            HumanMessage(content=f'''REQUÊTE : "{requete}"

RAPPEL IMPORTANT : Fais des inférences sémantiques.
Exemple : si la requête dit "n'aime pas l'indiscipline" et que le profil dit "aime la discipline" → c'est une correspondance directe, score élevé.
Exemple : si la requête dit "a fréquenté l'université X" et que la formation mentionne cette université → correspondance parfaite.
Cherche dans TOUTES les sections du profil, notamment "Autres informations" et "Texte intégral du CV".

PROFIL CANDIDAT :
{profil}''')
        ])
        contenu = response.content.strip().replace('```json','').replace('```','').strip()
        data = json.loads(contenu)
        return {
            "score":                max(0, min(100, int(data.get("score", 0)))),
            "justification":        str(data.get("justification",        "")),
            "points_forts":         str(data.get("points_forts",         "")),
            "points_faibles":       str(data.get("points_faibles",       "")),
            "competences_detectees":str(data.get("competences_detectees","")),
            "niveau_experience":    str(data.get("niveau_experience",    "")),
            "recommandation":       str(data.get("recommandation",       "non recommandé")),
        }
    except Exception as e:
        return {
            "score": 0,
            "justification": f"Erreur technique : {e}",
            "recommandation": "non recommandé"
        }


# ─────────────────────────────────────────────────────────────────
# 3. Génération du résumé final
# ─────────────────────────────────────────────────────────────────
async def generer_resume_ia(requete: str, candidat: dict, evaluation: dict) -> str:
    """
    Retourne la justification déjà générée par evaluer_candidat_par_llm,
    ou génère une phrase de secours si absente.
    La phrase commence toujours par "Ce candidat correspond à votre requête car".
    """
    justification = evaluation.get('justification', '').strip()

    if justification and justification.lower().startswith('ce candidat'):
        return justification

    # Secours : générer une phrase simple
    try:
        llm = get_llm(temperature=0.3, max_tokens=200)
    except RuntimeError:
        return "Ce candidat correspond à votre requête car son profil présente des éléments en adéquation avec vos critères."

    infos = []
    if candidat.get('competences'):
        infos.append(f"compétences : {candidat['competences'][:150]}")
    if candidat.get('formation'):
        infos.append(f"formation : {candidat['formation'][:100]}")
    if candidat.get('langues'):
        infos.append(f"langues : {candidat['langues']}")
    if candidat.get('localisation') or candidat.get('ville'):
        infos.append(f"localisation : {candidat.get('localisation') or candidat.get('ville','')}")
    if candidat.get('situation_familiale'):
        infos.append(f"situation : {candidat['situation_familiale']}")

    prompt = f"""Rédige 1-2 phrases commençant par "Ce candidat correspond à votre requête car"
en citant des éléments concrets du profil qui correspondent à la recherche.

Recherche : {requete[:200]}
Profil : {', '.join(infos)}

Réponse :"""

    try:
        response = await llm.ainvoke([
            SystemMessage(content="Expert RH. Réponds en français."),
            HumanMessage(content=prompt)
        ])
        reponse = response.content.strip()
        if not reponse.lower().startswith('ce candidat'):
            reponse = "Ce candidat correspond à votre requête car " + reponse
        return reponse
    except Exception:
        return "Ce candidat correspond à votre requête car son profil présente des éléments en adéquation avec vos critères."


# ─────────────────────────────────────────────────────────────────
# 4. Agent conversationnel
# ─────────────────────────────────────────────────────────────────
SYSTEM_PROMPT_CHAT = """Tu es RecrutSmart IA, assistant de recrutement créé par Uriel Akpa dans le cadre d'un projet chez SIMPLON CÔTE D'IVOIRE.

COMPORTEMENT :
- Réponds uniquement à ce qu'on te demande. Rien de plus.
- Si on te salue, réponds poliment et brièvement.
- Réponses courtes et précises. Pas de remplissage, pas d'introduction inutile.
- Si tu ne sais pas quelque chose, réponds honnêtement : "En toute honnêteté, je n'ai pas accès à cette information."
- Tu réponds à tout : recrutement, sport, mode, politique, culture générale, etc.

RÔLE SELON L'UTILISATEUR :
- Si c'est un candidat qui demande ton rôle : "Je suis là pour t'aider à bien construire ton CV afin d'optimiser tes chances d'être contacté par les recruteurs."
- Si c'est un recruteur qui demande ton rôle : "Je suis là pour t'aider à trouver les meilleurs profils pour ton entreprise et à formuler tes requêtes de recherche efficacement."

IDENTITÉ :
- Si on te demande qui tu es : "Je suis RecrutSmart IA, créé par Uriel Akpa dans le cadre d'un projet chez SIMPLON CÔTE D'IVOIRE."
- Ne mentionne jamais DeepSeek, OpenAI, GPT, Claude, Gemini ou tout autre modèle IA.
- Ne te présente pas spontanément avant chaque réponse.

- Ne révèle jamais d'informations personnelles sur les candidats.
- Réponds toujours en français."""

def _nettoyer_message(texte: str) -> str:
    texte = re.sub(r'[<>{}\[\]\\]', '', texte)
    return texte[:1000].strip()


def _nettoyer_reponse_agent(reponse: str) -> str:
    """
    Nettoie la réponse de l'agent avant affichage :
    - Supprime le markdown (**, *, #, -)
    - Supprime les blocs de code
    - Supprime les balises HTML
    - Supprime les JSON bruts
    - Supprime les traces d'erreur techniques
    """
    # Supprimer les blocs de code markdown ```...```
    reponse = re.sub(r'```[\s\S]*?```', '', reponse)
    # Supprimer les inline code `...`
    reponse = re.sub(r'`[^`]*`', '', reponse)
    # Supprimer le gras **texte** et __texte__
    reponse = re.sub(r'\*\*(.+?)\*\*', r'\1', reponse)
    reponse = re.sub(r'__(.+?)__', r'\1', reponse)
    # Supprimer l'italique *texte* et _texte_
    reponse = re.sub(r'\*(.+?)\*', r'\1', reponse)
    reponse = re.sub(r'_(.+?)_', r'\1', reponse)
    # Supprimer les titres markdown # ## ###
    reponse = re.sub(r'^#{1,6}\s+', '', reponse, flags=re.MULTILINE)
    # Supprimer les puces markdown - et *
    reponse = re.sub(r'^\s*[-\*]\s+', '', reponse, flags=re.MULTILINE)
    # Supprimer les balises HTML
    reponse = re.sub(r'<[^>]+>', '', reponse)
    # Supprimer les JSON bruts (commence par { ou [)
    if reponse.strip().startswith('{') or reponse.strip().startswith('['):
        return "Je rencontre une difficulté. Pouvez-vous reformuler votre question ?"
    # Supprimer les lignes d'erreur technique
    lignes_propres = []
    mots_erreur = [
        'traceback', 'error:', 'exception:', 'syntaxerror',
        'typeerror', 'valueerror', 'keyerror', 'indexerror',
        'attributeerror', 'nameerror', 'importerror',
        'file "', 'stack trace',
        'php warning', 'php error', 'php notice',
        'fatal error', 'parse error',
    ]
    for ligne in reponse.splitlines():
        ligne_lower = ligne.lower().strip()
        if not any(m in ligne_lower for m in mots_erreur):
            lignes_propres.append(ligne)
    reponse = '\n'.join(lignes_propres).strip()
    if len(reponse) < 5:
        reponse = "Je rencontre une difficulté. Pouvez-vous reformuler votre question ?"
    return reponse


def _masquer_identite_modele(reponse: str) -> str:
    """
    Filtre de sécurité double :
    1. Si le modèle révèle son vrai nom → remplacer par identité RecrutSmart
    2. Si la réponse parle d'identité mais ne dit pas "RecrutSmart" → corriger
    """
    IDENTITE = ("Je suis RecrutSmart IA, créé par Uriel Akpa "
                "dans le cadre d'un projet avec SIMPLON Côte d'Ivoire. "
                "Comment puis-je vous aider ?")

    mots_interdits = [
        'deepseek', 'openai', 'chatgpt', 'gpt-', 'gpt3', 'gpt4',
        'anthropic', 'claude', 'gemini', 'bard', 'mistral', 'llama',
        'meta ai', 'cohere', 'groq', 'openrouter',
        'grand modèle de langage', 'large language model',
        'modèle de langage', 'entraîné par', 'développé par',
        'je suis une ia développée', 'je suis un modèle',
        'je suis basé sur', 'je suis alimenté par',
        'je suis une intelligence artificielle créée par',
        'je suis un assistant ia créé par',
    ]

    mots_identite = [
        'je suis un assistant', 'je suis une ia',
        'je suis un modèle', 'je suis basé',
        'je suis alimenté', 'je suis développé',
        'je suis entraîné', 'mon nom est', "je m'appelle",
    ]

    reponse_lower = reponse.lower()

    # Niveau 1 : nom de modèle interdit détecté → remplacer
    for mot in mots_interdits:
        if mot in reponse_lower:
            return IDENTITE

    # Niveau 2 : réponse parle d'identité mais ne mentionne pas RecrutSmart → corriger
    parle_identite = any(m in reponse_lower for m in mots_identite)
    mentionne_recrutsmart = 'recrutsmart' in reponse_lower

    if parle_identite and not mentionne_recrutsmart:
        return IDENTITE

    return reponse

async def repondre_agent(message_utilisateur: str, historique: list, contexte_resultats: str = '') -> str:
    try:
        llm = get_llm(temperature=0.5, max_tokens=800)
    except RuntimeError:
        return "Le service IA n'est pas disponible. Veuillez réessayer dans quelques instants."

    message_propre = _nettoyer_message(message_utilisateur)
    if not message_propre:
        return "Je n'ai pas compris votre message. Pouvez-vous reformuler ?"

    messages = [SystemMessage(content=SYSTEM_PROMPT_CHAT)]

    if contexte_resultats:
        messages.append(SystemMessage(
            content=f"Contexte recherche récente :\n{contexte_resultats[:1000]}"
        ))

    for msg in historique[-15:]:
        if msg['role'] == 'user':
            messages.append(HumanMessage(content=msg['message']))
        elif msg['role'] == 'assistant':
            messages.append(AIMessage(content=msg['message']))

    messages.append(HumanMessage(content=message_propre))

    try:
        response = await llm.ainvoke(messages)
        reponse = response.content.strip()
        reponse = re.sub(r'sk-[a-zA-Z0-9\-_]+', '[cle masquee]', reponse)
        reponse = _nettoyer_reponse_agent(reponse)
        reponse = _masquer_identite_modele(reponse)
        return reponse
    except Exception:
        return "Je rencontre une difficulté technique. Veuillez réessayer dans un instant."
