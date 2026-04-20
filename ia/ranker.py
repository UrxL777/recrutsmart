# ia/ranker.py
# Système de classement strict :
# Filtre obligatoire : compétence OU formation dans le domaine
# Score 100 = compétence + formation + expérience + localisation

from semantic import (
    calculer_score_semantique,
    calculer_score_localisation,
    calculer_score_mots_exacts,
    calculer_score_experience,
    extraire_localisation
)

# Seuils de détection
SEUIL_COMPETENCE   = 0.35
SEUIL_FORMATION    = 0.33
SEUIL_LOCALISATION = 0.65
SCORE_MINIMUM      = 10


def classer_candidats(requete: str, candidats: list) -> list:
    """
    Règles strictes :
    1. Filtre obligatoire : candidat doit avoir compétence OU formation dans le domaine
    2. Si ni compétence ni formation → exclu, même si expérience/localisation OK
    3. Si localisation précisée dans requête → doit correspondre EN PLUS
    4. Score 100 = compétence + formation + expérience + localisation tous OK
    5. Tri par score décroissant
    """
    locs_requete          = extraire_localisation(requete)
    localisation_demandee = len(locs_requete) > 0

    resultats = []

    for candidat in candidats:
        texte_cv    = candidat.get('texte_cv', '')
        competences = candidat.get('competences', '')
        formation   = candidat.get('formation', '')
        experience  = candidat.get('experience', '')
        ville       = candidat.get('ville', '')

        if not texte_cv and not competences and not formation:
            continue

        # ── Score compétence ──────────────────────────────────────
        # On compare la requête avec les compétences déclarées + texte CV
        texte_comp   = ' '.join(filter(None, [competences, texte_cv]))
        s_competence = calculer_score_semantique(requete, texte_comp)

        # ── Score formation ───────────────────────────────────────
        texte_form  = ' '.join(filter(None, [formation, texte_cv]))
        s_formation = calculer_score_semantique(requete, texte_form)

        # ── Filtre obligatoire ────────────────────────────────────
        comp_ok = s_competence >= SEUIL_COMPETENCE
        form_ok = s_formation  >= SEUIL_FORMATION

        # Ni compétence ni formation → exclu définitivement
        if not comp_ok and not form_ok:
            continue

        # ── Score localisation ────────────────────────────────────
        s_localisation = calculer_score_localisation(requete, ville)
        loca_ok        = s_localisation >= SEUIL_LOCALISATION

        # Si localisation demandée mais ne correspond pas → exclu
        if localisation_demandee and not loca_ok:
            continue

        # ── Score expérience ──────────────────────────────────────
        texte_exp    = ' '.join(filter(None, [experience, texte_cv]))
        s_experience = calculer_score_experience(requete, texte_exp)
        exp_ok       = s_experience >= 0.80

        # ── Calcul du score final (0-100) ─────────────────────────
        # Score 100 uniquement si TOUS les critères sont remplis
        if comp_ok and form_ok and exp_ok and loca_ok:
            score_final = 100

        elif comp_ok and form_ok and exp_ok:
            # Compétence + Formation + Expérience (sans localisation)
            score_final = round(
                s_competence * 0.40 +
                s_formation  * 0.30 +
                s_experience * 0.30
            ) * 100
            score_final = min(95, max(80, score_final))

        elif comp_ok and form_ok and loca_ok:
            # Compétence + Formation + Localisation (sans expérience exacte)
            score_final = round(
                (s_competence * 0.40 +
                 s_formation  * 0.35 +
                 s_localisation * 0.25) * 100
            )
            score_final = min(92, max(75, score_final))

        elif comp_ok and form_ok:
            # Compétence + Formation (critères principaux)
            score_final = round(
                (s_competence * 0.55 +
                 s_formation  * 0.45) * 100
            )
            score_final = min(85, max(60, score_final))

        elif comp_ok and loca_ok:
            # Compétence + Localisation (sans formation)
            score_final = round(
                (s_competence  * 0.65 +
                 s_localisation * 0.35) * 100
            )
            score_final = min(70, max(45, score_final))

        elif form_ok and loca_ok:
            # Formation + Localisation (sans compétence explicite)
            score_final = round(
                (s_formation   * 0.65 +
                 s_localisation * 0.35) * 100
            )
            score_final = min(65, max(40, score_final))

        elif comp_ok:
            # Compétence seule
            score_final = round(s_competence * 100)
            score_final = min(55, max(SCORE_MINIMUM, score_final))

        else:
            # Formation seule
            score_final = round(s_formation * 100)
            score_final = min(45, max(SCORE_MINIMUM, score_final))

        if score_final < SCORE_MINIMUM:
            continue

        resultats.append({
            'candidat_id':        candidat['id'],
            'nom':                candidat['nom'],
            'prenom':             candidat['prenom'],
            'ville':              ville,
            'email':              candidat.get('email', ''),
            'cv_fichier':         candidat.get('cv_fichier', ''),
            'competences':        competences,
            'experience':         experience,
            'formation':          formation,
            'score':              score_final,
            'score_competence':   round(s_competence * 100),
            'score_formation':    round(s_formation * 100),
            'score_localisation': round(s_localisation * 100),
            'score_experience':   round(s_experience * 100),
            'comp_ok':            comp_ok,
            'form_ok':            form_ok,
            'loca_ok':            loca_ok,
            'exp_ok':             exp_ok,
        })

    # Tri par score décroissant — jamais un 30% avant un 85%
    resultats.sort(key=lambda x: -x['score'])
    return resultats