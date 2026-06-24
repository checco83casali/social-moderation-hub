# Go-Live Checklist

Lista di controllo prima di mettere in produzione Social Moderation Hub.

---

## 1. Configurazione server

- [ ] PHP 8.1+ installato con estensioni: `pdo_mysql`, `mbstring`, `openssl`, `curl`
- [ ] Document root punta a `public/` (non alla root del progetto)
- [ ] HTTPS abilitato con certificato valido
- [ ] Il file `.env` non è accessibile via web (deve essere fuori da `public/`)
- [ ] I permessi di `.env` sono `600` (solo il processo PHP può leggerlo)
- [ ] La cartella `logs/` esiste ed è scrivibile dal processo PHP

---

## 2. Configurazione `.env`

- [ ] `APP_URL` impostato con l'URL pubblico corretto (es. `https://moderation.sanmarinortv.sm`)
- [ ] `APP_SECRET` impostato con una stringa casuale di 64 caratteri (`php -r "echo bin2hex(random_bytes(32));"`)
- [ ] `APP_ENV=production`
- [ ] `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` corretti
- [ ] `ANTHROPIC_API_KEY` impostato (o configurato dalla dashboard)
- [ ] `META_APP_ID`, `META_APP_SECRET` impostati
- [ ] `META_WEBHOOK_VERIFY_TOKEN` impostato (distinto da `APP_SECRET`)
- [ ] `OAUTH_ALLOWED_EMAIL_DOMAINS` impostato (es. `sanmarinortv.sm`) — **fortemente raccomandato**
- [ ] Almeno un provider OAuth configurato (Google, Meta, o Microsoft) — o utente locale creato

---

## 3. Database e installer

- [ ] `php install.php` completato senza errori
- [ ] Tabella `policies` contiene almeno una riga con `is_active = 1`
- [ ] Almeno un utente admin creato (primo login OAuth o insert diretto)

---

## 4. Integrazione Meta

- [ ] App Meta creata su [developers.facebook.com](https://developers.facebook.com) (tipo: Business)
- [ ] `META_APP_ID` e `META_APP_SECRET` nel `.env` corrispondono all'app Meta
- [ ] Prodotto **Webhooks** aggiunto e webhook verificato (URL + token)
- [ ] Prodotto **Facebook Login** aggiunto con URI di callback corretti
- [ ] Pagina Facebook connessa dalla dashboard (Pagine → Connetti pagina)
- [ ] Colonna `webhook_verified = 1` in `connected_pages` per la pagina connessa
- [ ] App Meta in **modalità Live** (non Development) per utenti reali

---

## 5. Sicurezza rete

- [ ] Solo `/webhook/meta` è raggiungibile dall'internet pubblico
- [ ] Dashboard (`/`), API (`/api/`), login (`/auth/`) accessibili solo da IP interni o VPN
- [ ] (Opzionale) `INTERNAL_IP_ALLOWLIST` e/o `PUBLIC_DOMAIN` configurati nel `.env`
- [ ] (Opzionale) `TRUSTED_PROXIES` configurato se c'è un reverse proxy davanti
- [ ] Guida completa: [deployment-security.md](deployment-security.md)

---

## 6. Test funzionale

- [ ] Login dashboard funziona
- [ ] La sezione Impostazioni si apre e mostra i valori corretti
- [ ] Pubblica un commento di test sulla pagina Facebook → appare nella coda entro 30 secondi
- [ ] La moderazione AI processa il commento (controlla il log di moderazione)
- [ ] La decisione AI viene applicata correttamente su Facebook (hide/approve)
- [ ] Dev mode è **disattivata** (`dev_mode = 0` in `app_settings`)

---

## 7. (Opzionale) Auto-deploy via GitHub

- [ ] `GITHUB_WEBHOOK_SECRET` impostato nel `.env`
- [ ] Webhook configurato in GitHub → Settings → Webhooks
- [ ] `GITHUB_WEBHOOK_BRANCH=main`

---

## 8. (Opzionale) Retention dati GDPR

- [ ] `data_retention_days` configurato nelle Impostazioni
- [ ] Cron job configurato: `php /percorso/bin/retention-purge.php`

---

> Completata la checklist, rimuovi o disabilita l'accesso al file `install.php`
> se non prevedi reinstallazioni future.
