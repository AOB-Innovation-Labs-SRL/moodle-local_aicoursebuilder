<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Romanian language strings for local_aicoursebuilder.
 *
 * @package    local_aicoursebuilder
 * @copyright  2026 AOB Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aicoursebuilder:generateincourse'] = 'Generează conținut într-un curs cu AI Course Builder';
$string['aicoursebuilder:manage'] = 'Administrează AI Course Builder';
$string['aicoursebuilder:use'] = 'Folosește AI Course Builder';
$string['aicoursebuilder:usedirectconnectors'] = 'Folosește conectorii direcți către furnizorii AI';
$string['aicoursebuilder:viewusage'] = 'Vizualizează utilizarea AI Course Builder';
$string['aipolicynotaccepted'] = 'Utilizatorul nu a acceptat politica AI a Moodle.';
$string['allowedtypes'] = 'Tipuri de surse permise';
$string['allowedtypes_desc'] = 'Tipurile de fișiere pe care profesorii le pot încărca drept material sursă.';
$string['commandfailed'] = 'Programul extern {$a} a eșuat.';
$string['commandtimeout'] = 'Programul extern {$a} a durat prea mult și a fost oprit.';
$string['connector_coreai'] = 'Subsistemul AI Moodle (core_ai)';
$string['connector_deepseek'] = 'DeepSeek (API direct)';
$string['connector_usedefault'] = 'Folosește conectorul implicit';
$string['connectorhttperror'] = 'Furnizorul AI a răspuns cu eroarea HTTP {$a}.';
$string['connectorinvalidjson'] = 'Furnizorul AI a trimis un răspuns care nu este JSON valid.';
$string['connectornetworkerror'] = 'Furnizorul AI nu a putut fi contactat.';
$string['connectornotconfigured'] = 'Conectorul AI {$a} nu este configurat.';
$string['connectorratelimited'] = 'S-a atins limita de cereri a furnizorului AI. Încearcă din nou mai târziu.';
$string['connectorssettings'] = 'Conectori AI';
$string['connectorunknown'] = 'Conector AI necunoscut: {$a}.';
$string['connectorunsupported'] = 'Conectorul AI nu suportă: {$a}.';
$string['coreaierror'] = 'Subsistemul AI Moodle a întors o eroare: {$a}';
$string['deepseek_apikey'] = 'Cheia API DeepSeek';
$string['deepseek_apikey_desc'] = 'Se păstrează criptată. Nu mai este afișată după salvare.';
$string['deepseek_baseurl'] = 'URL de bază DeepSeek';
$string['deepseek_baseurl_desc'] = 'URL-ul de bază al API-ului compatibil OpenAI, fără /v1, de exemplu https://api.deepseek.com.';
$string['deepseek_model'] = 'Modelul implicit DeepSeek';
$string['deepseek_model_desc'] = 'Modelul folosit de pașii care nu au un model propriu, de exemplu deepseek-flash.';
$string['deepseek_thinking'] = 'Modul thinking DeepSeek';
$string['deepseek_thinking_desc'] = 'Activează modul thinking. Consumă mai mulți tokeni de ieșire, iar temperatura este ignorată.';
$string['deepseekheading'] = 'DeepSeek';
$string['deepseekheading_desc'] = 'Conexiune directă la API-ul DeepSeek. DeepSeek procesează datele în China: trimite doar conținut public, fără date personale.';
$string['defaultconnector'] = 'Conector implicit';
$string['defaultconnector_desc'] = 'Conectorul folosit de fiecare pas care nu are un conector propriu.';
$string['extractionfailed'] = 'Textul nu a putut fi extras din fișier: {$a}';
$string['extractionheading'] = 'Extragerea textului';
$string['extractionheading_desc'] = 'Programe externe opționale, folosite când extractoarele PHP integrate eșuează. Lasă o cale goală pentru a dezactiva acel fallback.';
$string['extractionnotext'] = 'Fișierul nu conține text care poate fi extras. Un document scanat necesită OCR, care nu este încă disponibil.';
$string['extractorunsupported'] = 'Fișierele de tip {$a} nu sunt suportate.';
$string['ingestallfailed'] = 'Textul nu a putut fi extras din niciun fișier sursă.';
$string['ingestnosources'] = 'Jobul nu are fișiere sursă.';
$string['ingestprogress'] = 'Au fost procesate {$a->done} din {$a->total} fișiere sursă.';
$string['maxfiles'] = 'Număr maxim de fișiere per job';
$string['maxfiles_desc'] = 'Cel mai mare număr de fișiere sursă pe care le poate avea un job.';
$string['maxfilesize'] = 'Dimensiune maximă per fișier (MB)';
$string['maxfilesize_desc'] = 'Cel mai mare fișier sursă acceptat, în megaocteți.';
$string['messageprovider:jobfailed'] = 'Job AI Course Builder eșuat';
$string['messageprovider:jobfinished'] = 'Job AI Course Builder finalizat';
$string['notimplemented'] = 'Această funcționalitate nu este încă implementată.';
$string['notjobowner'] = 'Doar proprietarul acestui job îl poate modifica.';
$string['pdfencrypted'] = 'PDF-ul este criptat. Elimină protecția și încarcă din nou fișierul.';
$string['pdftotextpath'] = 'Calea către pdftotext';
$string['pdftotextpath_desc'] = 'Calea completă a programului pdftotext (Poppler), de exemplu /usr/bin/pdftotext. Este fallback pentru fișierele PDF și este necesară și pentru fallback-ul LibreOffice. Lasă gol pentru a dezactiva.';
$string['pluginname'] = 'AI Course Builder';
$string['route_connector'] = 'Conector: {$a}';
$string['route_model'] = 'Model: {$a}';
$string['route_model_desc'] = 'Lasă gol pentru modelul implicit al conectorului. Ignorat pentru subsistemul AI Moodle.';
$string['routesheading'] = 'Rute per pas';
$string['routesheading_desc'] = 'Conectorul și modelul pentru fiecare pas de generare. Valorile goale revin la cele implicite de mai sus. Subsistemul AI Moodle folosește furnizorul și modelul configurate în setările AI ale site-ului.';
$string['sofficepath'] = 'Calea către LibreOffice (soffice)';
$string['sofficepath_desc'] = 'Calea completă a programului soffice, de exemplu /usr/bin/soffice. Este fallback pentru fișierele DOCX: fișierul este convertit în PDF și citit cu pdftotext, deci trebuie setată și calea pdftotext. Lasă gol pentru a dezactiva.';
$string['sourcefilemissing'] = 'Fișierul sursă salvat nu a fost găsit.';
$string['sourceinfected'] = 'Antivirusul a refuzat fișierul {$a}.';
$string['sourcenofiles'] = 'Nu a fost încărcat niciun fișier.';
$string['sourcesheading'] = 'Fișiere sursă';
$string['sourcesheading_desc'] = 'Limite pentru fișierele pe care profesorii le încarcă drept material sursă.';
$string['sourcetoolarge'] = 'Fișierul {$a->filename} depășește maximul de {$a->maxsize}.';
$string['sourcetoomany'] = 'Un job poate avea cel mult {$a} fișiere sursă.';
$string['sourcetype_docx'] = 'Document Word (.docx)';
$string['sourcetype_pdf'] = 'PDF (.pdf)';
$string['sourcetypenotallowed'] = 'Fișierul {$a} nu este de un tip permis.';
$string['step_activities'] = 'Activități';
$string['step_brief'] = 'Brief-ul cursului';
$string['step_digest'] = 'Rezumatul documentelor';
$string['step_outline'] = 'Structura cursului';
$string['step_questions'] = 'Întrebări de quiz';
$string['step_repair'] = 'Repararea JSON';
$string['step_review'] = 'Revizuire';
$string['step_sections'] = 'Conținutul secțiunilor';
$string['task_ingestsources'] = 'Extrage textul din fișierele sursă';
