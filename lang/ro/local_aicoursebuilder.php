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
$string['messageprovider:jobfailed'] = 'Job AI Course Builder eșuat';
$string['messageprovider:jobfinished'] = 'Job AI Course Builder finalizat';
$string['notimplemented'] = 'Această funcționalitate nu este încă implementată.';
$string['notjobowner'] = 'Doar proprietarul acestui job îl poate modifica.';
$string['pluginname'] = 'AI Course Builder';
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
$string['privacy:path:jobs'] = 'Joburi';
$string['settings:alertpercent'] = 'Prag de alertă pentru buget (%)';
$string['settings:alertpercent_desc'] = 'Managerii sunt notificați când un buget lunar atinge acest procent din plafon.';
$string['settings:joblimitusd'] = 'Plafon per job (USD)';
$string['settings:joblimitusd_desc'] = 'Un job al cărui cost estimat depășește această valoare nu pornește. 0 înseamnă fără plafon.';
$string['settings:jobretentiondays'] = 'Retenția joburilor (zile)';
$string['settings:jobretentiondays_desc'] = 'Joburile terminate, eșuate și anulate sunt șterse împreună cu sursele și datele intermediare după acest număr de zile. 0 le păstrează.';
$string['settings:limitsheading'] = 'Plafoane de cost';
$string['settings:limitsheading_desc'] = 'Sumele sunt în USD. 0 înseamnă fără plafon.';
$string['settings:maxuploadmb'] = 'Dimensiunea maximă a unui fișier sursă (MB)';
$string['settings:maxuploadmb_desc'] = 'Cel mai mare fișier pe care un profesor îl poate încărca drept material sursă.';
$string['settings:sitelimitusd'] = 'Plafon lunar pentru tot situl (USD)';
$string['settings:sitelimitusd_desc'] = 'Cheltuiala AI totală permisă pe lună pe acest sit.';
$string['settings:sourcesheading'] = 'Surse și retenție';
$string['settings:userlimitusd'] = 'Plafon lunar per utilizator (USD)';
$string['settings:userlimitusd_desc'] = 'Cheltuiala AI permisă per utilizator și lună. Un plafon stabilit pentru un singur utilizator îl înlocuiește.';
