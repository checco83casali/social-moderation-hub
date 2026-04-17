# Pubblicare su GitHub — Guida completa

Segui questi passi nell'ordine esatto.

---

## 1. Crea il repository su GitHub

1. Vai su [github.com](https://github.com) → accedi
2. Clicca **+** in alto a destra → **New repository**
3. Compila:
   - **Repository name:** `social-moderation-hub`
   - **Description:** `Hybrid AI + Human content moderation for Facebook pages`
   - **Visibility:** Public
   - ⚠️ NON spuntare "Add README", "Add .gitignore" o "Choose license" — li abbiamo già
4. Clicca **Create repository**

---

## 2. Prepara il progetto in locale

Decomprimi lo zip scaricato, poi apri il terminale nella cartella:

```bash
cd social-moderation-hub

# Inizializza git
git init

# Collega al repository GitHub (sostituisci TUONOME)
git remote add origin https://github.com/TUONOME/social-moderation-hub.git
```

---

## 3. Primo commit e push

```bash
git add .
git commit -m "feat: initial release v1.0.0

- Claude Haiku → Sonnet → Human escalation pipeline
- Meta Graph API integration (webhooks, comment moderation)
- OAuth2 login (Google, Meta, Microsoft)
- Context-aware moderation (account age, follower count)
- Scam and grooming pattern detection
- Progressive ban system with recidivism tracking
- Versioned policy management
- Admin dashboard (dark-theme SPA)
- Embeddable JS widget for page connection
- Docker support
- MIT License"

git branch -M main
git push -u origin main
```

Se GitHub chiede credenziali, usa il tuo username e un **Personal Access Token**
(non la password — vai su GitHub → Settings → Developer settings → Personal access tokens → Generate new token).

---

## 4. Configura il repository

Su GitHub, vai al tuo repo → clicca l'ingranaggio ⚙️ accanto ad **About**:

**Description:**
```
Hybrid AI + Human content moderation for Facebook pages · PHP · Claude AI · Self-hosted
```

**Topics** (aggiungili tutti):
```
php moderation ai claude anthropic facebook meta content-moderation
slim-framework mysql self-hosted open-source
```

**Website:** il tuo dominio se ce l'hai

---

## 5. Crea la prima Release

1. Vai su **Releases** (colonna destra) → **Create a new release**
2. **Tag:** `v1.0.0`
3. **Title:** `v1.0.0 — Initial release`
4. **Description:** copia dal CHANGELOG.md la sezione `[1.0.0]`
5. Clicca **Publish release**

---

## 6. Flusso di lavoro continuativo

Ogni volta che lavori con Claude e scarichi file aggiornati:

```bash
# Copia i file modificati nella cartella del progetto, poi:

git add .
git commit -m "feat: descrizione breve della modifica"
git push
```

### Formato commit message

```
feat: nuova funzionalità
fix: correzione bug
docs: aggiornamento documentazione
refactor: ristrutturazione codice senza cambiare funzionalità
style: modifica UI/CSS
```

---

## 7. Aggiornare il CHANGELOG

Ogni modifica significativa va documentata in `CHANGELOG.md` sotto `[Unreleased]`:

```markdown
## [Unreleased]

### Added
- Notifiche Slack quando la coda supera soglia

### Fixed
- Correzione validazione firma webhook Meta
```

Quando rilasci una nuova versione, sposta `[Unreleased]` in `[1.1.0]` con la data.

---

## 8. Dichiarazione AI (best practice)

Il `CONTRIBUTORS.md` già presente nel progetto dichiara correttamente
che lo sviluppo è assistito da Claude (Anthropic), con tu come autore e maintainer.
Questo è lo standard emergente per i progetti open source sviluppati con assistenza AI.
