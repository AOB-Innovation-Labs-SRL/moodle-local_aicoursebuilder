---
description: Review complet în cod al unui PR (precheck scriptat + citire țintită + sabotaj țintit). Folosire: /review-pr 89
argument-hint: <număr PR>
---

Fă review complet, în COD, pentru PR-ul #$ARGUMENTS din AOB Academy (`local_aicoursebuilder`). Fii critic: nu lua partea autorului și nu te baza pe descrierea PR-ului, comentarii sau raport — verifici tu în cod. Nu modifica și nu posta nimic pe PR / ClickUp / branch-ul autorului.

Lucrează în ordinea de mai jos. **Nu lansa subagenți**, cu o singură excepție (pasul 1). `gh` trebuie să fie instalat și autentificat (`gh auth login`); scripturile îl găsesc singure, iar în comenzile tale îl chemi ca `gh` sau, pe Windows dacă nu e în PATH, `"C:\Program Files\GitHub CLI\gh.exe"`; comenzile rulează în Git Bash. Regulile proiectului sunt în [AGENTS.md](AGENTS.md); mediul Docker în AGENTS.md (Local environment) și README.md (Verification script). Un singur container Docker deservește verificările: nu rula două review-uri sau `scripts/verifica` în paralel cu precheck-ul (folosesc același arbore `/opt/moodle-fast`). Dacă precheck-ul spune că arborele rapid lipsește, rulează o dată `scripts/verifica rapid` pe branch-ul tău, apoi reiei.

## 1. Pornește verificările mecanice, apoi citește cerințele
1. Pornește IMEDIAT, în fundal (`run_in_background`), din rădăcina repo-ului:
   `bash scripts/review/precheck.sh $ARGUMENTS "$SCRATCH/review-pr-$ARGUMENTS"` (`$SCRATCH` = scratchpad-ul sesiunii).
   Face: fetch al PR-ului, merge local cu `origin/main` într-un worktree separat, zone sensibile, AGENTS.md / README.md / docs atinse, paritate lang en/ro, prompturi existente modificate, `amd/src` fără `amd/build`, linii adăugate cu API-uri interzise / secrete, starea CI (`gh pr checks`), apoi php -l, phpcs pe fișierele schimbate și suita PHPUnit completă pe rezultatul merge-ului, în container. Scrie `summary.md`. **Nu rula tu aceleași lucruri; nu aștepta și nu face polling** — lucrezi la pașii 2–4 cât timp rulează, iar la final citești `summary.md`.
2. Taskul ClickUp: id din descrierea PR-ului, din numele branch-ului sau din commit-uri (`pr.json` din directorul de ieșire are corpul și branch-ul). Singura excepție la „fără subagenți”: lansează **un singur** agent cu `model: "haiku"` care citește cu uneltele ClickUp (uneltele ClickUp MCP; încarcă-le cu ToolSearch `select:`) descrierea, checklist-urile, **dependențele** (`include: dependencies`, cu statusul fiecărui task blocant), TOATE comentariile (inclusiv threaded) și atașamentele, și întoarce DOAR: lista numerotată de cerințe explicite/implicite (criterii de acceptare, decizii de produs din comentarii, ce e marcat „nu atinge”) + deciziile luate de autor în rapoarte + blocanții cu statusul lor. Fără text în plus. Un task cu blocant care nu e „complete” (sau al cărui PR nu e în `main`) = remarcă în raport: PR-ul s-a făcut peste un task încă blocat și poate duplica / contrazice munca blocantului. Dacă PR-ul nu are task, sari peste.
3. Autor: din `pr.json` (`author.login`); adresează-l pe nume în pasul 7.

## 2. Citire țintită (nu integrală)
- `pr.diff` din directorul de ieșire e punctul de plecare. Citește **integral** doar fișierele care: conțin logică nouă de securitate / acces / date / SQL, au hunk-uri unde contextul din diff nu ajunge, sau sunt zone sensibile (`db/`, `classes/external/`, `classes/privacy/`, `lib.php`, `settings.php`, `deploy.sh`, `.github/`). Restul (șabloane banale, lang, teste) — din diff.
- Citește AGENTS.md și verifică că PR-ul îl actualizează (și README.md / docs/) când schimbă ce afirmă ele (precheck-ul listează).
- Schimbări de contract (semnături, valori întoarse de funcțiile externe, structura blueprint-ului / schemei JSON, setări, tabele): `grep` în tot repo-ul după consumatori (PHP, `amd/src`, șabloane, teste din alte directoare).

## 3. Matrice cerință → cod → test
Pentru FIECARE cerință din task: unde e (fișier:linie), ce test o acoperă, verdict ✔ / ✘ / ◐. Plus: cerințe omise, scope creep, decizii de produs luate de autor fără acord în task, comportament existent schimbat (spus în raport + documentat/testat?).

## 4. Verificări de conținut (cele pe care scriptul nu le poate face)
Numai ce ține de PR-ul curent; criteriile sunt cele din „Definition of done”, „Forbidden” și „Allowed APIs” din AGENTS.md:
- Texte vizibile: doar în `lang/en` și `lang/ro`, cu paritate; niciun text direct în PHP, JS sau șabloane.
- Acces: capability-uri din `db/access.php` verificate în funcțiile externe și paginile noi (`require_capability`, `validate_context`, `require_sesskey` unde e cazul); un job / blueprint al altui utilizator sau al altui curs → refuzat, cu test.
- Stil și documentare: moodle-cs fără avertismente, PHPDoc complet, un singur namespace / o clasă per fișier.
- API-uri: doar cele permise; nimic din „Forbidden” (apeluri AI sincrone într-un request web, chei API în repo / JS / loguri, `save_question()`, MBZ, etc.); secretele stocate prin `\core\encryption`; HTTP prin `\core\http_client`.
- Tabele / schemă: `db/install.xml` și `db/upgrade.php` împreună, `version.php` crescut, pași idempotenți, fără pierdere de date; tabel nou cu `userid` → provider Privacy API + test.
- Prompturi: o versiune care a rulat nu se editează (listate de precheck); schimbarea e un fișier `*.vN+1.md`, iar versiunea intră în hash-ul pasului.
- `amd/src` schimbat → `amd/build` reconstruit (CI Grunt); cod în `thirdparty/` → licență și `thirdpartylibs.xml`.
- Ecran nou → scenariu Behat; PHPUnit în același PR cu codul.
- Teste noi care ar putea trece izolat și pica în suită (fixture-uri partajate, `resetAfterTest`, configurări globale lăsate, ora / limba): judecă din cod; suita completă din precheck e dovada.

## 5. Sabotaj țintit (obligatoriu, dar economic)
Alege **doar garanțiile noi cu risc**: capability / acces, validarea parametrilor în funcțiile externe, Privacy API, pași de upgrade, reguli de date, și orice afirmă un AGENTS.md nou. Garanțiile banale (text, ordine, cosmetică) sărite — spune că le-ai sărit. Pentru fiecare, după ce suita s-a terminat (arborele din container are acum rezultatul merge-ului — nu în paralel cu ea):
`bash scripts/review/sabotage.sh <fișier> '<sed cu delimitator #>' "<filtru PHPUnit>"` — rulează DOAR testele filtrate, revine singur, raportează PICAT / NU A PICAT / NEAPLICAT / FARA TESTE. Secvențial, câte unul. Tabel final: garanție | sabotaj | rezultat. Un test care rămâne verde la sabotaj = problemă.

## 5b. UI (doar dacă PR-ul atinge `templates/`, `amd/src` sau paginile `wizard.php`, `review.php`, `index.php`, `job.php`, `usage.php`)
Verifică că ecranul nou are scenariu Behat și că rezultatul lui din CI (`gh pr checks`, secțiunea CI din `summary.md`) e verde; nu rulezi UI-ul local. Spune la „Ce NU am verificat” că UI-ul nu a fost verificat vizual.

## 6. Raport (în română, 5 părți)
1. **Verdict**: APROBAT / APROBAT CU REMARCI MINORE / RESPINS + 2–3 propoziții.
2. **Ce am confirmat** (dovadă: fișier:linie, comandă, rezultat). Numărul de teste = cel **măsurat** în `summary.md`. Un test care pică în suită: verifică întâi pe `origin/main` curat dacă nu e al lui `main` (atunci îl notezi la „Probleme preexistente”).
3. **Probleme noi** pe severitate (blocker / major / minor): fișier:linie, scenariu concret de eșec, testul care ar trebui să pice fără fix.
4. **Probleme preexistente** (separat, nu în sarcina PR-ului).
5. **Ce NU am verificat**, și ce am rulat eu vs. luat din comentarii.
Include matricea din pasul 3 și tabelul de sabotaj din pasul 5.

## 7. Încheiere (după autor)
FĂRĂ comenzi `/agent`. (1) Comentariu GitHub gata de lipit, adresat dezvoltatorului pe nume (ce e bine / blockere numerotate cu fișier:linie / ce trebuie clarificat / ce cer la predare), oferit ca `gh pr review $ARGUMENTS --request-changes --body-file …` (sau `--approve` dacă verdictul e aprobat), NEpostat până nu zic eu; (2) un prompt separat pentru Claude Code-ul lui: merge / conflicte cu `main` întâi, apoi fixurile, apoi `scripts/verifica rapid` + tabel de sabotaj la predare.

## 8. Salvează review-ul (pentru `/recheck-pr`)
Înainte de curățare, creează `.claude/reviews/pr-$ARGUMENTS/` (în checkout-ul principal; e în `.gitignore`) și pune acolo:
- `report.md` — raportul complet din pasul 6, cu problemele **numerotate stabil** (B1, B2 = blockere, M1 = majore, m1 = minore), fiecare cu fișier:linie, scenariu și testul care trebuie să pice fără fix; plus matricea, tabelul de sabotaj și lista „ce NU am verificat”;
- `pr.diff`, `head.sha`, `summary.md` din directorul de ieșire al precheck-ului.
Spune-i utilizatorului că după ce colegul predă din nou, comanda este `/recheck-pr $ARGUMENTS`.

La final curăță: `bash scripts/review/cleanup.sh "$SCRATCH/review-pr-$ARGUMENTS/wt" $ARGUMENTS` (șterge worktree-ul și pune la loc codul tău în arborele din container), apoi șterge restul scratch-ului. Nu lăsa nimic modificat în repo-ul principal.
