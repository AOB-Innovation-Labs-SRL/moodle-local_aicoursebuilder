---
description: PR aprobat: task „complete”, aduce main în branch-ul tău. Folosire: /task-done [id sau nume task]
argument-hint: [id sau nume task]
---

PR-ul taskului ($ARGUMENTS; dacă e gol, cel din conversație) a fost aprobat. Răspunde în română, în 2–3 rânduri.

1. Pune taskul ClickUp pe „complete”.
2. Pe branch-ul tău propriu (nu `main`): `git fetch origin`; verifici `gh pr view <branch> --json state` — dacă PR-ul nu e încă `MERGED`, spui asta și te oprești aici (nu atingi branch-ul). Dacă e: `git merge origin/main` (PR-urile se îmbină squash, deci merge, nu ff), apoi `git push origin <branch>`; dacă apar conflicte sau ai modificări necomise, oprește-te și spune.
3. Nu atingi `main`.
