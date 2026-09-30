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
$string['budgetexceeded'] = 'S-a atins plafonul de cost AI la nivel de {$a->scope} (plafon {$a->limit} USD).';
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
$string['parallelismheading'] = 'Paralelism și reîncercare';
$string['parallelismheading_desc'] = 'Setări de concurență și reîncercare pentru sub-apelurile AI independente (spec 3.8).';
$string['pdfencrypted'] = 'PDF-ul este criptat. Elimină protecția și încarcă din nou fișierul.';
$string['pdftotextpath'] = 'Calea către pdftotext';
$string['pdftotextpath_desc'] = 'Calea completă a programului pdftotext (Poppler), de exemplu /usr/bin/pdftotext. Este fallback pentru fișierele PDF și este necesară și pentru fallback-ul LibreOffice. Lasă gol pentru a dezactiva.';
$string['peakwindows_json'] = 'Ferestre de vârf: {$a}';
$string['peakwindows_json_desc'] = 'Obiect JSON care leagă ziua săptămânii ISO (1 = luni .. 7 = duminică) de o listă de intervale [ora_start, ora_sfârșit) UTC, de exemplu {"1": [[1, 4], [6, 10]]}. Gol sau invalid revine la programul pilot DeepSeek (spec 8): 01:00-04:00 și 06:00-10:00 UTC, luni-vineri.';
$string['pluginname'] = 'AI Course Builder';
$string['pool_concurrency'] = 'Sub-apeluri AI paralele';
$string['pool_concurrency_desc'] = 'Numărul de sub-apeluri independente (secțiuni, întrebări per secțiune) rulate concurent într-un singur task. Limitat la 1-6.';
$string['pricing_json'] = 'Prețuri: {$a}';
$string['pricing_json_desc'] = 'Obiect JSON care leagă numele modelului de prețuri per 1.000.000 tokeni: input_miss, input_hit, output, și opțional un obiect offpeak cu aceleași 3 chei. Gol sau invalid revine la prețurile implicite de mai jos.<br>Implicit: {$a}';
$string['pricingheading'] = 'Estimarea costului';
$string['pricingheading_desc'] = 'Prețuri per model și ferestre de vârf folosite pentru a calcula costul real al unui apel și pentru a-l estima înainte de trimitere. Sumele sunt în USD per 1.000.000 tokeni.';
$string['pricinginvalidjson'] = 'Acesta nu este un tabel de prețuri valid. Se folosesc prețurile implicite până la corectare.';
$string['privacy:metadata:aiprovider:anthropic'] = 'Anthropic primește promptul profesorului și textul surselor pentru a genera conținutul cursului.';
$string['privacy:metadata:aiprovider:deepseek'] = 'DeepSeek primește promptul profesorului și textul surselor pentru a genera conținutul cursului.';
$string['privacy:metadata:aiprovider:gemini'] = 'Google Gemini primește promptul profesorului și textul surselor pentru a genera conținutul cursului.';
$string['privacy:metadata:aiprovider:language'] = 'Limba cursului de generat.';
$string['privacy:metadata:aiprovider:openaicompat'] = 'Un furnizor compatibil OpenAI primește promptul profesorului și textul surselor pentru a genera conținutul cursului.';
$string['privacy:metadata:aiprovider:prompt'] = 'Promptul scris de profesor.';
$string['privacy:metadata:aiprovider:sourcecontent'] = 'Textul extras din fișierele sursă încărcate de profesor.';
$string['privacy:metadata:core_ai'] = 'Cererile trimise prin subsistemul AI Moodle sunt tratate de pluginul furnizorului AI configurat.';
$string['privacy:metadata:core_files'] = 'Fișierele sursă încărcate pentru un job sunt păstrate în stocarea de fișiere Moodle.';
$string['privacy:metadata:local_aicb_ailog'] = 'Jurnalul apelurilor AI: tokeni, cost și model, niciodată promptul sau răspunsul.';
$string['privacy:metadata:local_aicb_ailog:connector'] = 'Conectorul folosit pentru apel.';
$string['privacy:metadata:local_aicb_ailog:cost'] = 'Costul apelului, în USD.';
$string['privacy:metadata:local_aicb_ailog:model'] = 'Modelul folosit pentru apel.';
$string['privacy:metadata:local_aicb_ailog:timecreated'] = 'Momentul apelului.';
$string['privacy:metadata:local_aicb_ailog:tokens'] = 'Numărul de tokeni trimiși și primiți.';
$string['privacy:metadata:local_aicb_ailog:userid'] = 'Utilizatorul care a pornit apelul.';
$string['privacy:metadata:local_aicb_blueprint'] = 'Versiunile blueprint-ului de curs generate și editate pentru un job.';
$string['privacy:metadata:local_aicb_blueprint:approvedby'] = 'Utilizatorul care a aprobat blueprint-ul.';
$string['privacy:metadata:local_aicb_blueprint:content'] = 'Blueprint-ul, inclusiv conținutul generat al cursului.';
$string['privacy:metadata:local_aicb_blueprint:timeapproved'] = 'Momentul aprobării blueprint-ului.';
$string['privacy:metadata:local_aicb_blueprint:usermodified'] = 'Utilizatorul care a modificat ultima dată blueprint-ul.';
$string['privacy:metadata:local_aicb_budget'] = 'Cheltuielile lunare AI ale unui utilizator.';
$string['privacy:metadata:local_aicb_budget:limitusd'] = 'Plafonul lunar stabilit pentru utilizator, în USD.';
$string['privacy:metadata:local_aicb_budget:period'] = 'Luna cheltuielilor.';
$string['privacy:metadata:local_aicb_budget:spentusd'] = 'Suma cheltuită în lună, în USD.';
$string['privacy:metadata:local_aicb_budget:userid'] = 'Utilizatorul care a cheltuit suma.';
$string['privacy:metadata:local_aicb_chunk'] = 'Bucăți din textul extras din fișierele sursă ale unui job.';
$string['privacy:metadata:local_aicb_chunk:content'] = 'Textul bucății.';
$string['privacy:metadata:local_aicb_chunk:title'] = 'Cel mai apropiat titlu al bucății.';
$string['privacy:metadata:local_aicb_job'] = 'Joburile de generare a cursurilor pornite de profesori.';
$string['privacy:metadata:local_aicb_job:brief'] = 'Brief-ul cursului, confirmat de profesor.';
$string['privacy:metadata:local_aicb_job:courseid'] = 'Cursul pentru care jobul generează conținut.';
$string['privacy:metadata:local_aicb_job:prompt'] = 'Promptul scris de profesor.';
$string['privacy:metadata:local_aicb_job:timecreated'] = 'Momentul creării jobului.';
$string['privacy:metadata:local_aicb_job:userid'] = 'Utilizatorul căruia îi aparține jobul.';
$string['privacy:metadata:local_aicb_source'] = 'Fișierele sursă încărcate pentru un job.';
$string['privacy:metadata:local_aicb_source:digest'] = 'Rezumatul textului sursei.';
$string['privacy:metadata:local_aicb_source:filename'] = 'Numele fișierului încărcat.';
$string['privacy:metadata:local_aicb_source:filesize'] = 'Dimensiunea fișierului încărcat.';
$string['privacy:metadata:local_aicb_source:mimetype'] = 'Tipul fișierului încărcat.';
$string['privacy:metadata:local_aicb_step'] = 'Punctele de control ale pașilor de generare AI ai unui job.';
$string['privacy:metadata:local_aicb_step:cost'] = 'Costul pasului, în USD.';
$string['privacy:metadata:local_aicb_step:output'] = 'Conținutul generat de pas.';
$string['privacy:metadata:local_aicb_step:step'] = 'Numele pasului.';
$string['privacy:path:ailog'] = 'Apeluri AI';
$string['privacy:path:budget'] = 'Buget AI';
$string['privacy:path:files'] = 'Fișiere sursă';
$string['privacy:path:jobs'] = 'Joburi';
$string['retry_basedelayms'] = 'Întârzierea de bază la reîncercare (ms)';
$string['retry_basedelayms_desc'] = 'Întârzierea de bază înainte de prima reîncercare, dublată la fiecare încercare ulterioară, cu variație aleatorie (jitter). Ignorată când furnizorul trimite un header Retry-After.';
$string['retry_maxattempts'] = 'Număr maxim de încercări';
$string['retry_maxattempts_desc'] = 'Numărul de încercări per apel, inclusiv prima, înainte de a renunța (doar 429, 500, 502, 503, 504 și erori de rețea; celelalte erori nu se reîncearcă).';
$string['route_connector'] = 'Conector: {$a}';
$string['route_model'] = 'Model: {$a}';
$string['route_model_desc'] = 'Lasă gol pentru modelul implicit al conectorului. Ignorat pentru subsistemul AI Moodle.';
$string['routesheading'] = 'Rute per pas';
$string['routesheading_desc'] = 'Conectorul și modelul pentru fiecare pas de generare. Valorile goale revin la cele implicite de mai sus. Subsistemul AI Moodle folosește furnizorul și modelul configurate în setările AI ale site-ului.';
$string['settings:alertpercent'] = 'Prag de alertă pentru buget (%)';
$string['settings:alertpercent_desc'] = 'Managerii sunt notificați când un buget lunar atinge acest procent din plafon.';
$string['settings:joblimitusd'] = 'Plafon per job (USD)';
$string['settings:joblimitusd_desc'] = 'Un job al cărui cost estimat depășește această valoare nu pornește. 0 înseamnă fără plafon.';
$string['settings:jobretentiondays'] = 'Retenția joburilor (zile)';
$string['settings:jobretentiondays_desc'] = 'Joburile terminate, eșuate și anulate sunt șterse împreună cu sursele și datele intermediare după acest număr de zile. 0 le păstrează.';
$string['settings:limitsheading'] = 'Plafoane de cost';
$string['settings:limitsheading_desc'] = 'Sumele sunt în USD. 0 înseamnă fără plafon.';
$string['settings:sitelimitusd'] = 'Plafon lunar pentru tot situl (USD)';
$string['settings:sitelimitusd_desc'] = 'Cheltuiala AI totală permisă pe lună pe acest sit.';
$string['settings:userlimitusd'] = 'Plafon lunar per utilizator (USD)';
$string['settings:userlimitusd_desc'] = 'Cheltuiala AI permisă per utilizator și lună. Un plafon stabilit pentru un singur utilizator îl înlocuiește.';
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
$string['token_estimator_charsperfactor'] = 'Caractere per token: {$a}';
$string['token_estimator_charsperfactor_desc'] = 'Factor euristic (lungimea textului în caractere împărțită la acesta) folosit pentru a estima numărul de tokeni ai unei cereri înainte de trimitere, pentru estimarea costului și rezervarea din buget.';
