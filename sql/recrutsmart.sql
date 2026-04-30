-- ============================================================
--  RecrutSmart — Schéma MySQL complet
--  1. Créer la base "recrutsmart" dans phpMyAdmin
--  2. Sélectionner la base puis importer ce fichier
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
--  1. CANDIDATS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS candidats (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom                 VARCHAR(100)  NOT NULL,
    prenom              VARCHAR(100)  NOT NULL,
    email               VARCHAR(191)  NOT NULL UNIQUE,
    telephone           VARCHAR(20)   NOT NULL,
    ville               VARCHAR(100)  NOT NULL,
    mot_de_passe        VARCHAR(255)  NOT NULL,
    question_securite   VARCHAR(255)  NOT NULL,
    reponse_securite    VARCHAR(255)  NOT NULL,
    cv_fichier          VARCHAR(255)  DEFAULT NULL,
    cv_mime             VARCHAR(100)  DEFAULT NULL,
    cv_date             DATETIME      DEFAULT NULL,
    actif               TINYINT(1)    NOT NULL DEFAULT 1,
    cree_le             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    maj                 DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  2. RECRUTEURS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recruteurs (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom                 VARCHAR(100)  NOT NULL,
    prenom              VARCHAR(100)  NOT NULL,
    email               VARCHAR(191)  NOT NULL UNIQUE,
    telephone           VARCHAR(20)   NOT NULL,
    entreprise          VARCHAR(200)  NOT NULL,
    site_web            VARCHAR(255)  DEFAULT NULL,
    mot_de_passe        VARCHAR(255)  NOT NULL,
    question_securite   VARCHAR(255)  NOT NULL,
    reponse_securite    VARCHAR(255)  NOT NULL,
    actif               TINYINT(1)    NOT NULL DEFAULT 1,
    cree_le             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    maj                 DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  3. MESSAGES (recruteur → candidat, sens unique)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recruteur_id    INT UNSIGNED NOT NULL,
    candidat_id     INT UNSIGNED NOT NULL,
    sujet           VARCHAR(255) NOT NULL,
    corps           TEXT         NOT NULL,
    heure_rdv       DATETIME     DEFAULT NULL,
    lu              TINYINT(1)   NOT NULL DEFAULT 0,
    cree_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recruteur_id) REFERENCES recruteurs(id) ON DELETE CASCADE,
    FOREIGN KEY (candidat_id)  REFERENCES candidats(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  4. CANDIDATURES
--     Créée automatiquement quand le recruteur envoie un message
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS candidatures (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    candidat_id     INT UNSIGNED NOT NULL,
    recruteur_id    INT UNSIGNED NOT NULL,
    message_id      INT UNSIGNED DEFAULT NULL,
    poste           VARCHAR(200) NOT NULL,
    statut          ENUM('En attente','Entretien planifié','Accepté','Refusé')
                    NOT NULL DEFAULT 'En attente',
    cree_le         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    maj             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (candidat_id)  REFERENCES candidats(id)   ON DELETE CASCADE,
    FOREIGN KEY (recruteur_id) REFERENCES recruteurs(id)  ON DELETE CASCADE,
    FOREIGN KEY (message_id)   REFERENCES messages(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  5. CV_ANALYSES
--     Remplie par le microservice Python après chaque upload CV
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cv_analyses (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    candidat_id         INT UNSIGNED NOT NULL UNIQUE,
    competences         TEXT         DEFAULT NULL,
    experience          TEXT         DEFAULT NULL,
    formation           TEXT         DEFAULT NULL,
    resume              TEXT         DEFAULT NULL,
    langues             TEXT         DEFAULT NULL COMMENT 'Toutes les langues parlées extraites du CV',
    localisation        TEXT         DEFAULT NULL COMMENT 'Ville, pays, mobilité extraits du CV',
    disponibilite       VARCHAR(100) DEFAULT NULL COMMENT 'Disponibilité (immédiate, préavis...)',
    situation_familiale VARCHAR(100) DEFAULT NULL COMMENT 'Situation familiale (marié, célibataire, enfants...)',
    annees_experience   INT          DEFAULT 0    COMMENT 'Nombre d années d expérience extrait du CV',
    surplus_info        LONGTEXT     DEFAULT NULL COMMENT 'Toutes les autres infos : loisirs, pays visités, permis, qualités, références, certifications...',
    analyse_le          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (candidat_id) REFERENCES candidats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  5b. CV_TEXTE_BRUT
--      Texte intégral extrait du CV (séparé pour ne pas alourdir cv_analyses)
--      Utilisé par le LLM pour chercher n'importe quelle information
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cv_texte_brut (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    candidat_id   INT UNSIGNED NOT NULL UNIQUE,
    texte_complet LONGTEXT     DEFAULT NULL COMMENT 'Texte brut intégral du CV extrait par Python',
    analyse_le    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (candidat_id) REFERENCES candidats(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  6. RECHERCHES
--     Historique des requêtes des recruteurs
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recherches (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recruteur_id    INT UNSIGNED NOT NULL,
    requete         TEXT         NOT NULL,
    cree_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recruteur_id) REFERENCES recruteurs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  7. RESULTATS_MATCHING
--     Scores et résumés IA pour chaque recherche
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS resultats_matching (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recherche_id    INT UNSIGNED NOT NULL,
    candidat_id     INT UNSIGNED NOT NULL,
    score           TINYINT UNSIGNED NOT NULL,
    resume_ia       TEXT         DEFAULT NULL,
    cree_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recherche_id) REFERENCES recherches(id)  ON DELETE CASCADE,
    FOREIGN KEY (candidat_id)  REFERENCES candidats(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  8. CONVERSATIONS_AGENT
--     Historique des échanges avec l'agent IA (LangChain/LangGraph)
--     role = 'user' ou 'assistant'
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conversations_agent (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    user_role       ENUM('candidat','recruteur') NOT NULL,
    role            ENUM('user','assistant')     NOT NULL,
    message         TEXT         NOT NULL,
    cree_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;