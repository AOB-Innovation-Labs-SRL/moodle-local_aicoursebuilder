---
description: Commit, push pe branch-ul tău, PR către main, comentariu pe task + status „pm check”. Folosire: /task-pr [id sau nume task]
argument-hint: [id sau nume task, dacă nu reiese din conversație]
---

Lucrezi pe branch-ul tău propriu (nu `main`). `gh` trebuie să fie instalat și autentificat. Predai taskul ($ARGUMENTS; dacă e gol, e cel din conversație). Răspunde în română, scurt.

1. `git fetch origin && git merge origin/main` pe branch-ul curent (AGENTS.md, „Verification before pushing”); conflicte rezolvate primele (lang en/ro: păstrezi toate cheile; AGENTS.md / README.md: unești listele; `amd/build`: reconstruiești, nu uni manual). Dacă merge-ul a adus schimbări în zona ta, rulezi `scripts/verifica schimbari`, nu suita.
2. Commit pe branch-ul curent (mesaj cu atribuirea din system-reminder), `git push -u origin <branch-ul curent>` — niciodată pe `main`.
3. PR către **`main`**: `gh pr create --base main --body-file <fișier în scratchpad>`, cu structura PR-urilor anterioare (`gh pr list --state merged --limit 2` și citești corpul unuia). Corpul include: ce s-a făcut, ce se comportă altfel și de ce, rezultatele măsurate ale verificării, **ce NU s-a verificat**.
4. Verifici `gh pr view <n> --json mergeStateStatus` → `CLEAN`.
5. Comentariu pe taskul ClickUp cu rezumatul + link PR, status „pm check”.
6. Raportezi link-ul PR-ului, într-o singură propoziție + `mergeStateStatus` + starea CI din `gh pr checks` (CI rulează pe PR; nu da Re-run pe run-uri vechi, un push nou anulează run-ul în curs).

NU împingi pe `main`; singurul PR e către `main`, de pe branch-ul tău.
