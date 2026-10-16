---
description: Re-verifică un PR după reparații — doar modificările de la ultimul review, nu totul de la zero. Folosire: /recheck-pr 89
argument-hint: <număr PR>
---

Re-verificare a PR-ului #$ARGUMENTS după ce autorul a predat din nou. Există deja un review anterior, salvat de `/review-pr` în `.claude/reviews/pr-$ARGUMENTS/` (`report.md`, `pr.diff`, `head.sha`). **Nu reface review-ul complet**: verifici (a) fiecare problemă din review-ul anterior, (b) codul NOU din reparații, (c) că suita rămâne verde pe rezultatul merge-ului. Restul se bazează pe review-ul anterior și spui asta explicit. Fii la fel de critic ca în `/review-pr`; nu lua cuvântul autorului. Nu modifica și nu posta nimic pe PR / ClickUp / branch-ul autorului. **Fără subagenți.** `gh` trebuie să fie instalat și autentificat (`gh auth login`); scripturile îl găsesc singure, iar în comenzile tale îl chemi ca `gh` sau, pe Windows dacă nu e în PATH, `"C:\Program Files\GitHub CLI\gh.exe"`; comenzile rulează în Git Bash. Regulile proiectului sunt în [AGENTS.md](AGENTS.md). Rulează din **checkout-ul principal** (de acolo se citește `.claude/reviews/`). Un singur container Docker deservește verificările: nu rula două review-uri sau `scripts/verifica` în paralel cu precheck-ul (folosesc același arbore `/opt/moodle-fast`).

Dacă `.claude/reviews/pr-$ARGUMENTS/report.md` lipsește: spune-i utilizatorului că nu există un review salvat și oprește-te (propune `/review-pr $ARGUMENTS`).

## 1. Pornește verificările, apoi citește review-ul anterior
1. Imediat, în fundal (`run_in_background`), din rădăcina repo-ului:
   `bash scripts/review/precheck.sh $ARGUMENTS "$SCRATCH/recheck-pr-$ARGUMENTS" ".claude/reviews/pr-$ARGUMENTS"`
   Face merge-ul local cu `origin/main`, verificările mecanice și suita PHPUnit completă pe rezultat, și produce `delta-files.txt` + `delta.diff` (diferența dintre diff-ul PR-ului de la review și cel de acum — ce a venit doar prin merge cu `main` nu apare). Dacă PR-ul nu s-a schimbat (același sha), scriptul o spune: raportează „nimic nou” și oprește-te. Nu aștepta și nu face polling.
2. Citește `report.md`: verdictul, problemele numerotate, tabelul de sabotaj, „ce NU am verificat”. Citește și răspunsurile autorului **de după review**: `gh pr view $ARGUMENTS --json comments,reviews,commits` (doar ce e posterior review-ului — data e în `report.md`) și, dacă taskul ClickUp are comentarii noi, doar pe cele noi. Notează ce susține autorul că a rezolvat — verifici în cod, nu iei de bună.
3. Autor: din `pr.json` (`author.login`); adresează-l pe nume în pasul 6.

## 2. Verifică problemele vechi, una câte una
Pentru fiecare problemă din `report.md`: verdict **REZOLVATĂ / PARȚIAL / NEREZOLVATĂ / ACCEPTATĂ** cu dovadă (fișier:linie în versiunea nouă din worktree + testul care o acoperă). „Rezolvată” cere și un test care pică fără reparație. Dacă autorul a ales altă soluție decât cea cerută, judecă soluția, nu conformitatea literală.
Citește integral numai fișierele atinse de reparații; restul din `delta.diff`.

## 3. Cod nou = review nou, dar numai pe delta
Parcurge `delta.diff` ca pe un PR mic: bug-uri introduse de reparații, scope creep („am mai schimbat și…”), comportament schimbat tăcut, acces / capability-uri / lang en+ro / Privacy API / pași de upgrade / prompturi versionate (criteriile din `/review-pr`, pasul 4) — doar pentru liniile din delta. Verifică și că reparația unei probleme n-a stricat o garanție verificată înainte (caută consumatorii funcțiilor/contractelor modificate în delta, `grep` în repo). Fișierele noi din PR (`NOU`) se citesc integral.

## 4. Sabotaj numai pe ce e nou
Doar pentru testele **adăugate sau schimbate în delta** (cele care pretind că acoperă problemele vechi sau codul nou): `bash scripts/review/sabotage.sh <fișier> '<sed cu #>' "<filtru PHPUnit>"`, secvențial, după ce suita din precheck s-a terminat (arborele din container are rezultatul merge-ului). Nu repeta sabotajele din review-ul anterior pentru cod neatins de delta — treci-le în tabel ca „neatins, rezultat preluat din review-ul anterior”. Tabel: garanție | sabotaj | rezultat | (nou / preluat).

## 5. Raportul (română, scurt, doar delta)
1. **Verdict nou**: APROBAT / APROBAT CU REMARCI MINORE / ÎNCĂ RESPINS + 2–3 propoziții.
2. **Probleme vechi**: tabel `ID | problemă | stare nouă | dovadă (fișier:linie, test)`.
3. **Probleme noi** (introduse de reparații), pe severitate, cu fișier:linie, scenariu concret, testul care ar trebui să pice fără fix. Continuă numerotarea (B3, M2…).
4. **Rezultate rulate de mine acum**: numărul de teste PHPUnit **măsurat** pe merge (un test care pică: verifică întâi pe `origin/main` curat), merge curat sau nu, starea CI din `gh pr checks`, sabotajele noi.
5. **Ce NU am reverificat** (părțile neatinse de delta, preluate din review-ul de la sha-ul `<head.sha vechi>`) și ce n-am putut verifica.

## 6. Încheiere (după autor)
FĂRĂ comenzi `/agent`.
- **Verdict aprobat**: comentariu scurt de aprobare pentru PR, oferit ca `gh pr review $ARGUMENTS --approve --body-file …`, NEpostat până nu zic eu.
- **Încă respins**: comentariu GitHub gata de lipit, adresat pe nume (ce s-a rezolvat, ce a rămas, ce e nou), oferit ca `gh pr review $ARGUMENTS --request-changes --body-file …`, NEpostat până nu zic eu, plus un prompt pentru Claude Code-ul lui, doar cu ce a rămas.

## 7. Salvează și curăță
Actualizează `.claude/reviews/pr-$ARGUMENTS/`: păstrează `report.md` vechi ca `report-<sha scurt vechi>.md`, scrie noul `report.md` (starea curentă a TUTUROR problemelor, nu doar a delta — ca următorul recheck să plece de acolo), și copiază `pr.diff`, `head.sha`, `summary.md` din directorul nou. Apoi `bash scripts/review/cleanup.sh "$SCRATCH/recheck-pr-$ARGUMENTS/wt" $ARGUMENTS` și șterge restul scratch-ului. Nu lăsa nimic modificat în repo-ul principal.
