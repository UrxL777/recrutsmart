# ia/extractor.py
# Extraction de texte depuis les CV (PDF, DOCX, images JPG/PNG)

import os
import fitz        # PyMuPDF
import docx        # python-docx
from PIL import Image

def extraire_texte(chemin_fichier: str, mime_type: str) -> str:
    if not chemin_fichier or not os.path.exists(chemin_fichier):
        return ''
    try:
        if mime_type == 'application/pdf':
            return _extraire_pdf(chemin_fichier)
        elif mime_type in (
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword'
        ):
            return _extraire_docx(chemin_fichier)
        elif mime_type in ('image/jpeg', 'image/png'):
            return _extraire_image(chemin_fichier)
        return ''
    except Exception:
        return ''

def _extraire_pdf(chemin: str) -> str:
    texte = []
    with fitz.open(chemin) as doc:
        for page in doc:
            texte.append(page.get_text())
    return '\n'.join(texte).strip()

def _extraire_docx(chemin: str) -> str:
    document = docx.Document(chemin)
    paragraphes = [p.text for p in document.paragraphs if p.text.strip()]
    return '\n'.join(paragraphes).strip()

def _extraire_image(chemin: str) -> str:
    try:
        import pytesseract
        image = Image.open(chemin)
        return pytesseract.image_to_string(image, lang='fra+eng').strip()
    except Exception:
        return ''

def nettoyer_texte(texte: str) -> str:
    if not texte:
        return ''
    lignes = [ligne.strip() for ligne in texte.splitlines() if ligne.strip()]
    return '\n'.join(lignes)