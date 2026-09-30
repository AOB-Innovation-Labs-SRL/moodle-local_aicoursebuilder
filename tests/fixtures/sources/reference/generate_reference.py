#!/usr/bin/env python3
"""Generates the reference texts of the golden set, once, independently of the PHP extractors.

PDF files:  the text of every page as PyMuPDF (fitz) reads it, pages separated by an empty line.
DOCX files: the text of word/document.xml, one line per paragraph (w:t runs joined, w:tab and w:br as a space).
PPTX files: the text of ppt/slides/slideN.xml in slide order, one line per paragraph (a:t runs joined, a:br and
            a:tab as a space). Table cells are paragraphs too, so they are in the text.
XLSX files: the cells of xl/worksheets/*.xml, one line per row, the cells joined by a space; shared strings are
            resolved from xl/sharedStrings.xml. The golden workbook has one visible sheet.
Scanned PDF: pdf_scan_01 has no text layer. Its reference is the OCR text layer of the original file (ABBYY
            FineReader 14, see "download_url" in ../sources.json), read with PyMuPDF. Pass the path of the
            downloaded original with --scan-original.

Usage: python generate_reference.py [--scan-original PATH] [name ...]
       Without names it writes every reference; with names (for example pptx_01 xlsx_01) only those.
Requires PyMuPDF (PDF references made with 1.27.2.2; the scan reference with 1.28.2). The output is committed;
the PHPUnit tests never run this script.
"""
import argparse
import html
import re
import zipfile
from pathlib import Path

import fitz

HERE = Path(__file__).resolve().parent
SOURCES = HERE.parent

PDF_FILES = ['pdf_text_01', 'pdf_text_02', 'pdf_text_03', 'pdf_text_04']
DOCX_FILES = ['docx_01', 'docx_02']
PPTX_FILES = ['pptx_01', 'pptx_02']
XLSX_FILES = ['xlsx_01']
SCAN_FILES = ['pdf_scan_01']


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


def pptx_reference(path):
    archive = zipfile.ZipFile(path)
    slides = sorted(
        (name for name in archive.namelist() if re.fullmatch(r'ppt/slides/slide\d+\.xml', name)),
        key=lambda name: int(re.search(r'(\d+)\.xml$', name).group(1)),
    )
    lines = []
    for name in slides:
        xml = archive.read(name).decode('utf-8')
        for paragraph in re.findall(r'<a:p[ >].*?</a:p>', xml, flags=re.S):
            paragraph = re.sub(r'<a:(?:br|tab)\b[^>]*/>', '<a:t> </a:t>', paragraph)
            text = html.unescape(''.join(re.findall(r'<a:t(?: [^>]*)?>([^<]*)</a:t>', paragraph)))
            if text.strip():
                lines.append(text.strip())
    return '\n'.join(lines)


def xlsx_reference(path):
    archive = zipfile.ZipFile(path)
    strings = []
    if 'xl/sharedStrings.xml' in archive.namelist():
        shared = archive.read('xl/sharedStrings.xml').decode('utf-8')
        for item in re.findall(r'<si>.*?</si>', shared, flags=re.S):
            strings.append(html.unescape(''.join(re.findall(r'<t(?: [^>]*)?>([^<]*)</t>', item))))
    sheets = sorted(name for name in archive.namelist() if re.fullmatch(r'xl/worksheets/sheet\d+\.xml', name))
    lines = []
    for name in sheets:
        xml = archive.read(name).decode('utf-8')
        for row in re.findall(r'<row[ >].*?</row>', xml, flags=re.S):
            cells = []
            for attributes, body in re.findall(r'<c\b([^>]*?)(?:/>|>(.*?)</c>)', row, flags=re.S):
                value = re.search(r'<v>([^<]*)</v>', body or '')
                inline = re.findall(r'<t(?: [^>]*)?>([^<]*)</t>', body or '')
                if 't="s"' in attributes and value:
                    cells.append(strings[int(value.group(1))])
                elif inline:
                    cells.append(html.unescape(''.join(inline)))
                elif value:
                    cells.append(html.unescape(value.group(1)))
            text = ' '.join(cell.strip() for cell in cells if cell.strip())
            if text:
                lines.append(text)
    return '\n'.join(lines)


parser = argparse.ArgumentParser()
parser.add_argument('--scan-original', help='Path of the original pdf_scan_01 with its OCR text layer')
parser.add_argument('names', nargs='*')
arguments = parser.parse_args()
selected = set(arguments.names)


def wanted(name):
    return not selected or name in selected


def write(name, text):
    (HERE / f'{name}.md').write_text(text + '\n', encoding='utf-8', newline='\n')


for name in PDF_FILES:
    if wanted(name):
        write(name, pdf_reference(SOURCES / f'{name}.pdf'))
for name in DOCX_FILES:
    if wanted(name):
        write(name, docx_reference(SOURCES / f'{name}.docx'))
for name in PPTX_FILES:
    if wanted(name):
        write(name, pptx_reference(SOURCES / f'{name}.pptx'))
for name in XLSX_FILES:
    if wanted(name):
        write(name, xlsx_reference(SOURCES / f'{name}.xlsx'))
for name in SCAN_FILES:
    if selected and name in selected or (not selected and arguments.scan_original):
        if not arguments.scan_original:
            parser.error(f'{name} needs --scan-original')
        write(name, pdf_reference(arguments.scan_original))
