---
description: Ia un task din ClickUp, planifică (Plan Mode), implementează și verifică. Folosire: /task numele taskului
argument-hint: <numele taskului din ClickUp>
---

TASK: $ARGUMENTS

Lucrezi pe AOB Academy (pluginul Moodle `local_aicoursebuilder`), **pe branch-ul tău propriu** (fiecare dev își are branch-ul lui, creat din `main`; PR-urile merg doar spre `main`). Task-urile sunt în ClickUp, lista „Dev Tasks”, sub părintele lor; taskurile `[DEV]` și `[SR]` se tratează identic. Răspunde în română, scurt: fără recapitulări, fără narațiune între pași. Regulile proiectului (stil, API-uri permise / interzise, definiția „gata”, verificări) sunt în [AGENTS.md](AGENTS.md); mediul Docker local în AGENTS.md (Local environment) și README.md (Verification script). Nu face polling și nu scrie nimic cât rulează joburile lungi. Pentru ce e deja în `AGENTS.md` nu citi cod ca să „te orientezi”.

## 1. Task și plan
1. Singura delegare permisă (și obligatorie): un agent `model: "haiku"` care, cu uneltele ClickUp, găsește taskul după nume, citește descrierea, părintele, checklist-urile, **dependențele** (`include: dependencies`: pentru fiecare „waiting on” citește statusul taskului blocant), TOATE comentariile (inclusiv threaded) și atașamentele, și întoarce DOAR: id-ul taskului, lista numerotată de cerințe explicite/implicite (criterii, decizii de produs din comentarii, ce e marcat „nu atinge”), lista blocanților cu statusul lor, ambiguități. Fără text în plus. **Dacă vreun blocant nu e „complete”, oprește-te înainte de pasul 2** și spune care (nu pui taskul „in progress”, nu planifici); continui doar dacă îmi spui explicit să trec peste. Cât rulează, nu citi nimic în paralel din repo pe tema asta.
2. Pune taskul pe „in progress” (tu, un singur apel).
3. Branch-ul: rămâi pe branch-ul curent (`git branch --show-current`); dacă e `main`, oprește-te și întreabă pe care lucrăm. `git fetch origin && git merge origin/main` (fără force); dacă apar conflicte, oprește-te și spune. Un singur container Docker deservește checkout-ul principal, deci verificările (pasul 3) rulează de aici, nu dintr-un worktree separat.
4. `EnterPlanMode`. Explorează **tu** codul, țintit (Grep/Glob, citiri parțiale), **fără alți subagenți**. Planul: fișiere de atins, teste noi (PHPUnit în același PR; Behat pentru fiecare ecran nou; acces: un job / blueprint al altui utilizator sau fără capability = refuzat), garanțiile noi pentru sabotaj, ce se actualizează în AGENTS.md / README.md / docs/, ce NU intră în scope. Un rând „Model și efort recomandat” (justificat pe cost). Ambiguități → `AskUserQuestion`, nu presupune. Cere aprobarea cu `ExitPlanMode`. Nu implementa nimic înainte. Dacă e respins: nu implementa, întreabă ce s-a schimbat.

## 2. Implementare
- Lucrezi singur. Texte vizibile doar în `lang/en` și `lang/ro` (niciodată în PHP, JS sau șabloane); PHPDoc complet; moodle-cs fără erori și avertismente; doar API-urile permise din AGENTS.md (nimic din „Forbidden”); apeluri AI doar prin ad-hoc tasks, niciodată sincron într-un request web; tabel nou cu `userid` → provider Privacy API; schimbare de schemă → `db/install.xml` + `db/upgrade.php` + `version.php`; prompturile din `prompts/` sunt versionate (o versiune care a rulat nu se mai editează, se adaugă `*.vN+1.md`).
- Repo-ul e LF (`.gitattributes`). Dacă modifici `amd/src`, reconstruiești `amd/build` (CI verifică cu Grunt; versiunile exacte sunt cele din `moodle-env/moodle502/npm-shrinkwrap.json`).
- După fiecare pas: `scripts/verifica stil`. În timpul lucrului nu rulezi suita completă; ea se rulează o singură dată, la pasul 3.
- Actualizezi AGENTS.md / README.md / docs/ unde schimbi ce afirmă ele. Numerele de teste le scrii doar după ce le-ai **măsurat** la pasul 3.

## 3. Verificare (înainte să mă anunți)
Ordinea contează: pornești ce e lung în fundal, apoi lucrezi la restul.
1. **În fundal** (`run_in_background`): `scripts/verifica rapid > "$SCRATCH/verify.log" 2>&1` (php -l, phpcs și suita PHPUnit completă, ~4 minute; Docker Desktop trebuie pornit — dacă e oprit, îl pornești tu și reiei).
2. **Nu face polling și nu scrie nimic cât rulează** — o singură raportare când se termină, citind **doar** secțiunea `== Summary ==` din `verify.log` (jurnalul complet doar pentru un test picat). Exit 2 = mediu (Docker / container), nu cod. Nu rula suita altfel o dată în plus.
3. Sabotaje, **doar pentru garanțiile noi cu risc** (capability / acces, validarea parametrilor și sesskey în funcțiile externe, Privacy API, pași de upgrade, reguli de date, ce afirmă un AGENTS.md nou); cele banale (text, cosmetică) le sari și spui asta. După ce suita s-a terminat (arborele din container are acum codul tău), secvențial:
   `bash scripts/review/sabotage.sh <fișier> '<sed cu delimitator #>' "<filtru PHPUnit>"` — modifică doar copia din container, rulează doar testele filtrate și revine singur; rezultat PICAT / NU A PICAT / NEAPLICAT / FARA TESTE.
   Un sabotaj care nu pică → repari testul și repeți. Tabel: garanție | sabotaj | rezultat.
4. Ecran nou sau modificat → scenariu Behat în PR; Behat rulează local cu `scripts/verifica complet` (dacă baza Behat e inițializată) și în CI. Nu rula nimic pe serverul de test `aicb.aoblabs.ro` (`deploy.sh`) fără să mă întrebi.
5. Test existent modificat → spui de ce. Comportament existent schimbat → spui explicit ce și de ce.

## 4. Raport
Ce a trecut (cu numere măsurate), ce a picat, **ce NU ai verificat** (ex. grunt, mustache, phpdoc, savepoints rulează doar în CI), comportamente schimbate, descoperiri din afara scope-ului (le raportezi, nu le rezolvi). Apoi stai: commit/PR doar la `/task-pr`; la sfârșit (după aprobare) `/task-done`.

## Reguli
- Fără acțiuni greu de reversat sau vizibile în exterior (server de test, producție, ștergeri, trimiteri) fără să mă întrebi.
- Niciun commit, push sau PR înainte de `/task-pr`; push doar pe branch-ul tău, niciodată pe `main` sau pe branch-ul altui dev.
