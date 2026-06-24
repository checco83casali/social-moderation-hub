# Meta App — Guida completa alla configurazione

Questa guida copre la creazione e configurazione dell'app Meta necessaria per
connettere Social Moderation Hub alle pagine Facebook.

---

## Prerequisiti

- Account Meta Developer (gratuito) → [developers.facebook.com](https://developers.facebook.com)
- Accesso admin alla pagina Facebook da moderare
- Social Moderation Hub installato su un URL HTTPS pubblico

---

## Step 1 — Crea l'app su Meta for Developers

1. Vai su [developers.facebook.com/apps](https://developers.facebook.com/apps)
2. Clicca **Crea app**
3. Scegli il tipo: **Business** (obbligatorio per le API delle pagine)
4. Inserisci:
   - **Nome app**: es. `MyBrand Moderation` (non viene mostrato agli utenti)
   - **Email contatto**: la tua email
   - **Business account**: collega il Business Manager se ce l'hai
5. Clicca **Crea app**

---

## Step 2 — Recupera App ID e App Secret

1. Nella dashboard dell'app, vai su **Impostazioni → Base** (Settings → Basic)
2. Copia:
   - **ID app** → `META_APP_ID` nel `.env`
   - **Chiave segreta app** → `META_APP_SECRET` nel `.env`

```env
META_APP_ID=123456789012345
META_APP_SECRET=abcdef1234567890abcdef1234567890
```

> ⚠️ La chiave segreta non va mai esposta pubblicamente. Tienila solo nel `.env` sul server.

---

## Step 3 — Aggiungi il prodotto Facebook Login

Facebook Login è necessario per connettere le pagine dalla dashboard.

1. Nella barra sinistra, clicca **Aggiungi prodotto** (Add a Product)
2. Trova **Facebook Login** → clicca **Configura**
3. Scegli **Web**
4. In **URI di reindirizzamento OAuth validi** aggiungi:
   ```
   https://moderation.tuodominio.com/auth/meta/callback
   ```
5. Salva le modifiche

### Permessi richiesti per Facebook Login

Nella sezione **Facebook Login → Impostazioni**, assicurati che siano abilitati:

| Permesso | Motivo |
|---|---|
| `pages_show_list` | Elencare le pagine gestite dall'utente |
| `pages_manage_metadata` | Leggere i metadati della pagina e gestire il webhook |
| `pages_read_engagement` | Leggere i commenti |
| `pages_manage_engagement` | Nascondere/eliminare commenti |
| `pages_read_user_content` | Leggere i contenuti degli utenti sulla pagina |

> In **modalità sviluppo** questi permessi funzionano subito per gli utenti con un ruolo nell'app.
> Per la produzione (app in **modalità live**) è richiesta la App Review di Meta.

---

## Step 4 — Aggiungi il prodotto Webhooks

Il webhook riceve in tempo reale i nuovi commenti dalla pagina Facebook.

1. Clicca **Aggiungi prodotto** → trova **Webhooks** → **Configura**
2. Seleziona oggetto: **Pagina** (Page)
3. Clicca **Iscriviti a questo oggetto** (Subscribe to this object)

Compila i campi:

- **URL di callback**: `https://moderation.tuodominio.com/webhook/meta`
- **Token di verifica**: il valore di `META_WEBHOOK_VERIFY_TOKEN` dal tuo `.env`

```env
META_WEBHOOK_VERIFY_TOKEN=valore_casuale_che_hai_scelto
```

> Il token di verifica è un valore **scelto da te** e messo sia nel `.env` che nel
> pannello Meta — non è l'App Secret. Tienili distinti.

4. Sottoscrivi i campi:
   - ✅ `feed`
   - ✅ `comments`

5. Clicca **Verifica e salva** — se la verifica ha successo appare un segno verde.

---

## Step 5 — (Opzionale) Facebook Login for Business

Se vuoi usare il flusso semplificato "Aggiungi pagine" dalla dashboard:

1. Vai su **Prodotti → Facebook Login for Business**
2. Crea una **Configurazione salvata** con i permessi elencati nello Step 3
3. Copia l'**ID configurazione** → `.env`:

```env
META_FB_LOGIN_CONFIG_ID=123456789
```

---

## Step 6 — Connetti la pagina dalla dashboard

1. Apri `https://moderation.tuodominio.com/dashboard.html`
2. Vai su **Pagine Facebook → + Connetti pagina**
3. Segui il flusso Facebook Login
4. Seleziona la pagina da moderare
5. Il webhook viene sottoscritto automaticamente

---

## Step 7 — Verifica il funzionamento

Pubblica un commento di test sulla pagina Facebook. Entro pochi secondi
deve apparire nella **Coda revisione** della dashboard.

Puoi anche inviare un evento di test dalla dashboard Meta:
**Webhooks → Pagina → Test** → seleziona `feed` → **Invia test**

---

## Step 8 — Vai in modalità Live (produzione)

In modalità sviluppo solo gli utenti con un ruolo nell'app possono usare i permessi.
Per andare in produzione:

1. Vai su **App Review → Permessi e funzionalità**
2. Richiedi la revisione per i permessi sopra elencati
3. Una volta approvati, cambia l'interruttore **Modalità sviluppo → Live**

> Per le pagine che possiedi e di cui sei admin non serve la App Review —
> la modalità Live è sufficiente per iniziare.

---

## Troubleshooting

| Problema | Soluzione |
|---|---|
| Verifica webhook fallisce | Controlla che `META_WEBHOOK_VERIFY_TOKEN` nel `.env` corrisponda esattamente a quello inserito in Meta |
| Commenti non arrivano | Verifica che la pagina abbia il webhook attivo: colonna `webhook_verified = 1` in `connected_pages` |
| Commenti arrivano ma non vengono moderati | C'è una policy attiva? Controlla `policies` tabella: `is_active = 1` |
| Errori firma (`403`) | `META_APP_SECRET` corretto? Controlla `logs/app.log` |
| `pages_manage_engagement` negato | L'app è in modalità sviluppo e l'utente non ha un ruolo nell'app |

---

## Sicurezza webhook

Ogni POST in ingresso viene validato con firma HMAC-SHA256 (`X-Hub-Signature-256`),
confrontata in tempo costante. Richieste senza firma o con firma errata vengono
rifiutate con HTTP 403 prima di qualsiasi elaborazione.

> `/webhook/meta` è l'unico endpoint che deve essere raggiungibile dall'internet
> pubblico. Tutto il resto (dashboard, `/api`, login, connessione pagine) va
> ristretto a IP interni via firewall. Vedi [deployment-security.md](deployment-security.md).
