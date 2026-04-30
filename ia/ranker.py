# ia/ranker.py
# Pré-filtrage Python (rapide) + évaluation LLM sur le top filtré
# Règles de code : seuil >= 40, tri décroissant

import asyncio
import traceback
import unicodedata


def _normaliser(texte: str) -> str:
    """Supprime accents et met en minuscules."""
    return ''.join(
        c for c in unicodedata.normalize('NFD', texte.lower())
        if unicodedata.category(c) != 'Mn'
    )


def _score_prefiltrage(requete: str, candidat: dict) -> int:
    """
    Score rapide par mots-clés pour trier les candidats AVANT le LLM.
    Python cherche dans TOUTES les colonnes disponibles.
    Ce n'est pas le score final — juste un ordre de passage.

    Pondération :
    - competences      → +4 (le plus important)
    - formation        → +3
    - experience       → +3
    - langues          → +3
    - localisation     → +3
    - situation_familiale → +2
    - surplus_info     → +2
    - texte_complet    → +1 (filet de sécurité)
    """
    requete_norm = _normaliser(requete)
    mots_vides = {
        'de','du','la','le','les','un','une','et','ou','en','au','aux',
        'avec','pour','par','ans','an','plus','qui','que','dans','sur',
        'est','des','ne','pas','je','il','elle','nous','vous','ils',
        'son','sa','ses','ce','cet','cette','ces','se','si','ni','car',
        'mais','donc','or','or','car','ni','or'
    }
    mots = [m for m in requete_norm.split() if len(m) > 2 and m not in mots_vides]

    if not mots:
        return 1

    sources = {
        'competences':         (4, candidat.get('competences',         '')),
        'formation':           (3, candidat.get('formation',           '')),
        'experience':          (3, candidat.get('experience',          '')),
        'langues':             (3, candidat.get('langues',             '')),
        'localisation':        (3, candidat.get('localisation', '') + ' ' + candidat.get('ville', '')),
        'situation_familiale': (2, candidat.get('situation_familiale', '')),
        'surplus_info':        (2, candidat.get('surplus_info',        '')[:3000]),
        'texte_complet':       (1, candidat.get('texte_complet',       '')[:4000]),
    }

    score = 0
    for mot in mots:
        racine = mot[:4] if len(mot) >= 4 else mot
        for poids, texte in sources.values():
            texte_norm = _normaliser(texte)
            if mot in texte_norm or racine in texte_norm:
                score += poids
                break  # compter une seule fois par mot

    return score


async def classer_candidats(requete: str, candidats: list) -> dict:
    """
    1. Pré-filtrage Python sur toutes les colonnes → tri par pertinence
    2. Évaluation LLM sur le top 25 (équilibre vitesse/qualité)
    3. Règle 1 : score < 40 → candidat non retourné
    4. Règle 2 : tri final par score décroissant (jamais un 40 avant un 70)
    """
    from agent import evaluer_candidat_par_llm, generer_resume_ia

    print(f"[RANKER] Requête : {requete}")
    print(f"[RANKER] Total candidats : {len(candidats)}")

    # ── Pré-filtrage Python ───────────────────────────────────────
    candidats_tries = sorted(
        candidats,
        key=lambda c: -_score_prefiltrage(requete, c)
    )

    # Évaluer TOUS les candidats — pas de limite arbitraire
    top_candidats = candidats_tries

    print(f"[RANKER] {len(candidats_tries)} candidats à évaluer (tous) :")
    for i, c in enumerate(candidats_tries[:5]):
        print(f"  {i+1}. {c.get('prenom','')} {c.get('nom','')} — pré-score : {_score_prefiltrage(requete, c)}")

    # ── Évaluation LLM ────────────────────────────────────────────
    resultats = []
    for idx, candidat in enumerate(top_candidats):
        try:
            print(f"[RANKER] LLM {idx+1}/{len(top_candidats)} — {candidat.get('prenom','')} {candidat.get('nom','')}...", end=' ', flush=True)

            evaluation = await evaluer_candidat_par_llm(requete, candidat)
            score      = evaluation.get("score", 0)

            # ── Règle 1 : seuil minimum d'affichage ──────────────
            if score >= 40:
                resume = await generer_resume_ia(requete, candidat, evaluation)
                resultats.append({
                    'candidat_id':          candidat['id'],
                    'nom':                  candidat['nom'],
                    'prenom':               candidat['prenom'],
                    'ville':                candidat.get('ville',             ''),
                    'email':                candidat.get('email',             ''),
                    'cv_fichier':           candidat.get('cv_fichier',        ''),
                    'competences':          candidat.get('competences',       ''),
                    'formation':            candidat.get('formation',         ''),
                    'experience':           candidat.get('experience',        ''),
                    'langues':              candidat.get('langues',           ''),
                    'localisation':         candidat.get('localisation',      ''),
                    'situation_familiale':  candidat.get('situation_familiale',''),
                    'annees_experience':    candidat.get('annees_experience',  0),
                    'score':                score,
                    'resume_ia':            resume,
                    'points_forts':         evaluation.get('points_forts',         ''),
                    'points_faibles':       evaluation.get('points_faibles',       ''),
                    'niveau_experience':    evaluation.get('niveau_experience',    ''),
                    'recommandation':       evaluation.get('recommandation',       ''),
                })
                print(f"score={score}% ✅")
            else:
                print(f"score={score}% (ignoré < 40)")

            await asyncio.sleep(0.1)

        except Exception as e:
            print(f"Erreur candidat {candidat.get('id','?')}: {e}")
            traceback.print_exc()
            continue

    # ── Règle 2 : tri par score décroissant ───────────────────────
    resultats.sort(key=lambda x: -x['score'])

    print(f"[RANKER] {len(resultats)} retenus sur {len(top_candidats)} évalués")

    return {
        'exacts':     resultats,
        'partiels':   [],
        'similaires': [],
        'valide':     True
    }
