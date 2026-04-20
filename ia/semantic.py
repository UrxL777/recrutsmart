
# ia/semantic.py
# Moteur de recherche sémantique — comprend le sens des mots
# Utilise sentence-transformers pour les embeddings locaux

import numpy as np
from sentence_transformers import SentenceTransformer
from sklearn.metrics.pairwise import cosine_similarity

# ── Modèle multilingue (français + anglais) ───────────────────────
# Téléchargé automatiquement au premier lancement (~500MB)
_modele = None

def get_modele() -> SentenceTransformer:
    """Charge le modèle une seule fois en mémoire."""
    global _modele
    if _modele is None:
        # paraphrase-multilingual-MiniLM-L12-v2 :
        # - Supporte 50+ langues dont le français
        # - Comprend le sens sémantique des phrases
        # - Léger et rapide
        _modele = SentenceTransformer('paraphrase-multilingual-MiniLM-L12-v2')
    return _modele


def calculer_similarite_semantique(texte1: str, texte2: str) -> float:
    """
    Calcule la similarité sémantique entre deux textes.
    Retourne un score entre 0.0 et 1.0.
    0.0 = aucun rapport, 1.0 = identiques
    """
    if not texte1 or not texte2:
        return 0.0

    modele = get_modele()
    embeddings = modele.encode([texte1, texte2])
    score = cosine_similarity([embeddings[0]], [embeddings[1]])[0][0]
    return float(score)


def calculer_score_semantique(requete: str, texte_cv: str) -> float:
    """
    Score sémantique entre la requête recruteur et le texte du CV.
    Retourne un score entre 0.0 et 1.0.
    """
    return calculer_similarite_semantique(requete, texte_cv)


def extraire_localisation(texte: str) -> list[str]:
    """
    Extrait les mots qui ressemblent à des villes/lieux dans un texte.
    Retourne une liste de mots potentiellement géographiques.
    """
    # Villes et quartiers d'Abidjan et de Côte d'Ivoire fréquents
    villes_connues = [
        'abidjan', 'cocody', 'plateau', 'yopougon', 'abobo', 'adjamé',
        'treichville', 'marcory', 'koumassi', 'port-bouët', 'attécoubé',
        'bingerville', 'anyama', 'songon', 'yamoussoukro', 'bouaké',
        'daloa', 'san-pédro', 'korhogo', 'man', 'gagnoa', 'divo',
        'abengourou', 'bondoukou', 'odienné', 'touba', 'séguéla',
        'grand-bassam', 'assinie', 'jacqueville'
    ]

    texte_lower = texte.lower()
    localisations = []
    for ville in villes_connues:
        if ville in texte_lower:
            localisations.append(ville)

    return localisations


def calculer_score_localisation(requete: str, ville_candidat: str) -> float:
    """
    Calcule le score de correspondance géographique.
    - Ville exacte : 1.0
    - Même zone (ex: Abidjan + quartier d'Abidjan) : 0.7
    - Aucune correspondance : 0.0
    """
    if not requete or not ville_candidat:
        return 0.0

    requete_lower     = requete.lower()
    ville_lower       = ville_candidat.lower()

    # Correspondance exacte
    if ville_lower in requete_lower:
        return 1.0

    # Quartiers d'Abidjan — si la requête mentionne Abidjan
    # et le candidat est dans un quartier d'Abidjan
    quartiers_abidjan = [
        'cocody', 'plateau', 'yopougon', 'abobo', 'adjamé',
        'treichville', 'marcory', 'koumassi', 'port-bouët',
        'attécoubé', 'bingerville', 'anyama', 'songon'
    ]
    if 'abidjan' in requete_lower and ville_lower in quartiers_abidjan:
        return 0.7
    if ville_lower == 'abidjan' and any(q in requete_lower for q in quartiers_abidjan):
        return 0.7

    return 0.0


def calculer_score_mots_exacts(requete: str, texte_cv: str) -> float:
    """
    Score basé sur les mots exacts de la requête trouvés dans le CV.
    Retourne un score entre 0.0 et 1.0.
    """
    if not requete or not texte_cv:
        return 0.0

    stopwords = {
        'le', 'la', 'les', 'de', 'du', 'des', 'un', 'une', 'et', 'ou',
        'à', 'au', 'aux', 'en', 'par', 'sur', 'avec', 'pour', 'dans',
        'qui', 'que', 'je', 'il', 'elle', 'nous', 'vous', 'ils', 'elles',
        'ce', 'se', 'sa', 'son', 'ses', 'mon', 'ma', 'mes', 'ton', 'ta'
    }

    mots_requete = set(
        mot.lower() for mot in requete.split()
        if len(mot) > 2 and mot.lower() not in stopwords
    )

    if not mots_requete:
        return 0.0

    texte_lower = texte_cv.lower()
    mots_trouves = sum(1 for mot in mots_requete if mot in texte_lower)

    return mots_trouves / len(mots_requete)


def extraire_annees_experience(texte: str) -> int:
    """
    Extrait le nombre d'années d'expérience depuis un texte CV.
    Retourne 0 si non trouvé.
    Exemples reconnus : "5 ans", "3 années", "2 ans d'expérience"
    """
    import re
    patterns = [
        r"(\d+)\s*ans?\s*d.exp",
        r"(\d+)\s*ann.es?\s*d.exp",
        r"exp.rience\s*[:\-]?\s*(\d+)\s*ans?",
        r"(\d+)\s*ans?.+anciennet",
    ]
    for pattern in patterns:
        match = re.search(pattern, texte.lower())
        if match:
            return int(match.group(1))
    return 0


def extraire_annees_requete(requete: str) -> int:
    """
    Extrait le nombre minimum d'années demandé dans la requête recruteur.
    Exemples : "minimum 2 ans", "au moins 3 ans", "5 ans d'expérience"
    """
    import re
    patterns = [
        r"minimum\s*(\d+)\s*ans?",
        r"au\s*moins\s*(\d+)\s*ans?",
        r"(\d+)\s*ans?\s*minimum",
        r"(\d+)\s*ans?.+exp",
        r"(\d+)\s*ann.es?.+exp",
    ]
    for pattern in patterns:
        match = re.search(pattern, requete.lower())
        if match:
            return int(match.group(1))
    return 0


def calculer_score_experience(requete: str, texte_cv: str) -> float:
    """
    Score basé sur les années d'expérience.
    - Candidat a autant ou plus d'années que demandé : 1.0
    - Candidat a moins d'années : score proportionnel
    - Aucun critère d'expérience dans la requête : 0.5 (neutre)
    """
    annees_requises  = extraire_annees_requete(requete)
    annees_candidat  = extraire_annees_experience(texte_cv)

    if annees_requises == 0:
        return 0.5  # Pas de critère d'expérience → score neutre

    if annees_candidat == 0:
        return 0.2  # Expérience non précisée dans le CV

    if annees_candidat >= annees_requises:
        return 1.0  # Critère rempli

    # Score proportionnel : ex 2 ans sur 5 requis = 0.4
    return round(annees_candidat / annees_requises, 2)