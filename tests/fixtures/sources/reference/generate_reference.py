#!/usr/bin/env python3
"""Generates the reference texts of the golden set, once, independently of the PHP extractors.

PDF files:  the text of every page as PyMuPDF (fitz) reads it, pages separated by an empty line.
DOCX files: the text of word/document.xml, one line per paragraph (w:t runs joined, w:tab and w:br as a space).

Usage: python generate_reference.py    (run from any directory; writes the *.md files next to this script)
Requires PyMuPDF (tested with 1.27.2.2). The output is committed; the PHPUnit test never runs this script.
"""
import html
import re
import zipfile
from pathlib import Path

import fitz

HERE = Path(__file__).resolve().parent
SOURCES = HERE.parent

PDF_FILES = ['pdf_text_01', 'pdf_text_02', 'pdf_text_03', 'pdf_text_04']
DOCX_FILES = ['docx_01', 'docx_02']


def pdf_reference(path):
    document = fitz.open(path)
    return '\n\n'.join(page.get_text().strip() for page in document)


def docx_reference(path):
    xml = zipfile.ZipFile(path).read('word/document.xml').decode('utf-8')
    lines = []
    for paragraph in re.findall(r'<w:p[ >].*?</w:p>', xml, flags=re.S):
        paragraph = re.sub(r'<w:(?:tab|br)\b[^>]*/>', '<w:t> </w:t>', paragraph)
        text = html.unescape(''.join(re.findall(r'<w:t(?: [^>]*)?>([^<]*)</w:t>', paragraph)))
        if text.strip():
            lines.append(text.strip())
    return '\n'.join(lines)


for name in PDF_FILES:
    (HERE / f'{name}.md').write_text(pdf_reference(SOURCES / f'{name}.pdf') + '\n', encoding='utf-8', newline='\n')
for name in DOCX_FILES:
    (HERE / f'{name}.md').write_text(docx_reference(SOURCES / f'{name}.docx') + '\n', encoding='utf-8', newline='\n')
