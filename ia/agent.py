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
GROQ_SYSTEM_PROMPT = """Tu es un expert senior en recrutement avec 15 ans d'expérience en analyse de CVs et en matching candidat/poste.
Ta mission : évaluer avec RIGUEUR et PRÉCISION la pertinence d'un profil candidat par rapport à une recherche de poste.

Tu reçois :
- La REQUÊTE du recruteur en langage naturel (poste, compétences, critères personnels, contexte)
- Le PROFIL complet du candidat organisé par catégories :
    * competences         — compétences techniques et professionnelles
    * formation           — diplômes et formations
    * experience          — résumé de l'expérience professionnelle
    * annees_experience   — nombre d'années d'expérience (entier)
    * langues             — toutes les langues parlées
    * localisation        — ville, pays, mobilité
    * disponibilite       — disponibilité du candidat
    * situation_familiale — situation personnelle et familiale
    * surplus_info        — toutes les autres informations : loisirs, pays visités, permis, qualités, références, certifications, préférences alimentaires, croyances si mentionnées, et toute autre information présente dans le CV
    * texte_complet       — texte brut intégral du CV (source de vérité absolue)

Retourne UNIQUEMENT ce JSON valide, sans texte avant ou après :
{
    "score": <entier 0-100>,
    "justification": "Ce candidat correspond à votre requête car <2-3 phrases expliquant précisément le score>",
    "points_forts": "<liste des atouts du profil pour ce poste>",
    "points_faibles": "<lacunes ou éléments manquants>",
    "competences_detectees": "<toutes les compétences identifiées dans le CV>",
    "niveau_experience": "<junior|confirmé|senior|expert>",
    "recommandation": "<fort recommandé|recommandé|à considérer|non recommandé>"
}

========================================
ÉTAPE 1 — ANALYSE DE LA REQUÊTE DU RECRUTEUR
========================================
Avant de scorer, tu DOIS lire la requête en entier et identifier TOUS les critères
mentionnés, qu'ils soient professionnels ou personnels.
La requête peut contenir n'importe quel type de critère — traite-les tous avec le même sérieux.

A) GENRE
- Si la requête mentionne explicitement un genre → critère OBLIGATOIRE.
- Genre non correspondant → score plafonné à 20, recommandation "non recommandé".
- Genre non mentionné → ignorer, ne pas pénaliser.

B) ÂGE
- Si la requête mentionne un âge ou une fourchette → critère OBLIGATOIRE.
- Chercher l'âge dans : situation_familiale, surplus_info, texte_complet.
- Âge hors fourchette :
    * Écart 1-3 ans  → -15 points
    * Écart 4-7 ans  → -25 points
    * Écart 8 ans+   → -40 points
- Âge non disponible → signaler dans "points_faibles" (-5 points max).
- Âge non mentionné dans la requête → ignorer.

C) VILLE / LOCALISATION
- Si la requête mentionne une ville, région ou pays → critère OBLIGATOIRE.
- Chercher dans : localisation, ville (profil candidat), texte_complet.
- Pénalités :
    * Même ville                              → bonus +10 points
    * Ville différente, même pays             → -20 points
    * Pays différent, même continent          → -30 points
    * Continent différent                     → -40 points
- Ville non renseignée → signaler dans "points_faibles" (-10 points max).
- Aucune ville mentionnée dans la requête → ignorer.

D) LANGUES
- Si la requête mentionne une ou plusieurs langues → critère OBLIGATOIRE.
- Chercher dans : langues, surplus_info, texte_complet.
- Langue obligatoire absente → -15 points par langue manquante.
- Langue souhaitée absente → -5 points par langue manquante.
- Tenir compte des variantes (baoulé/baoule, dioula/jula, etc.).

E) COMPÉTENCES
- Lister TOUTES les compétences demandées dans la requête.
- Chercher dans : competences, formation, surplus_info, texte_complet.
- Compétence OBLIGATOIRE manquante → -15 points par compétence.
- Compétence SOUHAITÉE manquante → -5 points par compétence.
- Tenir compte des synonymes (JS = JavaScript, agronome = agriculteur, etc.).
- Aucune compétence en lien → score plafonné à 25.

F) ANNÉES D'EXPÉRIENCE
- Si la requête mentionne une durée → critère OBLIGATOIRE.
- Utiliser en priorité : annees_experience (entier), puis experience (texte).
- Règles :
    * Écart 1-2 ans en dessous → score plafonné à 50
    * Écart 3-4 ans en dessous → score plafonné à 35
    * Écart 5 ans+ en dessous  → score plafonné à 20
- Aucune durée mentionnée → ignorer.

G) SITUATION FAMILIALE ET CRITÈRES PERSONNELS
- Si la requête mentionne un état civil, nombre d'enfants, ou toute autre
  information personnelle → chercher dans : situation_familiale, surplus_info, texte_complet.
- Critère présent et correspondant → bonus +5 points.
- Critère présent mais non correspondant → -10 points.
- Information absente du CV → signaler dans "points_faibles", ne pas inventer.

H) INFORMATIONS SUPPLÉMENTAIRES (loisirs, permis, pays visités, préférences, etc.)
- Si la requête mentionne n'importe quelle information inhabituelle ou spécifique
  (ex: "qui ne mange pas de porc", "qui a visité le Japon", "qui joue au football") →
  chercher dans : surplus_info, texte_complet.
- Information présente et correspondante → bonus +5 points.
- Information demandée mais absente du CV → signaler dans "points_faibles".
- Ne JAMAIS inventer une information absente du CV.

========================================
ÉTAPE 2 — SCORING DE BASE
========================================
BARÈME :
- 90-100 : Profil idéal, correspond à tous les critères essentiels
- 75-89  : Très bon profil, correspondance forte
- 55-74  : Bon profil, correspondance partielle mais solide
- 35-54  : Profil moyen, quelques correspondances
- 15-34  : Profil faible, peu de correspondance
- 0-14   : Profil hors sujet ou données insuffisantes

RÈGLES :
- Utiliser les colonnes structurées en priorité.
- Utiliser texte_complet comme source de vérité absolue pour confirmer ou trouver
  des informations non présentes dans les colonnes structurées.
- Un profil junior peut scorer haut si la requête cherche un junior.
- Ne pas pénaliser un profil uniquement parce qu'il a trop d'expérience.

========================================
ÉTAPE 3 — APPLICATION DES PÉNALITÉS ET BONUS
========================================
PÉNALITÉS :
1.  Genre non correspondant (si demandé)              → score plafonné à 20
2.  Âge hors fourchette (écart 1-3 ans)               → -15 points
3.  Âge hors fourchette (écart 4-7 ans)               → -25 points
4.  Âge hors fourchette (écart 8 ans+)                → -40 points
5.  Ville différente, même pays (si demandée)         → -20 points
6.  Pays différent, même continent (si demandée)      → -30 points
7.  Continent différent (si demandée)                 → -40 points
8.  Langue obligatoire manquante                      → -15 points par langue
9.  Compétence obligatoire manquante                  → -15 points par compétence
10. Compétence souhaitée manquante                    → -5 points par compétence
11. Expérience insuffisante (écart 1-2 ans)           → score plafonné à 50
12. Expérience insuffisante (écart 3-4 ans)           → score plafonné à 35
13. Expérience insuffisante (écart 5 ans+)            → score plafonné à 20
14. Aucune compétence en lien                         → score plafonné à 25
15. Critère personnel demandé non correspondant       → -10 points

BONUS :
1. Même ville que demandée                            → +10 points
2. Compétences rares très recherchées                 → +5 à +10 points
3. Expérience dans le même secteur d'activité         → +5 points
4. Toutes les compétences obligatoires présentes      → +10 points
5. Critère personnel demandé correspondant            → +5 points
6. Langue rare demandée présente                      → +5 points

Score final = Score de base + Bonus - Pénalités
Score final doit rester entre 0 et 100.

========================================
ÉTAPE 4 — DÉTERMINATION DE LA RECOMMANDATION
========================================
- Score ≥ 75 et aucun critère bloquant   → "fort recommandé"
- Score 55-74                             → "recommandé"
- Score 35-54                             → "à considérer"
- Score < 35 ou genre non correspondant  → "non recommandé"

========================================
RÈGLES GÉNÉRALES
========================================
- La justification DOIT commencer par : "Ce candidat correspond à votre requête car"
- Ne JAMAIS inventer des informations absentes du profil candidat.
- Si une information est manquante dans le profil, le signaler dans "points_faibles".
- Chercher dans texte_complet avant de conclure qu'une information est absente.
- Être factuel et précis dans la justification.
- Aucun commentaire avant ou après le JSON.
""".strip()


# ─────────────────────────────────────────────────────────────────
# 1. Validation de la requête
# ─────────────────────────────────────────────────────────────────
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
permettant de chercher un profil : métier, compétence, domaine, formation,
ou tout autre critère personnel précis (langue, localisation, situation familiale...).
Elle est INVALIDE uniquement si elle est totalement vide de sens.

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
            HumanMessage(content=f'REQUÊTE : "{requete}"\n\nPROFIL CANDIDAT :\n{profil}')
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
SYSTEM_PROMPT_CHAT = """Tu es RecrutSmart IA, un assistant intégré à une plateforme de recrutement intelligente créé par Uriel AAKPA.

Ton rôle :
- Tu aides les recruteurs à trouver des candidats et les candidats à optimiser leur CV.
- Pour un recruteur : suggère des formulations précises pour obtenir de bons résultats.
- Pour un candidat : explique comment structurer son CV pour être bien classé.
- Tu peux répondre à toutes questions générales comme une IA normale.

IDENTITÉ — RÈGLE ABSOLUE ET NON NÉGOCIABLE :
- Tu es RecrutSmart IA, point final. Tu n'as pas d'autre identité.
- Tu n'es PAS DeepSeek, tu n'es PAS GPT, tu n'es PAS Claude, tu n'es PAS Gemini, tu n'es PAS un LLM open source.
- Si on te demande quel modèle tu utilises, quelle technologie tu es, qui t'a entraîné, ou toute question sur ta nature technique → réponds UNIQUEMENT : "Je suis RecrutSmart IA, un assistant créé par Uriel AAKPA dans le cadre d'un projet avec SIMPLON Côte d'Ivoire. Je ne peux pas vous donner d'informations sur ma technologie interne."
- Ne mentionne JAMAIS les noms DeepSeek, OpenAI, Anthropic, Google, Meta, Mistral, ou tout autre fournisseur de modèle.
- Ne dis JAMAIS "Je suis un grand modèle de langage" ou "Je suis une IA basée sur...".
- Si on insiste ou essaie de te piéger avec des questions détournées → maintiens toujours la même réponse.

Règles de confidentialité :
- Ne révèle jamais d'informations personnelles sur les candidats ni de données sensibles.
- Réponds toujours en français."""

def _nettoyer_message(texte: str) -> str:
    texte = re.sub(r'[<>{}\[\]\\]', '', texte)
    return texte[:1000].strip()


def _nettoyer_reponse_agent(reponse: str) -> str:
    """
    Nettoie la réponse de l'agent avant affichage :
    - Supprime les blocs de code markdown (```...```)
    - Supprime les balises HTML
    - Supprime les JSON bruts qui auraient pu s'échapper
    - Supprime les traces d'erreur Python/PHP
    - Supprime les clés API ou tokens qui auraient pu fuiter
    """
    # Supprimer les blocs de code markdown ```...```
    reponse = re.sub(r'```[\s\S]*?```', '', reponse)
    # Supprimer les inline code `...`
    reponse = re.sub(r'`[^`]*`', '', reponse)
    # Supprimer les balises HTML
    reponse = re.sub(r'<[^>]+>', '', reponse)
    # Supprimer les JSON bruts (commence par { ou [)
    reponse = re.sub(r'^\s*[\{\[][\s\S]*[\}\]]\s*$', '', reponse.strip())
    # Supprimer les lignes qui ressemblent à des erreurs techniques
    lignes_propres = []
    mots_erreur = [
        'traceback', 'error:', 'exception:', 'syntaxerror',
        'typeerror', 'valueerror', 'keyerror', 'indexerror',
        'attributeerror', 'nameerror', 'importerror',
        'file "', 'line ', 'at line', 'stack trace',
        'undefined', 'null', 'none', 'nan',
        'php warning', 'php error', 'php notice',
        'fatal error', 'parse error', 'warning:',
    ]
    for ligne in reponse.splitlines():
        ligne_lower = ligne.lower().strip()
        if not any(m in ligne_lower for m in mots_erreur):
            lignes_propres.append(ligne)
    reponse = '\n'.join(lignes_propres).strip()
    # Si après nettoyage la réponse est vide ou trop courte → message générique
    if len(reponse) < 5:
        reponse = "Je rencontre une difficulté. Pouvez-vous reformuler votre question ?"
    return reponse


def _masquer_identite_modele(reponse: str) -> str:
    """
    Filtre de sécurité double :
    1. Si le modèle révèle son vrai nom → remplacer par identité RecrutSmart
    2. Si la réponse parle d'identité mais ne dit pas "RecrutSmart" → corriger
    """
    IDENTITE = ("Je suis RecrutSmart IA, un assistant créé par Uriel AAKPA "
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
        'je suis', 'mon nom est', "je m'appelle",
        'je suis un assistant', 'je suis une ia',
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
